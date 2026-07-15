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
    public function queueKomisi(string $noPengajuan, float $amount, ?string $tgl = null, ?string $ket = null): ?int
    {
        return $this->queueIncentive('komisi', $noPengajuan, $amount, $tgl, $ket ?: ('Komisi '.$noPengajuan));
    }

    /** Realisasi BLT (batch per No. Pengajuan) → antrian (kind=blt). */
    public function queueBlt(string $noPengajuan, float $amount, ?string $tgl = null, ?string $ket = null): ?int
    {
        return $this->queueIncentive('blt', $noPengajuan, $amount, $tgl, $ket ?: ('BLT '.$noPengajuan));
    }

    /**
     * GMC (gimmick/promo developer, mengikat SPR/customer) → antrian (kind=gmc).
     * $supplierId (bs_suppliers.id) diisi untuk komponen GMC multi-supplier → di
     * Posting Invoice BO dropdown supplier terisi otomatis.
     */
    public function queueGmc(string $noGmc, float $amount, ?int $foCustomerId = null, ?string $custName = null, ?string $tgl = null, ?string $ket = null, ?int $supplierId = null): ?int
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || trim($noGmc) === '') {
            return null;
        }

        return $this->enqueue('gmc', $noGmc, null, [
            'customer_id'        => $foCustomerId ? $this->iproCustomerIdByFo($foCustomerId) : null,
            'customer_name'      => $custName,
            'refund_source'      => null,
            'amount'             => $amount,
            'supplier_id'        => $supplierId,
            'payable_account_id' => $this->acc('2.1.01.004'), // Hutang/Titipan
            'offset_account_id'  => $this->acc('5.9.01.001'), // Beban promosi/gimmick
            'tgl_refund'         => $tgl,
            'keterangan'         => $ket ?: ('Gimmick '.$noGmc),
        ]);
    }

    /** Komisi/BLT → hutang insentif: Beban Komisi (5.2.01.001) / Hutang Komisi (2.1.01.001). */
    private function queueIncentive(string $kind, string $noPengajuan, float $amount, ?string $tgl, ?string $ket): ?int
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || trim($noPengajuan) === '') {
            return null;
        }

        return $this->enqueue($kind, $noPengajuan, null, [
            'customer_id'        => null,
            'customer_name'      => null,
            'refund_source'      => null,
            'amount'             => $amount,
            'payable_account_id' => $this->acc('2.1.01.001'), // Hutang komisi/insentif
            'offset_account_id'  => $this->acc('5.2.01.001'), // Beban komisi/insentif
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
