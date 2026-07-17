<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApKuitansi;
use App\Domain\BusinessSupport\Models\BsApMemorial;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsArKuitansi;
use App\Domain\BusinessSupport\Models\BsArMemorial;
use App\Domain\BusinessSupport\Models\BsBookedRevenue;
use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsCashTransaction;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry;
use App\Domain\BusinessSupport\Models\BsGiro;
use App\Domain\BusinessSupport\Models\BsOutgoingCheque;
use App\Domain\BusinessSupport\Models\BsPayrollRun;
use App\Domain\BusinessSupport\Models\BsPurchaseOrder;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\Master\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Pihak Terkait per jurnal — nama supplier / employee / customer di balik sebuah jurnal.
 *
 * KENAPA RESOLVER, BUKAN KOLOM: bs_journals TIDAK punya kolom pihak sama sekali (tidak ada
 * supplier_id/employee_id/customer_id maupun party polymorphic). Kaitannya justru TERBALIK —
 * belasan tabel dokumen menyimpan journal_id yang menunjuk ke bs_journals.id. Resolver ini
 * menempuh arah balik itu secara BATCH: satu query per tabel sumber (whereIn journal_id),
 * bukan N+1 per jurnal.
 *
 * `source` TIDAK bisa dipakai menentukan jenis pihak: 'memorial' bisa supplier (SPK), customer
 * (booked revenue), atau tanpa pihak (penyusutan/COGS/jurnal manual); 'cash' bisa supplier,
 * customer, karyawan, atau teks bebas. Karena itu pemetaan dilakukan per TABEL DOKUMEN.
 *
 * Urutan prioritas (yang ketemu duluan menang):
 *   1. Nama dari master (FK) — otoritatif: bs_suppliers.name / customers.full_name / bs_employees.name
 *   2. Teks bebas dokumen (payee/drawer/holder) — apa adanya yang diketik operator, karena
 *      tabel-tabel itu memang tidak punya FK ke master. Tanpa ini mayoritas jurnal kas kosong.
 *
 * Jurnal yang MEMANG tanpa pihak (penyusutan aset, COGS, tutup buku, import, memorial manual)
 * sengaja dikembalikan kosong — tidak dikarang.
 *
 * Query lewat Eloquent agar global scope tenant + soft delete ikut berlaku. Untuk tabel yang
 * modelnya tanpa HasTenant dipakai DB::table, aman karena journal_id-nya sudah berasal dari
 * query jurnal yang ber-scope tenant.
 */
final class JournalPartyResolver
{
    /**
     * Batas id per putaran. Sebuah tabel bisa punya 3 kolom jurnal (mis. bs_outgoing_cheques:
     * handover/clear/void) → 3 × CHUNK placeholder dalam satu statement. 5.000 menjaganya jauh
     * di bawah batas ~65.535 placeholder MySQL, walau user menarik rentang tanggal bertahun-tahun.
     */
    private const CHUNK = 5000;

    /**
     * @param  array<int>  $journalIds
     * @return array<int, string>  journal_id => nama pihak (hanya yang ketemu)
     */
    public function forJournalIds(array $journalIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $journalIds))));
        if ($ids === []) {
            return [];
        }
        if (count($ids) > self::CHUNK) {
            $out = [];
            foreach (array_chunk($ids, self::CHUNK) as $part) {
                $out += $this->resolveChunk($part);
            }

            return $out;
        }

        return $this->resolveChunk($ids);
    }

    /** @return array<int, string> */
    private function resolveChunk(array $ids): array
    {
        $out = [];
        // Hanya isi bila belum terisi → menegakkan urutan prioritas di atas.
        $fill = function (array $pairs) use (&$out): void {
            foreach ($pairs as $journalId => $name) {
                $name = trim((string) $name);
                if ($name !== '' && ! isset($out[$journalId])) {
                    $out[$journalId] = $name;
                }
            }
        };

        foreach ($this->supplierMaps($ids) as $m) { $fill($m); }
        foreach ($this->customerMaps($ids) as $m) { $fill($m); }
        $fill($this->employeeMap($ids));
        foreach ($this->freeTextMaps($ids) as $m) { $fill($m); }

        return $out;
    }

    /** Satu jurnal saja — pembungkus tipis di atas forJournalIds(). */
    public function forJournalId(?int $journalId): ?string
    {
        return $journalId ? ($this->forJournalIds([$journalId])[$journalId] ?? null) : null;
    }

    /** @return array<array<int, string>> */
    private function supplierMaps(array $ids): array
    {
        $names = fn (array $sids) => BsSupplier::whereIn('id', array_filter($sids))->pluck('name', 'id')->all();

        $maps = [
            $this->mapVia(BsApInvoice::class, ['journal_id'], 'supplier_id', $ids, $names),
            $this->mapVia(BsApKuitansi::class, ['journal_id', 'void_journal_id'], 'supplier_id', $ids, $names),
            $this->mapVia(BsApMemorial::class, ['journal_id'], 'supplier_id', $ids, $names),
            $this->mapVia(BsPurchaseOrder::class, ['dp_journal_id'], 'supplier_id', $ids, $names),
            // SPK/kontraktor: bs_work_orders TIDAK punya journal_id (sudah diverifikasi ke skema) —
            // satu-satunya kaitan adalah reference = spk_number, aman karena spk_number UNIQUE.
            $this->mapViaReference(BsWorkOrder::class, 'spk_number', 'supplier_id', $ids, $names),
        ];

        // AP Payment: 2 hop (payment → invoice → supplier).
        $maps[] = $this->mapTwoHop('bs_ap_payments', 'ap_invoice_id', BsApInvoice::class, 'supplier_id', $ids, $names);

        return $maps;
    }

    /** @return array<array<int, string>> */
    private function customerMaps(array $ids): array
    {
        // full_name = kolom asli (varchar). Select eksplisit: customers.id_number/npwp terenkripsi.
        $names = fn (array $cids) => Customer::whereIn('id', array_filter($cids))->pluck('full_name', 'id')->all();

        $maps = [
            $this->mapVia(BsArInvoice::class, ['journal_id'], 'customer_id', $ids, $names),
            $this->mapVia(BsArKuitansi::class, ['journal_id', 'void_journal_id'], 'customer_id', $ids, $names),
            $this->mapVia(BsArMemorial::class, ['journal_id'], 'customer_id', $ids, $names),
            $this->mapVia(BsBookedRevenue::class, ['booking_journal_id', 'recognition_journal_id', 'cancel_journal_id'], 'customer_id', $ids, $names),
            $this->mapVia(BsFakturKeluaranEntry::class, ['journal_id', 'convert_journal_id'], 'customer_id', $ids, $names),
            // TTS (UTJ/DP) — customer_id nullable (ada TTS "tanpa unit"), yang null dilewati.
            $this->mapViaTable('tts_receipts', ['journal_id'], 'customer_id', $ids, $names),
            // AR Payment: 2 hop (payment → invoice → customer).
            $this->mapTwoHop('bs_ar_payments', 'ar_invoice_id', BsArInvoice::class, 'customer_id', $ids, $names),
        ];

        // KPR Realisasi: realization → application → customer.
        $maps[] = $this->kprMap(
            DB::table('kpr_realizations')->whereIn('journal_id', $ids)->pluck('kpr_application_id', 'journal_id')->all(),
            $names,
        );

        // KPR Retensi: retention → realization → application → customer.
        $ret = DB::table('kpr_retentions')->whereIn('journal_id', $ids)->get(['journal_id', 'kpr_realization_id']);
        if ($ret->isNotEmpty()) {
            $r2a = DB::table('kpr_realizations')->whereIn('id', $ret->pluck('kpr_realization_id')->filter()->unique()->all())
                ->pluck('kpr_application_id', 'id');
            $byJournal = [];
            foreach ($ret as $r) {
                if ($appId = $r2a[$r->kpr_realization_id] ?? null) {
                    $byJournal[(int) $r->journal_id] = $appId;
                }
            }
            $maps[] = $this->kprMap($byJournal, $names);
        }

        return $maps;
    }

    /** @param array<int,int> $journalToApplication */
    private function kprMap(array $journalToApplication, callable $names): array
    {
        $journalToApplication = array_filter($journalToApplication);
        if ($journalToApplication === []) {
            return [];
        }
        $app = DB::table('kpr_applications')->whereIn('id', array_values(array_unique($journalToApplication)))
            ->pluck('customer_id', 'id');
        $lookup = $names($app->values()->all());

        $m = [];
        foreach ($journalToApplication as $journalId => $appId) {
            $cid = $app[$appId] ?? null;
            if ($cid !== null && isset($lookup[$cid])) {
                $m[(int) $journalId] = $lookup[$cid];
            }
        }

        return $m;
    }

    /**
     * Payroll: 1 jurnal run = BANYAK karyawan. Menyebut satu nama saja akan menyesatkan,
     * jadi bila >1 ditulis jumlahnya.
     */
    private function employeeMap(array $ids): array
    {
        $runs = BsPayrollRun::whereIn('journal_id', $ids)->get(['id', 'journal_id']);
        if ($runs->isEmpty()) {
            return [];
        }
        $lines = DB::table('bs_payroll_lines')->whereIn('payroll_run_id', $runs->pluck('id')->all())
            ->get(['payroll_run_id', 'employee_id', 'employee_name']);
        $emp = BsEmployee::whereIn('id', $lines->pluck('employee_id')->filter()->unique()->all())->pluck('name', 'id');

        $m = [];
        foreach ($runs as $run) {
            $mine = $lines->where('payroll_run_id', $run->id);
            if ($mine->isEmpty()) {
                continue;
            }
            // Nama master diutamakan; employee_name hanya salinan saat run dibuat.
            $m[(int) $run->journal_id] = $mine->count() === 1
                ? (string) ($emp[$mine->first()->employee_id] ?? $mine->first()->employee_name ?? '')
                : $mine->count().' karyawan';
        }

        return $m;
    }

    /**
     * Teks bebas — tabel ini TIDAK punya FK ke master, hanya nama yang diketik operator.
     * Dipakai paling akhir supaya nama master selalu menang.
     *
     * @return array<array<int, string>>
     */
    private function freeTextMaps(array $ids): array
    {
        $asis = fn (array $v) => array_combine($v, $v) ?: [];

        return [
            $this->mapVia(BsCashTransaction::class, ['journal_id'], 'payee', $ids, $asis),
            $this->mapVia(BsCashAdvance::class, ['journal_id', 'realize_journal_id', 'settle_journal_id'], 'holder', $ids, $asis),
            $this->mapVia(BsGiro::class, ['receive_journal_id', 'settle_journal_id'], 'drawer', $ids, $asis),
            $this->mapVia(BsOutgoingCheque::class, ['handover_journal_id', 'clear_journal_id', 'void_journal_id'], 'payee', $ids, $asis),
        ];
    }

    /**
     * Peta journal_id => nama pihak lewat model Eloquent (scope tenant + soft delete ikut).
     *
     * @param  array<string>  $journalCols  kolom-kolom penunjuk bs_journals.id pada tabel dokumen
     * @param  callable(array): array  $names  daftar id pihak → [id => nama]
     */
    private function mapVia(string $model, array $journalCols, string $partyCol, array $ids, callable $names): array
    {
        $rows = $model::query()
            ->where(function ($q) use ($journalCols, $ids) {
                foreach ($journalCols as $c) {
                    $q->orWhereIn($c, $ids);
                }
            })
            ->get(array_merge($journalCols, [$partyCol]));

        return $this->pair($rows, $journalCols, $partyCol, $ids, $names);
    }

    /** Sama dengan mapVia() tapi untuk tabel tanpa model Eloquent. */
    private function mapViaTable(string $table, array $journalCols, string $partyCol, array $ids, callable $names): array
    {
        $q = DB::table($table)->where(function ($w) use ($journalCols, $ids) {
            foreach ($journalCols as $c) {
                $w->orWhereIn($c, $ids);
            }
        });
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return $this->pair($q->get(array_merge($journalCols, [$partyCol])), $journalCols, $partyCol, $ids, $names);
    }

    /** Dokumen → dokumen induk → pihak (mis. AP payment → AP invoice → supplier). */
    private function mapTwoHop(string $table, string $parentCol, string $parentModel, string $partyCol, array $ids, callable $names): array
    {
        $rows = DB::table($table)->whereIn('journal_id', $ids)->get(['journal_id', $parentCol]);
        if ($rows->isEmpty()) {
            return [];
        }
        $parent = $parentModel::whereIn('id', $rows->pluck($parentCol)->filter()->unique()->all())->pluck($partyCol, 'id');
        $lookup = $names($parent->values()->all());

        $m = [];
        foreach ($rows as $r) {
            $pv = $parent[$r->{$parentCol}] ?? null;
            if ($pv !== null && isset($lookup[$pv])) {
                $m[(int) $r->journal_id] = $lookup[$pv];
            }
        }

        return $m;
    }

    /** Peta journal_id => nama pihak lewat pencocokan bs_journals.reference = nomor dokumen. */
    private function mapViaReference(string $model, string $numberCol, string $partyCol, array $ids, callable $names): array
    {
        $refs = DB::table('bs_journals')->whereIn('id', $ids)->whereNotNull('reference')->pluck('reference', 'id');
        if ($refs->isEmpty()) {
            return [];
        }
        $docs = $model::whereIn($numberCol, $refs->unique()->values()->all())->get(['id', $numberCol, $partyCol]);
        if ($docs->isEmpty()) {
            return [];
        }
        $lookup = $names($docs->pluck($partyCol)->filter()->unique()->all());
        $byNumber = $docs->keyBy($numberCol);

        $m = [];
        foreach ($refs as $journalId => $ref) {
            $pv = $byNumber[$ref]?->{$partyCol} ?? null;
            if ($pv !== null && isset($lookup[$pv])) {
                $m[(int) $journalId] = $lookup[$pv];
            }
        }

        return $m;
    }

    /** Ubah baris dokumen menjadi peta journal_id => nama pihak. */
    private function pair(iterable $rows, array $journalCols, string $partyCol, array $ids, callable $names): array
    {
        $rows = collect($rows);
        if ($rows->isEmpty()) {
            return [];
        }
        $lookup = $names($rows->pluck($partyCol)->filter()->unique()->values()->all());

        $m = [];
        foreach ($rows as $r) {
            $pv = is_array($r) ? ($r[$partyCol] ?? null) : ($r->{$partyCol} ?? null);
            if ($pv === null || $pv === '' || ! isset($lookup[$pv])) {
                continue;
            }
            foreach ($journalCols as $c) {
                $jid = is_array($r) ? ($r[$c] ?? null) : ($r->{$c} ?? null);
                // Satu dokumen bisa punya beberapa kolom jurnal (mis. terima & void) — hanya
                // yang termasuk himpunan yang diminta yang dipetakan.
                if ($jid !== null && in_array((int) $jid, $ids, true)) {
                    $m[(int) $jid] = $lookup[$pv];
                }
            }
        }

        return $m;
    }
}
