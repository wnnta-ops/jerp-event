<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Services\FixedAssetService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Posting saldo awal Aset Tetap ke General Ledger.
 *
 * Aset hasil upload massal / migrasi tidak memposting jurnal perolehan, sehingga terputus dari GL
 * dan tak muncul di Neraca Saldo. Command ini menjurnal saldo awal per pasangan akun (kategori COA):
 *   Dr Aset Tetap / Cr Akumulasi Penyusutan / Cr Laba Ditahan (nilai buku).
 * Idempoten: aset yang sudah ber-GL (acquire_journal_id terisi) DILEWATI — jalan 2× tak menambah apa pun.
 */
class FaOpeningBalanceCommand extends Command
{
    protected $signature = 'fa:opening-balance
        {--as-of= : Tanggal cutoff Y-m-d (default: akhir bulan lalu)}
        {--dry-run : Hitung & tampilkan tanpa memposting}';

    protected $description = 'Posting saldo awal aset tetap (harga perolehan + akumulasi penyusutan) ke GL agar muncul di Neraca Saldo';

    public function handle(FixedAssetService $svc): int
    {
        $raw = trim((string) $this->option('as-of'));
        $asOf = $raw !== '' ? $raw : Carbon::now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        try {
            $asOf = Carbon::parse($asOf)->toDateString();
        } catch (\Throwable) {
            $this->error("Tanggal --as-of tidak valid: {$raw}");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $this->info(($dry ? '[DRY-RUN] ' : '').'Saldo awal aset tetap per '.$asOf);

        try {
            if ($dry) {
                // Dry-run: jalankan sungguhan di dalam transaksi lalu ROLLBACK, sehingga angka yang
                // ditampilkan identik dengan hasil posting tanpa menyimpan apa pun ke DB.
                DB::beginTransaction();
                try {
                    $result = $svc->runOpeningBalance($asOf, null);
                } finally {
                    DB::rollBack();
                }
            } else {
                $result = $svc->runOpeningBalance($asOf, null);
            }
        } catch (\Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        if ((int) $result['assets'] === 0) {
            $this->warn('Tidak ada aset yang perlu diposting (semua sudah ber-GL atau tidak layak).');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($result['by_category'] as $name => $c) {
            $rows[] = [
                $name,
                (string) $c['assets'],
                number_format($c['cost'], 2, ',', '.'),
                number_format($c['accum'], 2, ',', '.'),
                number_format($c['cost'] - $c['accum'], 2, ',', '.'),
            ];
        }
        $this->table(['Kategori', 'Aset', 'Harga Perolehan', 'Akum. Penyusutan', 'Nilai Buku'], $rows);

        $this->line(sprintf(
            '%s: %d aset, %d jurnal — total perolehan %s, akumulasi %s, nilai buku %s',
            $dry ? 'RINGKAS (belum diposting)' : 'DIPOSTING',
            (int) $result['assets'],
            (int) $result['journals'],
            number_format((float) $result['total_cost'], 2, ',', '.'),
            number_format((float) $result['total_accum'], 2, ',', '.'),
            number_format((float) $result['total_cost'] - (float) $result['total_accum'], 2, ',', '.'),
        ));

        return self::SUCCESS;
    }
}
