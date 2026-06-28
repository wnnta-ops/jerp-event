<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Services;

use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Posting jurnal FO → GL ipro-fo (ipro_bo.bs_journals + bs_journal_lines), ditulis
 * LANGSUNG (1 MySQL). Kode COA J-ERP (config/fobo.php) dipetakan ke id bs_accounts
 * via config('ipro.coa_map'). Default status 'draft' (akuntan verifikasi dulu).
 * Idempoten via kolom reference = "{modul}/{no}/{jenis}".
 */
class IproGlPoster
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

    public function post(FoFinancialEvent $e): ?int
    {
        if (! config('ipro.gl_enabled', true)) {
            return null;
        }

        $mapping = (array) config('fobo.mapping', []);
        $debetCode = $e->akunDebet ?: ($mapping[$e->jenis]['debet'] ?? null);
        $kreditCode = $e->akunKredit ?: ($mapping[$e->jenis]['kredit'] ?? null);

        $map = (array) config('ipro.coa_map', []);
        $debetId = $map[$debetCode] ?? null;
        $kreditId = $map[$kreditCode] ?? null;
        $amount = round((float) $e->amount, 2);

        if (! $debetId || ! $kreditId || $amount <= 0) {
            Log::warning("ipro GL skip {$e->jenis}/{$e->sourceNo}: akun/amount tak lengkap (D={$debetCode} K={$kreditCode} amt={$amount})");

            return null;
        }

        $reference = "{$e->sourceModul}/{$e->sourceNo}/{$e->jenis}";

        $exists = $this->db()->table('bs_journals')
            ->where('tenant_id', $this->tenantId)->where('reference', $reference)->value('id');
        if ($exists) {
            return (int) $exists; // sudah pernah diposting (idempoten)
        }

        $date = $e->tgl ? Carbon::parse($e->tgl) : Carbon::now();
        $year = (int) $date->year;
        $month = (int) $date->month;
        $now = Carbon::now();
        $status = (string) config('ipro.gl_status', 'draft');
        $desc = $e->keterangan ?: "FO {$e->jenis} {$e->sourceNo}";

        return (int) $this->db()->transaction(function () use ($debetId, $kreditId, $amount, $reference, $date, $year, $month, $now, $status, $desc, $e) {
            $journalId = $this->db()->table('bs_journals')->insertGetId([
                'tenant_id'      => $this->tenantId,
                'journal_number' => $this->nextNumber($year, $month),
                'journal_date'   => $date->toDateString(),
                'period_year'    => $year,
                'period_month'   => $month,
                'source'         => 'ar',
                'reference'      => $reference,
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
        });
    }

    private function nextNumber(int $year, int $month): string
    {
        $prefix = sprintf('JFO-%04d%02d-', $year, $month);
        $last = $this->db()->table('bs_journals')
            ->where('journal_number', 'like', $prefix . '%')
            ->orderByDesc('journal_number')->value('journal_number');
        $seq = $last ? ((int) substr((string) $last, -5)) + 1 : 1;

        return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
