<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Services;

use App\Modules\FO\FinanceAR\Models\Refund;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Posting FO → ANTRIAN Posting Invoice BO (ipro_bo.bs_ap_refund_queue), status 'pending'.
 * BELUM jadi hutang Account Payable: ditampung dulu di halaman Posting Invoice. Yang
 * membedakan hanya SUMBER (kind):
 *   - refund : per jenis (SPR / SPRS / TRANSFER_ANONIM)
 *   - komisi : batch Realisasi Komisi (per No. Pengajuan)
 *   - blt    : batch Realisasi BLT (per No. Pengajuan)
 * BO yang menerbitkan invoice hutang dari antrian (PostingInvoiceController::fromRefund).
 * Idempoten via (tenant_id, kind, refund_no). Id akun BO di-resolve via coa_map (kode COA
 * J-ERP ≠ kode bs_accounts BO).
 */
class IproApPoster
{
    private string $conn;
    private int $tenantId;

    public function __construct()
    {
        $this->conn = (string) config('ipro.connection', 'ipro_bo');
        $this->tenantId = (int) config('ipro.tenant_id', 1);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->conn);
    }

    /** Refund FO → antrian (kind=refund), per jenis sumber. */
    public function queueRefund(Refund $r): ?int
    {
        $amount = round((float) $r->nilai_refund, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->enqueue('refund', (string) $r->no_refund, (int) $r->id, [
            'customer_id'        => $this->iproCustomerIdByFo((int) $r->customer_id),
            'customer_name'      => $r->customer?->full_name,
            'refund_source'      => $this->resolveSumber($r),
            'amount'             => $amount,
            'payable_account_id' => $this->acc('2.1.01.004'), // Hutang Refund / Titipan
            'offset_account_id'  => $this->acc('1.1.02.001'), // lawan: piutang/titipan
            'tgl_refund'         => $r->tgl_refund ? Carbon::parse((string) $r->tgl_refund)->toDateString() : null,
            'keterangan'         => $r->keterangan,
        ]);
    }

    /** Realisasi Komisi (batch per No. Pengajuan) → antrian (kind=komisi). */
    public function queueKomisi(string $noPengajuan, float $amount, ?string $tgl = null, ?string $ket = null, float $pph = 0.0, float $nett = 0.0): ?int
    {
        return $this->queueIncentive('komisi', $noPengajuan, $amount, $tgl, $ket ?: ('Komisi '.$noPengajuan), $pph, $nett);
    }

    /** Realisasi BLT (batch per No. Pengajuan) → antrian (kind=blt). */
    public function queueBlt(string $noPengajuan, float $amount, ?string $tgl = null, ?string $ket = null, float $pph = 0.0, float $nett = 0.0): ?int
    {
        return $this->queueIncentive('blt', $noPengajuan, $amount, $tgl, $ket ?: ('BLT '.$noPengajuan), $pph, $nett);
    }

    /**
     * GMC (gimmick/promo developer, mengikat SPR/customer) → antrian (kind=gmc).
     * $supplierId (bs_suppliers.id) diisi untuk komponen GMC multi-supplier → di
     * Posting Invoice BO dropdown supplier terisi otomatis.
     *
     * Akun jurnalnya diambil dari master Kategori Gimmick BO ($gimmickName = bs_gimmick_categories.name)
     * — boleh banyak akun debit & kredit, masing-masing berporsi. Kategori juga membawa kolom
     * Rincian Biaya Master Data KPR, dan $noKontrak/$foUnitId menautkan biaya ini ke SPR & unitnya,
     * sehingga sesudah invoice terbit di BO rekap Master Data KPR ikut terisi otomatis.
     * Bila kategorinya tidak ketemu, dipakai akun bawaan lama supaya posting tetap jalan.
     */
    public function queueGmc(
        string $noGmc,
        float $amount,
        ?int $foCustomerId = null,
        ?string $custName = null,
        ?string $tgl = null,
        ?string $ket = null,
        ?int $supplierId = null,
        ?string $gimmickName = null,
        ?string $noKontrak = null,
        ?int $foUnitId = null,
    ): ?int {
        $amount = round($amount, 2);
        if ($amount <= 0 || trim($noGmc) === '') {
            return null;
        }

        $cat = $gimmickName ? $this->gimmickCategory($gimmickName) : null;
        $split = $cat ? $this->gimmickSplit((int) $cat->id) : [];
        $firstOf = function (string $side) use ($split): ?int {
            foreach ($split as $s) {
                if ($s['side'] === $side) {
                    return (int) $s['account_id'];
                }
            }

            return null;
        };

        return $this->enqueue('gmc', $noGmc, null, [
            'customer_id'         => $foCustomerId ? $this->iproCustomerIdByFo($foCustomerId) : null,
            'customer_name'       => $custName,
            'refund_source'       => null,
            'amount'              => $amount,
            'supplier_id'         => $supplierId,
            'gimmick_category_id' => $cat?->id,
            'kpr_biaya_key'       => $cat?->kpr_biaya_key,
            'booking_id'          => $noKontrak ? $this->iproBookingIdByNoKontrak($noKontrak) : null,
            'unit_id'             => $foUnitId ? $this->iproUnitIdByFo($foUnitId) : null,
            'account_split'       => $split ? json_encode($split) : null,
            'payable_account_id'  => $firstOf('credit') ?? $this->acc('2.1.01.004'), // Hutang/Titipan
            'offset_account_id'   => $firstOf('debit') ?? $this->acc('5.9.01.001'),  // Beban promosi/gimmick
            'tgl_refund'          => $tgl,
            'keterangan'          => $ket ?: ('Gimmick '.$noGmc),
        ]);
    }

    /** Kategori Gimmick BO berdasarkan nama (yang dipilih di form GMC). */
    private function gimmickCategory(string $name): ?object
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return $this->db()->table('bs_gimmick_categories')
            ->where('tenant_id', $this->tenantId)->where('is_active', 1)
            ->where('name', $name)
            ->first(['id', 'kpr_biaya_key', 'debit_account_id', 'payable_account_id']);
    }

    /**
     * Pecahan akun kategori: [{side, account_id, portion}]. Bila tabel multi-akun belum terisi
     * (mis. BO belum dimigrasi), jatuh ke pasangan akun tunggal pada headernya.
     *
     * @return array<int,array{side:string, account_id:int, portion:float}>
     */
    private function gimmickSplit(int $categoryId): array
    {
        try {
            $rows = $this->db()->table('bs_gimmick_category_accounts')
                ->where('gimmick_category_id', $categoryId)
                ->orderBy('side')->orderBy('sort_order')->orderBy('id')
                ->get(['side', 'account_id', 'portion']);
        } catch (\Throwable $e) {
            $rows = collect();
        }

        if ($rows->isEmpty()) {
            $cat = $this->db()->table('bs_gimmick_categories')->where('id', $categoryId)
                ->first(['debit_account_id', 'payable_account_id']);
            $out = [];
            foreach ([['debit', $cat?->debit_account_id], ['credit', $cat?->payable_account_id]] as [$side, $accId]) {
                if ($accId) {
                    $out[] = ['side' => $side, 'account_id' => (int) $accId, 'portion' => 100.0];
                }
            }

            return $out;
        }

        return $rows->map(fn ($r) => [
            'side' => (string) $r->side, 'account_id' => (int) $r->account_id, 'portion' => (float) $r->portion,
        ])->all();
    }

    /** Id booking ipro_bo dari No. SPR/kontrak FO (bookings.booking_number = fo_kontrak.no_kontrak). */
    private function iproBookingIdByNoKontrak(string $noKontrak): ?int
    {
        $id = $this->db()->table('bookings')
            ->where('tenant_id', $this->tenantId)->where('booking_number', trim($noKontrak))->value('id');

        return $id ? (int) $id : null;
    }

    /** Id unit ipro_bo dari id unit FO (via unit_code = fo_units.kode_unit). */
    private function iproUnitIdByFo(int $foUnitId): ?int
    {
        $code = DB::table('fo_units')->where('id', $foUnitId)->value('kode_unit');
        if (! $code) {
            return null;
        }
        $id = $this->db()->table('units')->where('unit_code', $code)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Komisi/BLT → antrian dengan STRUKTUR LENGKAP (selaras GMC):
     *   Debit  Prepaid/Beban Komisi-BLT  = BRUTO (nilai BLT/komisi)
     *   Kredit Hutang PPh 23             = Σ PPh per unit
     *   Kredit Hutang Komisi/BLT (NETT)  = bruto − PPh
     * account_split memakai porsi = NOMINAL asli — allocate() BO menormalkan per
     * sisi (amount × porsi/total porsi) sehingga nilai jurnal persis; selisih
     * pembulatan jatuh ke baris terbesar (NETT). Tanpa pph/nett (caller lama)
     * → fallback sepasang akun tunggal seperti dulu.
     */
    private function queueIncentive(string $kind, string $noPengajuan, float $amount, ?string $tgl, ?string $ket, float $pph = 0.0, float $nett = 0.0): ?int
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || trim($noPengajuan) === '') {
            return null;
        }

        $accs = (array) config('ipro.incentive_accounts', []);
        $accPrepaid = (int) ($accs['prepaid'] ?? 0) ?: $this->acc('5.2.01.001');
        $accPph = (int) ($accs['pph'] ?? 0) ?: null;
        $accNett = (int) ($accs['nett'] ?? 0) ?: $this->acc('2.1.01.001');

        $split = null;
        if ($pph > 0 && $nett > 0 && $accPph && $accPrepaid && $accNett) {
            $split = [
                ['side' => 'debit', 'account_id' => (int) $accPrepaid, 'portion' => 100.0],
                ['side' => 'credit', 'account_id' => (int) $accPph, 'portion' => round($pph, 2)],
                ['side' => 'credit', 'account_id' => (int) $accNett, 'portion' => round($nett, 2)],
            ];
        }

        return $this->enqueue($kind, $noPengajuan, null, [
            'customer_id'        => null,
            'customer_name'      => null,
            'refund_source'      => null,
            'amount'             => $amount,
            'account_split'      => $split ? json_encode($split) : null,
            'payable_account_id' => $accNett,    // Hutang komisi/insentif (nett)
            'offset_account_id'  => $accPrepaid, // Prepaid/Beban komisi-insentif
            'tgl_refund'         => $tgl,
            'keterangan'         => $ket,
        ]);
    }

    /** Upsert 1 baris antrian (idempoten by kind+refund_no). */
    private function enqueue(string $kind, string $sourceNo, ?int $refundId, array $payload): ?int
    {
        if (! config('ipro.ap_enabled', true)) {
            return null;
        }
        $now = Carbon::now();
        $payload = array_merge($payload, ['refund_no' => $sourceNo, 'updated_at' => $now]);

        $existing = $this->db()->table('bs_ap_refund_queue')
            ->where('tenant_id', $this->tenantId)->where('kind', $kind)->where('refund_no', $sourceNo)->first();
        if ($existing) {
            if ($existing->status !== 'posted') {
                $this->db()->table('bs_ap_refund_queue')->where('id', $existing->id)->update($payload);
            }

            return (int) $existing->id;
        }

        return (int) $this->db()->table('bs_ap_refund_queue')->insertGetId(array_merge($payload, [
            'tenant_id'  => $this->tenantId,
            'kind'       => $kind,
            'refund_id'  => $refundId,
            'status'     => 'pending',
            'created_at' => $now,
        ]));
    }

    /** Id bs_accounts BO dari kode COA J-ERP via coa_map (fallback suspense). */
    private function acc(string $code): ?int
    {
        $map = (array) config('ipro.coa_map', []);
        $suspense = ((int) config('ipro.suspense_account_id', 0)) ?: null;

        return $map[$code] ?? $suspense;
    }

    /** Jenis sumber refund untuk pengelompokan. */
    private function resolveSumber(Refund $r): string
    {
        if (! empty($r->sumber)) {
            return (string) $r->sumber;
        }

        return match ($r->kontrak?->tahap) {
            'SPR' => 'SPR',
            'SPRS' => 'SPRS',
            default => 'TRANSFER_ANONIM',
        };
    }

    /** Id customer ipro_bo dari id customer FO (via code) — selaras IproMasterSync. */
    private function iproCustomerIdByFo(int $foCustomerId): ?int
    {
        if ($foCustomerId <= 0) {
            return null;
        }
        $code = DB::table('fo_customers')->where('id', $foCustomerId)->value('code');
        if (! $code) {
            return null;
        }

        return $this->db()->table('customers')
            ->where('tenant_id', $this->tenantId)->where('code', $code)->value('id');
    }
}
