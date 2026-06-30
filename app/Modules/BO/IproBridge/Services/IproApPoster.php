<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Services;

use App\Modules\FO\FinanceAR\Models\Refund;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Refund FO → Account Payable BO (ipro_bo.bs_ap_invoices) sebagai HUTANG ke customer
 * yang masih OPEN (belum dibayar). Pembayaran dilakukan di BO via alur kuitansi/bayar
 * hutang AP yang sudah ada. Ditandai source_type='refund' + refund_source (jenis sumber:
 * SPR / SPRS / TRANSFER_ANONIM) supaya bisa difilter di daftar Hutang.
 *
 * Jurnal pengakuan (status draft, akuntan verifikasi): Debit akun lawan (titipan/piutang
 * 1.1.02.001) / Kredit Hutang Refund (2.1.01.004). Saat dibayar di BO: Debit Hutang Refund
 * / Kredit Kas → bersih. Idempoten via (source_type='refund', source_id=refund.id).
 */
class IproApPoster
{
    private const PAYABLE_CODE = '2.1.01.004'; // Hutang Refund
    private const OFFSET_CODE = '1.1.02.001';  // Lawan: titipan/piutang penjualan

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

    /** Tulis 1 invoice hutang refund (open) di BO. Return id invoice, atau null bila dilewati. */
    public function postRefund(Refund $r): ?int
    {
        if (! config('ipro.ap_enabled', true)) {
            return null;
        }
        $amount = round((float) $r->nilai_refund, 2);
        if ($amount <= 0) {
            return null;
        }

        // Idempotensi: sudah pernah dikirim?
        $existing = $this->db()->table('bs_ap_invoices')
            ->where('tenant_id', $this->tenantId)
            ->where('source_type', 'refund')->where('source_id', $r->id)->value('id');
        if ($existing) {
            return (int) $existing;
        }

        $map = (array) config('ipro.coa_map', []);
        $suspense = ((int) config('ipro.suspense_account_id', 0)) ?: null;
        $payableId = $map[self::PAYABLE_CODE] ?? $suspense;
        $offsetId = $map[self::OFFSET_CODE] ?? $suspense;
        if (! $payableId || ! $offsetId) {
            throw new \RuntimeException(
                'COA refund tak terpetakan ('.self::PAYABLE_CODE.' / '.self::OFFSET_CODE.'); lengkapi ipro.coa_map atau set ipro.suspense_account_id.'
            );
        }

        $custId = $this->iproCustomerIdByFo((int) $r->customer_id);
        $sumber = $this->resolveSumber($r);
        $now = Carbon::now();
        $date = $r->tgl_refund ? Carbon::parse((string) $r->tgl_refund) : $now;
        $custName = $r->customer?->full_name;
        $desc = 'Refund '.$r->no_refund.($custName ? ' — '.$custName : '');

        return (int) $this->db()->transaction(function () use ($payableId, $offsetId, $custId, $sumber, $amount, $date, $now, $desc, $r) {
            $journalId = $this->postRecognitionJournal($offsetId, $payableId, $amount, $date, $now, $desc, $r->no_refund);

            return $this->db()->table('bs_ap_invoices')->insertGetId([
                'tenant_id'          => $this->tenantId,
                'invoice_number'     => $this->invoiceNumber($r->no_refund),
                'invoice_date'       => $date->toDateString(),
                'receive_date'       => $date->toDateString(),
                'due_date'           => $date->toDateString(),
                'supplier_id'        => null,
                'customer_id'        => $custId,
                'source_type'        => 'refund',
                'source_id'          => $r->id,
                'refund_source'      => $sumber,
                'trx_type'           => 'normal',
                'expense_account_id' => $offsetId,
                'payable_account_id' => $payableId,
                'description'        => $desc,
                'dpp'                => $amount,
                'total'              => $amount,
                'net_payable'        => $amount,
                'paid_amount'        => 0,
                'status'             => 'open',
                'journal_id'         => $journalId,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        });
    }

    /** Jurnal pengakuan hutang refund: Debit lawan / Kredit Hutang Refund (draft). */
    private function postRecognitionJournal(int $debetId, int $kreditId, float $amount, Carbon $date, Carbon $now, string $desc, string $sourceNo): int
    {
        $year = (int) $date->year;
        $month = (int) $date->month;
        $status = (string) config('ipro.gl_status', 'draft');

        $journalId = (int) $this->db()->table('bs_journals')->insertGetId([
            'tenant_id'      => $this->tenantId,
            'journal_number' => $this->nextJournalNumber($year, $month),
            'journal_date'   => $date->toDateString(),
            'period_year'    => $year,
            'period_month'   => $month,
            'source'         => 'ap',
            'reference'      => 'FO.FinanceAR/'.$sourceNo.'/REFUND_AP',
            'description'    => $desc,
            'status'         => $status,
            'total_debit'    => $amount,
            'total_credit'   => $amount,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $this->db()->table('bs_journal_lines')->insert([
            ['journal_id' => $journalId, 'account_id' => $debetId, 'debit' => $amount, 'credit' => 0, 'currency' => 'IDR', 'kurs' => 1, 'description' => $desc, 'created_at' => $now, 'updated_at' => $now],
            ['journal_id' => $journalId, 'account_id' => $kreditId, 'debit' => 0, 'credit' => $amount, 'currency' => 'IDR', 'kurs' => 1, 'description' => $desc, 'created_at' => $now, 'updated_at' => $now],
        ]);

        return $journalId;
    }

    private function nextJournalNumber(int $year, int $month): string
    {
        $prefix = sprintf('JFO-%04d%02d-', $year, $month);
        $last = $this->db()->table('bs_journals')
            ->where('journal_number', 'like', $prefix.'%')
            ->orderByDesc('journal_number')->lockForUpdate()->value('journal_number');
        $seq = $last ? ((int) substr((string) $last, -5)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    private function invoiceNumber(string $noRefund): string
    {
        return 'REF/'.$noRefund;
    }

    /** Jenis sumber refund untuk pengelompokan AP. */
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
