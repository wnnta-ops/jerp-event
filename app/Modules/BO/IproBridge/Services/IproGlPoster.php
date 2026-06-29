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
        $suspense = ((int) config('ipro.suspense_account_id', 0)) ?: null;
        $debetId = $map[$debetCode] ?? $suspense;
        $kreditId = $map[$kreditCode] ?? $suspense;
        $amount = round((float) $e->amount, 2);

        if ($amount <= 0) {
            Log::info("ipro GL skip {$e->jenis}/{$e->sourceNo}: amount<=0 (tak ada dampak keuangan).");

            return null;
        }
        if (! $debetId || ! $kreditId) {
            // JANGAN hilang diam-diam: lempar → dicatat ke outbox fo_sync_failures (oleh listener).
            throw new \RuntimeException(
                "COA FO tak terpetakan ({$e->jenis} D={$debetCode} K={$kreditCode}); lengkapi config ipro.coa_map atau set ipro.suspense_account_id."
            );
        }

        $reference = "{$e->sourceModul}/{$e->sourceNo}/{$e->jenis}";

        // Idempotensi cepat (jalur umum): sudah pernah diposting?
        $existing = $this->db()->table('fo_gl_postings')
            ->where('tenant_id', $this->tenantId)->where('reference', $reference)->value('journal_id');
        if ($existing) {
            return (int) $existing;
        }

        $date = $e->tgl ? Carbon::parse($e->tgl) : Carbon::now();
        $year = (int) $date->year;
        $month = (int) $date->month;
        $now = Carbon::now();
        $status = (string) config('ipro.gl_status', 'draft');
        $desc = $e->keterangan ?: "FO {$e->jenis} {$e->sourceNo}";

        return (int) $this->db()->transaction(function () use ($debetId, $kreditId, $amount, $reference, $date, $year, $month, $now, $status, $desc, $e) {
            // Gate idempotensi race-safe: unique(tenant_id, reference). Bila baris ini
            // sudah ada (proses paralel), insert gagal → ambil journal_id yang sudah ada.
            try {
                $this->db()->table('fo_gl_postings')->insert([
                    'tenant_id' => $this->tenantId,
                    'reference' => $reference,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Illuminate\Database\QueryException $ex) {
                if ((string) $ex->getCode() === '23000') {
                    return (int) $this->db()->table('fo_gl_postings')
                        ->where('tenant_id', $this->tenantId)->where('reference', $reference)->value('journal_id');
                }
                throw $ex;
            }

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

            $this->db()->table('fo_gl_postings')
                ->where('tenant_id', $this->tenantId)->where('reference', $reference)
                ->update(['journal_id' => $journalId, 'updated_at' => $now]);

            return $journalId;
        });
    }

    private function nextNumber(int $year, int $month): string
    {
        $prefix = sprintf('JFO-%04d%02d-', $year, $month);
        // lockForUpdate: serialkan penomoran bln yg sama (cegah duplikat journal_number
        // saat 2 posting jenis berbeda berjalan paralel). Dipanggil di dalam transaksi.
        $last = $this->db()->table('bs_journals')
            ->where('journal_number', 'like', $prefix . '%')
            ->orderByDesc('journal_number')->lockForUpdate()->value('journal_number');
        $seq = $last ? ((int) substr((string) $last, -5)) + 1 : 1;

        return $prefix . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
