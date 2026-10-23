<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Selaraskan akun HUTANG invoice (bs_ap_invoices.payable_account_id) dengan Struktur Jurnalnya.
 *
 * Struktur Jurnal invoice bisa disusun manual; sebelum 23 Okt 2026 akun hutang invoice tetap
 * akun Kategori walau jurnalnya mengkredit akun lain (mis. Deposit: Kategori → Beban Masih Harus
 * Dibayar, jurnal → Titipan Estate). Akibatnya BPP mencetak baris hutang (total Rp 0) dan
 * pelunasan mendebit akun yang tidak pernah dikredit.
 *
 * Yang diubah HANYA kepala invoice: akun kredit terbesar di jurnalnya. Jurnal tidak disentuh.
 * Invoice yang sudah punya Kuitansi/pembayaran/penghapusan dilewati — jurnal pelunasannya sudah
 * terbit dengan akun lama; dilaporkan supaya diputuskan Accounting. Tanpa --jalankan hanya laporan.
 */
class SelaraskanAkunHutangApCommand extends Command
{
    protected $signature = 'ap:selaraskan-akun-hutang {--jalankan : terapkan perubahan (tanpa ini hanya laporan)}';

    protected $description = 'Akun hutang invoice AP mengikuti Struktur Jurnalnya (invoice yang akun hutangnya tidak dikredit jurnal)';

    public function handle(): int
    {
        $jalankan = (bool) $this->option('jalankan');

        $calon = BsApInvoice::query()
            ->whereNotNull('journal_id')->where('status', '<>', BsApInvoice::STATUS_BATAL)
            ->whereNotExists(fn ($q) => $q->from('bs_journal_lines as l')
                ->whereColumn('l.journal_id', 'bs_ap_invoices.journal_id')
                ->whereColumn('l.account_id', 'bs_ap_invoices.payable_account_id')
                ->where('l.credit', '>', 0))
            ->whereExists(fn ($q) => $q->from('bs_journal_lines as l')
                ->whereColumn('l.journal_id', 'bs_ap_invoices.journal_id')->where('l.credit', '>', 0))
            ->orderBy('invoice_number')
            ->get();

        if ($calon->isEmpty()) {
            $this->info('Semua akun hutang invoice sudah sesuai jurnalnya.');

            return self::SUCCESS;
        }

        $kode = DB::table('bs_accounts')->pluck('code', 'id');
        $ubah = [];
        $lewat = [];
        foreach ($calon as $inv) {
            $baris = DB::table('bs_journal_lines')->where('journal_id', $inv->journal_id)->get(['account_id', 'credit']);
            $baru = BsApInvoice::akunHutangDariJurnal($baris, (int) $inv->payable_account_id);
            if (! $baru || $baru === (int) $inv->payable_account_id) {
                continue;
            }
            $r = [$inv->invoice_number, $inv->jenis, $kode[$inv->payable_account_id] ?? '-', $kode[$baru] ?? '-'];
            $alasan = $inv->kuitansiAktifAtauMenunggu() ? 'ada Kuitansi'
                : ((float) $inv->paid_amount > 0 || $inv->payments()->exists() ? 'ada pembayaran'
                : (DB::table('bs_ap_memorials')->where('ap_invoice_id', $inv->id)->exists() ? 'ada penghapusan' : null));
            if ($alasan) {
                $lewat[] = [...$r, $alasan];

                continue;
            }
            $ubah[] = $r;
            if ($jalankan) {
                // Query builder: updated_at sengaja tidak disentuh (bukan suntingan isi invoice).
                DB::table('bs_ap_invoices')->where('id', $inv->id)->update(['payable_account_id' => $baru]);
            }
        }

        if ($ubah) {
            $this->table(['Invoice', 'Jenis', 'Akun hutang semula', 'Menjadi'], $ubah);
        }
        if ($lewat) {
            $this->warn('Dilewati (sudah diproses lanjut — putuskan dengan Accounting):');
            $this->table(['Invoice', 'Jenis', 'Akun hutang semula', 'Menurut jurnal', 'Alasan'], $lewat);
        }
        $this->line(sprintf('%d invoice %s, %d dilewati.', count($ubah), $jalankan ? 'diselaraskan' : 'akan diselaraskan', count($lewat)));
        if (! $jalankan && $ubah) {
            $this->line('<comment>Mode laporan — jalankan ulang dengan --jalankan untuk menerapkan.</comment>');
        }

        return self::SUCCESS;
    }
}
