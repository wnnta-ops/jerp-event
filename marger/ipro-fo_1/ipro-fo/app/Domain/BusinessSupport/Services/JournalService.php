<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsJournalStatusLog;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inti General Ledger: posting jurnal double-entry (debit = kredit), penomoran,
 * dan pengelolaan periode fiskal.
 */
final class JournalService
{
    public function nextNumber(string $source, Carbon $date): string
    {
        $code = match ($source) {
            'saldo_awal' => 'JSA',
            'ar'         => 'JAR',
            'ap'         => 'JAP',
            'cash'       => 'JKB',
            'inventory'  => 'JINV',
            default      => 'JM', // memorial
        };

        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next($code, $date, [], fn () => $this->nextNumberLama($source, $date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $source, Carbon $date): string
    {
        $prefix = match ($source) {
            'saldo_awal' => 'JSA',
            'ar'         => 'JAR',
            'ap'         => 'JAP',
            'cash'       => 'JKB',
            'inventory'  => 'JINV',
            default      => 'JM', // memorial
        };
        $ym  = $date->format('y').$date->format('m');
        $seq = BsJournal::withTrashed()->whereYear('journal_date', $date->year)->count() + 1;

        // Loop sampai nomor bebas — tahan terhadap penghapusan/penomoran tak berurutan.
        do {
            $number = sprintf('%s/%s/%04d', $prefix, $ym, $seq);
            $seq++;
        } while (BsJournal::withTrashed()->where('journal_number', $number)->exists());

        return $number;
    }

    /** Pastikan baris periode fiskal ada (status open bila baru). */
    public function ensurePeriod(int $year, int $month): BsFiscalPeriod
    {
        return BsFiscalPeriod::firstOrCreate(
            ['tenant_id' => tenant_id() ?? default_tenant_id(), 'year' => $year, 'month' => $month],
            ['status' => 'open'],
        );
    }

    /**
     * Post jurnal. $lines = [['account_id'=>, 'debit'=>, 'credit'=>, 'description'=>, 'cost_center_id'=>], ...]
     *
     * @param array{journal_date:string, source?:string, description:string, reference?:?string, cost_center_id?:?int, created_by?:?int} $header
     * @param array<int,array<string,mixed>> $lines
     */
    public function post(array $header, array $lines): BsJournal
    {
        $clean = [];
        $totalD = 0.0;
        $totalK = 0.0;
        foreach ($lines as $l) {
            $d = round((float) ($l['debit'] ?? 0), 2);
            $k = round((float) ($l['credit'] ?? 0), 2);
            if ($d <= 0 && $k <= 0) {
                continue; // baris kosong
            }
            $totalD += $d;
            $totalK += $k;
            $clean[] = [
                'account_id'     => (int) $l['account_id'],
                'cost_center_id' => $l['cost_center_id'] ?? null,
                'department_id'  => $l['department_id'] ?? null,
                'ledger_id'      => $l['ledger_id'] ?? null,
                'location_id'    => $l['location_id'] ?? null,
                'currency'       => $l['currency'] ?? 'IDR',
                'kurs'           => $l['kurs'] ?? 1,
                'description'    => $l['description'] ?? null,
                'debit'          => $d,
                'credit'         => $k,
            ];
        }

        if (count($clean) < 2) {
            throw new BusinessRuleException('Jurnal minimal 2 baris (debit & kredit).', errorCode: 'JOURNAL_TOO_FEW_LINES');
        }
        if (round($totalD, 2) !== round($totalK, 2)) {
            throw new BusinessRuleException(
                'Jurnal tidak balance: total debit ('.number_format($totalD, 2).') ≠ total kredit ('.number_format($totalK, 2).').',
                errorCode: 'JOURNAL_NOT_BALANCED',
            );
        }
        if (round($totalD, 2) <= 0) {
            throw new BusinessRuleException('Nilai jurnal harus lebih dari 0.', errorCode: 'JOURNAL_ZERO');
        }

        $date   = Carbon::parse($header['journal_date']);
        $source = $header['source'] ?? 'memorial';
        $period = $this->ensurePeriod($date->year, $date->month);

        if ($period->status === 'closed') {
            throw new BusinessRuleException("Periode {$date->year}/{$date->month} sudah CLOSED — tidak bisa menambah jurnal.", errorCode: 'PERIOD_CLOSED');
        }

        // Status default posting otomatis modul lain = config (default 'final' → antre approval, seperti legacy).
        // Pemanggil yang menetapkan status eksplisit (mis. 'draft' untuk memorial) tetap dihormati.
        $autoDefault = config('business_support.journal.auto_status', 'final');
        $requested   = $header['status'] ?? $autoDefault;
        $status = in_array($requested, ['draft', 'final', 'posted'], true) ? $requested : $autoDefault;

        return DB::transaction(function () use ($header, $clean, $totalD, $totalK, $date, $source, $status): BsJournal {
            $journal = BsJournal::create([
                'cost_center_id' => $header['cost_center_id'] ?? null,
                'journal_number' => $this->nextNumber($source, $date),
                'journal_date'   => $date->toDateString(),
                'period_year'    => $date->year,
                'period_month'   => $date->month,
                'source'         => $source,
                'reference'      => $header['reference'] ?? null,
                'description'    => $header['description'],
                'status'         => $status,
                'total_debit'    => $totalD,
                'total_credit'   => $totalK,
                'created_by'     => $header['created_by'] ?? auth()->id(),
                'posted_at'      => $status === 'posted' ? now() : null,
                'finalized_at'   => $status === 'final' ? now() : null,
            ]);
            foreach ($clean as $c) {
                $journal->lines()->create($c);
            }
            $this->logStatus($journal, 'created', null, $status, $header['created_by'] ?? null);

            return $journal;
        });
    }

    /**
     * #54 — Edit jurnal: ganti header + baris, tetap balance. Nomor/source/status dipertahankan.
     * Diblokir bila periode lama atau baru sudah CLOSED.
     *
     * @param array{journal_date:string, reference?:?string, description:string, cost_center_id?:?int} $header
     * @param array<int,array<string,mixed>> $lines
     */
    public function update(BsJournal $journal, array $header, array $lines): BsJournal
    {
        $clean = [];
        $totalD = 0.0;
        $totalK = 0.0;
        foreach ($lines as $l) {
            $d = round((float) ($l['debit'] ?? 0), 2);
            $k = round((float) ($l['credit'] ?? 0), 2);
            if ($d <= 0 && $k <= 0) {
                continue;
            }
            $totalD += $d;
            $totalK += $k;
            $clean[] = [
                'account_id'     => (int) $l['account_id'],
                'cost_center_id' => $l['cost_center_id'] ?? null,
                'department_id'  => $l['department_id'] ?? null,
                'ledger_id'      => $l['ledger_id'] ?? null,
                'location_id'    => $l['location_id'] ?? null,
                'currency'       => $l['currency'] ?? 'IDR',
                'kurs'           => $l['kurs'] ?? 1,
                'description'    => $l['description'] ?? null,
                'debit'          => $d,
                'credit'         => $k,
            ];
        }

        if (count($clean) < 2) {
            throw new BusinessRuleException('Jurnal minimal 2 baris (debit & kredit).', errorCode: 'JOURNAL_TOO_FEW_LINES');
        }
        if (round($totalD, 2) !== round($totalK, 2)) {
            throw new BusinessRuleException('Jurnal tidak balance: total debit ('.number_format($totalD, 2).') ≠ total kredit ('.number_format($totalK, 2).').', errorCode: 'JOURNAL_NOT_BALANCED');
        }
        if (round($totalD, 2) <= 0) {
            throw new BusinessRuleException('Nilai jurnal harus lebih dari 0.', errorCode: 'JOURNAL_ZERO');
        }

        $this->assertPeriodOpen($journal); // periode lama
        $date = Carbon::parse($header['journal_date']);
        $newPeriod = $this->ensurePeriod($date->year, $date->month);
        if ($newPeriod->status === 'closed') {
            throw new BusinessRuleException("Periode {$date->year}/{$date->month} sudah CLOSED — tidak bisa edit.", errorCode: 'PERIOD_CLOSED');
        }

        return DB::transaction(function () use ($journal, $header, $clean, $totalD, $totalK, $date): BsJournal {
            $journal->lines()->delete();
            $journal->update([
                'journal_date'   => $date->toDateString(),
                'period_year'    => $date->year,
                'period_month'   => $date->month,
                'reference'      => $header['reference'] ?? $journal->reference,
                'description'    => $header['description'],
                'cost_center_id' => $header['cost_center_id'] ?? null,
                'total_debit'    => $totalD,
                'total_credit'   => $totalK,
            ]);
            foreach ($clean as $c) {
                $journal->lines()->create($c);
            }

            return $journal->refresh();
        });
    }

    /**
     * Baru → Final.
     * Finalisasi = penegasan bahwa jurnal SUDAH BALANCE (total debit = total kredit).
     * Berbeda dari Approve: finalisasi murni pengecekan keseimbangan, bukan verifikasi berwenang.
     */
    public function finalize(BsJournal $journal): BsJournal
    {
        $this->assertPeriodOpen($journal);
        if ($journal->status !== 'draft') {
            throw new BusinessRuleException('Hanya jurnal berstatus Baru yang bisa difinalisasi.', errorCode: 'JOURNAL_NOT_DRAFT');
        }

        // Syarat finalisasi: jurnal harus balance (debit = kredit) dan bernilai > 0.
        $totalD = round((float) $journal->lines()->sum('debit'), 2);
        $totalK = round((float) $journal->lines()->sum('credit'), 2);
        if ($totalD <= 0.0) {
            throw new BusinessRuleException('Nilai jurnal harus lebih dari 0 untuk difinalisasi.', errorCode: 'JOURNAL_ZERO');
        }
        if ($totalD !== $totalK) {
            throw new BusinessRuleException(
                'Jurnal belum balance: total debit ('.number_format($totalD, 2).') ≠ total kredit ('.number_format($totalK, 2).') — tidak bisa difinalisasi.',
                errorCode: 'JOURNAL_NOT_BALANCED',
            );
        }

        $journal->update(['status' => 'final', 'finalized_at' => now(), 'total_debit' => $totalD, 'total_credit' => $totalK]);
        $this->logStatus($journal, 'finalized', 'draft', 'final');

        return $journal;
    }

    /**
     * Baris jurnal yang WAJIB bercost center tetapi kosong — pengganjal Final → Approved.
     *
     * Cost center efektif tiap baris = cost center barisnya, jatuh ke cost center header bila
     * kosong. Aturan warisan itu sama persis dengan yang dipakai saat jurnalnya disimpan
     * (JournalController::withLineCostCenter) — kalau berbeda, gerbang ini akan menolak jurnal
     * yang sebenarnya sudah lengkap.
     *
     * PENGECUALIANNYA DIPERTAHANKAN: akun bertanda cost_center_optional (71 dari 267 akun
     * postable, lihat CoaTanpaCostCenterSeeder) memang tidak menanggung cost center — Utang
     * Usaha Supplier dan sebagian akun neraca lain. Mewajibkannya untuk SEMUA baris akan
     * mengunci approval pada jurnal yang justru paling biasa: Dr beban (ber-cost center) /
     * Cr Utang Usaha (tanpa).
     *
     * @return array<int,string>  "kode - nama akun" yang barisnya masih kosong
     */
    public function barisTanpaCostCenter(BsJournal $journal): array
    {
        /* Jurnal migrasi data awal dibebaskan seluruhnya — lihat BsJournal::SUMBER_MIGRASI.
           Ia memindahkan saldo yang sudah terjadi di sistem lama, bukan mencatat belanja baru,
           jadi tidak ada anggaran yang bisa direalisasikannya. */
        if ($journal->migrasiDataAwal()) {
            return [];
        }

        $headerCc = $journal->cost_center_id ? (int) $journal->cost_center_id : null;

        $kurang = [];
        foreach ($journal->lines()->get(['id', 'account_id', 'cost_center_id']) as $l) {
            $cc = $l->cost_center_id ? (int) $l->cost_center_id : null;
            if ($cc === null && $headerCc !== null) {
                $cc = $headerCc;    // baris kosong mewarisi header, sama seperti saat disimpan
            }
            if ($cc === null) {
                $kurang[] = (int) $l->account_id;
            }
        }
        $kurang = array_values(array_unique(array_filter($kurang)));
        if (! $kurang) {
            return [];
        }

        // Hanya akun yang MEMANG wajib yang dilaporkan. Dikecualikan, SAMA dengan wizard invoice AP
        // (Payable/Form.vue · ccOptional): akun bertanda optional DAN akun yang tidak dianggarkan
        // (daftar Accounting). Dulu gerbang ini hanya mengenal yang pertama — invoice bisa dibuat
        // tanpa cost center di baris mis. Beban Masih Harus Dibayar, lalu jurnalnya tidak pernah
        // bisa di-approve.
        $tanpaAnggaran = array_map('strval', (array) config('business_support.budget.coa_tanpa_anggaran', []));

        return BsAccount::whereIn('id', $kurang)
            ->where(fn ($q) => $q->where('cost_center_optional', false)->orWhereNull('cost_center_optional'))
            ->when($tanpaAnggaran, fn ($q) => $q->whereNotIn('code', $tanpaAnggaran))
            ->orderBy('code')->get(['code', 'name'])
            ->map(fn ($a) => $a->code.' - '.$a->name)->all();
    }

    /**
     * Final → Approved (efektif di GL).
     *
     * COST CENTER WAJIB LENGKAP LEBIH DULU. Approved berarti jurnalnya efektif di buku besar,
     * dan sejak saat itu angkanya ikut terbaca sebagai realisasi. Baris tanpa cost center tetap
     * menjadi beban di GL tetapi TIDAK PERNAH muncul sebagai realisasi di Budget Control —
     * anggarannya seolah tidak pernah terpakai. Itu tidak menimbulkan galat apa pun: hanya
     * angka yang kurang, dan biasanya baru ketahuan berbulan kemudian saat anggaran
     * direkonsiliasi. Karena itu ditahan di sini, di gerbang terakhir sebelum efektif.
     */
    public function approve(BsJournal $journal, ?int $userId = null): BsJournal
    {
        $this->assertPeriodOpen($journal);
        if ($journal->status !== 'final') {
            throw new BusinessRuleException('Hanya jurnal berstatus Final yang bisa di-approve.', errorCode: 'JOURNAL_NOT_FINAL');
        }

        $kurangCc = $this->barisTanpaCostCenter($journal);
        if ($kurangCc !== []) {
            throw new BusinessRuleException(
                'Cost center belum lengkap — jurnal '.$journal->journal_number.' tidak bisa di-approve. '
                .'Lengkapi cost center pada akun: '.implode(', ', $kurangCc).'. '
                .'Buka Edit Jurnal untuk mengisinya, lalu approve ulang.',
                errorCode: 'JOURNAL_NO_COST_CENTER',
            );
        }

        $journal->update(['status' => 'posted', 'posted_at' => now(), 'approved_by' => $userId ?? auth()->id()]);
        $this->logStatus($journal, 'approved', 'final', 'posted', $userId);

        return $journal;
    }

    /** Approved → Final (batalkan approval saja; jurnal keluar dari GL efektif tapi tetap Final). */
    public function unapprove(BsJournal $journal): BsJournal
    {
        $this->assertPeriodOpen($journal);
        if ($journal->status !== 'posted') {
            throw new BusinessRuleException('Hanya jurnal berstatus Approved yang bisa dibatalkan approval-nya.', errorCode: 'JOURNAL_NOT_POSTED');
        }
        $journal->update(['status' => 'final', 'posted_at' => null, 'approved_by' => null]);
        $this->logStatus($journal, 'unapproved', 'posted', 'final');

        return $journal;
    }

    /**
     * Final → Baru (batal finalisasi). SATU LANGKAH SAJA.
     *
     * Jurnal Approved TIDAK boleh langsung kembali ke Baru: statusnya harus dibatalkan
     * approval-nya lebih dulu (unapprove: Approved → Final), baru finalisasinya dibatalkan
     * (reject: Final → Baru). Melompati dua tahap sekaligus menghapus jejak persetujuan
     * tanpa ada satu pun langkah yang mencatat bahwa persetujuan itu pernah dicabut —
     * dan jurnal yang sudah efektif di buku besar bisa berubah jadi draft yang bebas
     * disunting hanya dengan satu klik.
     */
    public function reject(BsJournal $journal): BsJournal
    {
        $this->assertPeriodOpen($journal);
        if ($journal->status === 'posted') {
            throw new BusinessRuleException(
                'Jurnal Approved tidak bisa langsung dikembalikan ke Baru. Batalkan approval-nya dulu (Approved → Final), baru finalisasinya dibatalkan.',
                errorCode: 'JOURNAL_SKIP_STEP',
            );
        }
        if ($journal->status !== 'final') {
            throw new BusinessRuleException('Hanya jurnal Final yang bisa dikembalikan ke Baru.', errorCode: 'JOURNAL_NOT_REVERSIBLE');
        }
        $from = (string) $journal->status;
        $journal->update(['status' => 'draft', 'finalized_at' => null, 'posted_at' => null, 'approved_by' => null]);
        $this->logStatus($journal, 'rejected', $from, 'draft');

        return $journal;
    }

    /**
     * Hapus jurnal dari menu Jurnal. HANYA status Baru — jurnal Final/Approved harus dimundurkan
     * dulu lewat Batalkan Approval → Batalkan Finalisasi, supaya pencabutan persetujuannya
     * tercatat sebagai langkah tersendiri. Jurnal milik dokumen ditolak: hapus lewat dokumennya.
     */
    public function hapus(BsJournal $journal, string $alasan, ?int $userId = null): void
    {
        if ($journal->status !== 'draft') {
            throw new BusinessRuleException(
                'Hanya jurnal berstatus Baru yang bisa dihapus. Mundurkan dulu: Batalkan Approval → Batalkan Finalisasi.',
                errorCode: 'JOURNAL_NOT_DRAFT',
            );
        }
        if ($pemilik = (app(PemilikJurnal::class)->untuk([(int) $journal->id])[(int) $journal->id] ?? null)) {
            throw new BusinessRuleException(
                "Jurnal ini milik dokumen {$pemilik}".($journal->reference ? " ({$journal->reference})" : '').' — hapus/batalkan lewat dokumennya.',
                errorCode: 'JOURNAL_OWNED',
            );
        }

        $this->buang($journal, $alasan, $userId);
    }

    /**
     * Buang jurnal milik dokumen, dipanggil oleh dokumen itu sendiri saat langkahnya dimundurkan
     * (mis. Batal Realisasi Bon). Statusnya diturunkan ke Baru lebih dulu supaya laporan yang
     * menyaring status Approved tidak lagi menghitungnya, lalu barisnya dihapus dan jurnalnya
     * di-soft-delete. Jejaknya tetap di log status.
     */
    public function buang(BsJournal $journal, string $alasan, ?int $userId = null): void
    {
        $this->assertPeriodOpen($journal);

        DB::transaction(function () use ($journal, $alasan, $userId): void {
            $dari = (string) $journal->status;
            if ($dari !== 'draft') {
                $journal->update(['status' => 'draft', 'finalized_at' => null, 'posted_at' => null, 'approved_by' => null]);
            }
            $this->logStatus($journal, 'deleted', $dari, null, $userId, mb_substr($alasan, 0, 255));
            $journal->lines()->delete();
            $journal->delete();
        });
    }

    /**
     * Catat satu baris log status jurnal (append-only). Tidak pernah dihapus/di-update, sehingga
     * riwayat dibuat → finalisasi → approve → batal approve → reject tetap utuh hingga closed.
     */
    private function logStatus(BsJournal $journal, string $action, ?string $from, ?string $to, ?int $userId = null, ?string $note = null): void
    {
        BsJournalStatusLog::create([
            'tenant_id'   => $journal->tenant_id,
            'journal_id'  => $journal->id,
            'action'      => $action,
            'from_status' => $from,
            'to_status'   => $to,
            'user_id'     => $userId ?? auth()->id(),
            'note'        => $note,
            'created_at'  => now(),
        ]);
    }

    private function assertPeriodOpen(BsJournal $journal): void
    {
        $period = BsFiscalPeriod::where('tenant_id', $journal->tenant_id)
            ->where('year', $journal->period_year)->where('month', $journal->period_month)->first();
        if ($period && $period->status === 'closed') {
            throw new BusinessRuleException("Periode {$journal->period_year}/{$journal->period_month} sudah CLOSED.", errorCode: 'PERIOD_CLOSED');
        }
    }
}
