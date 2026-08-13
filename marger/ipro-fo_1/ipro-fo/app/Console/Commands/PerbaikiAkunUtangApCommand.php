<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi akun kredit invoice hutang LAMA: Uang Muka Penjualan → Utang Usaha Supplier.
 *
 * LATAR
 * Sebelum sisi kredit invoice hutang dibakukan, akun kreditnya diambil dari setelan
 * Kategori Gimmick. Kategori yang kolom kreditnya diarahkan ke Uang Muka Penjualan
 * membuat invoice hutang mengkredit akun itu — padahal yang terbit adalah KEWAJIBAN
 * KEPADA SUPPLIER, bukan uang muka dari pembeli. Posting baru sudah benar sejak
 * pembakuan tersebut; perintah ini merapikan yang telanjur terbit.
 *
 * ═══ BATAS YANG TIDAK BOLEH DILANGGAR ═══
 * Akun Uang Muka Penjualan (20106060601) JUGA DIPAKAI SAH oleh modul lain:
 *   · BookedRevenueService          — pendapatan diterima dimuka
 *   · ConvertUtjDpToUangMuka        — konversi UTJ/DP customer
 *   · BankApprovalController        — approval setoran bank
 *   · PettyCashController           — UTJ/DP customer
 *   · BookedRevenueController       — kolom UM
 * Mengganti SELURUH kredit pada akun itu akan menghancurkan saldo pendapatan diterima
 * dimuka dan uang muka pelanggan. Karena itu perintah ini HANYA menyentuh baris yang
 * memenuhi SEMUA syarat berikut:
 *   1. baris KREDIT (credit > 0) pada akun Uang Muka Penjualan;
 *   2. jurnalnya bersumber 'ap' (JournalService menandainya begitu saat invoice terbit);
 *   3. jurnalnya benar-benar tertaut ke sebuah invoice hutang lewat
 *      bs_ap_invoices.journal_id — bukan sekadar kebetulan bersumber 'ap';
 *   4. periode fiskalnya BELUM ditutup.
 *
 * Baris di luar itu tidak pernah disentuh, walau akunnya sama.
 *
 * CARA PAKAI
 *   php artisan ap:perbaiki-akun-utang                 # telaah saja, tidak menulis apa pun
 *   php artisan ap:perbaiki-akun-utang --apply         # kerjakan
 *   php artisan ap:perbaiki-akun-utang --invoice=123   # batasi ke satu invoice
 *   php artisan ap:perbaiki-akun-utang --apply --sertakan-periode-tutup
 *
 * SIFAT
 * · Telaah dulu secara bawaan — menulis hanya bila --apply diberikan.
 * · Satu transaksi: kalau ada satu saja yang gagal, tidak ada yang berubah.
 * · Idempoten: dijalankan dua kali, jalan kedua tidak menemukan apa pun lagi.
 * · Jumlah debit & kredit tiap jurnal TIDAK berubah — yang berpindah hanya akunnya,
 *   jadi neraca saldo tetap seimbang dan total jurnal tidak perlu dihitung ulang.
 */
class PerbaikiAkunUtangApCommand extends Command
{
    protected $signature = 'ap:perbaiki-akun-utang
        {--apply : Tulis perubahan. Tanpa opsi ini perintah hanya menelaah.}
        {--invoice=* : Batasi ke id invoice hutang tertentu (boleh diulang).}
        {--sertakan-periode-tutup : Ikut memperbaiki jurnal pada periode fiskal yang sudah ditutup.}';

    protected $description = 'Koreksi akun kredit invoice hutang lama dari Uang Muka Penjualan ke Utang Usaha Supplier';

    public function handle(): int
    {
        $kodeSalah = (string) config('business_support.gl_accounts.unearned_revenue');
        $kodeBenar = (string) config('business_support.gl_accounts.payable');

        if ($kodeSalah === '' || $kodeBenar === '') {
            $this->error('Kode akun belum lengkap di config business_support.gl_accounts.');

            return self::FAILURE;
        }

        $akunSalah = DB::table('bs_accounts')->where('code', $kodeSalah)->first(['id', 'code', 'name']);
        $akunBenar = DB::table('bs_accounts')->where('code', $kodeBenar)->first(['id', 'code', 'name']);

        if (! $akunSalah || ! $akunBenar) {
            $this->error("Akun tidak ditemukan: {$kodeSalah} / {$kodeBenar}.");

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Koreksi akun kredit invoice hutang');
        $this->line("  dari : {$akunSalah->code} — {$akunSalah->name}");
        $this->line("  jadi : {$akunBenar->code} — {$akunBenar->name}");
        $this->line('');

        $baris = $this->cariBaris((int) $akunSalah->id);

        if ($baris->isEmpty()) {
            $this->info('Tidak ada baris jurnal yang perlu diperbaiki. Selesai.');

            return self::SUCCESS;
        }

        // Periode fiskal yang sudah ditutup dipisahkan: mengubah jurnal di dalamnya berarti
        // menggeser angka periode yang sudah dilaporkan dan dikunci.
        $tutup  = $baris->filter(fn ($r) => (bool) $r->periode_tutup);
        $garap  = $this->option('sertakan-periode-tutup') ? $baris : $baris->reject(fn ($r) => (bool) $r->periode_tutup);

        $this->tampilkanTabel($garap, $akunBenar->code);

        if ($tutup->isNotEmpty() && ! $this->option('sertakan-periode-tutup')) {
            $this->line('');
            $this->warn($tutup->count().' baris DILEWATI karena periode fiskalnya sudah ditutup:');
            foreach ($tutup->groupBy(fn ($r) => $r->period_year.'/'.str_pad((string) $r->period_month, 2, '0', STR_PAD_LEFT)) as $p => $g) {
                $this->line("    {$p} — {$g->count()} baris, ".$this->rp((float) $g->sum('credit')));
            }
            $this->line('    Jalankan dengan --sertakan-periode-tutup bila memang ingin ikut diperbaiki.');
        }

        if ($garap->isEmpty()) {
            $this->line('');
            $this->info('Tidak ada yang bisa dikerjakan.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  Baris jurnal  : '.$garap->count());
        $this->line('  Invoice       : '.$garap->pluck('invoice_id')->unique()->count());
        $this->line('  Total nilai   : '.$this->rp((float) $garap->sum('credit')));

        if (! $this->option('apply')) {
            $this->line('');
            $this->comment('TELAAH SAJA — belum ada yang diubah. Tambahkan --apply untuk mengerjakannya.');
            $this->comment('Disarankan mysqldump dulu sebelum --apply di lingkungan produksi.');

            return self::SUCCESS;
        }

        $hasil = $this->kerjakan($garap, (int) $akunBenar->id, (int) $akunSalah->id);

        $this->line('');
        $this->info('Selesai.');
        $this->line('  Baris jurnal diperbaiki : '.$hasil['baris']);
        $this->line('  Header invoice diperbaiki: '.$hasil['header']);

        return self::SUCCESS;
    }

    /**
     * Baris kredit pada akun yang salah, DAN benar-benar milik jurnal invoice hutang.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function cariBaris(int $akunSalahId): \Illuminate\Support\Collection
    {
        $q = DB::table('bs_journal_lines as l')
            ->join('bs_journals as j', 'j.id', '=', 'l.journal_id')
            // INNER JOIN ke invoice: inilah yang memastikan jurnalnya memang invoice hutang,
            // bukan jurnal lain yang kebetulan bersumber 'ap'.
            ->join('bs_ap_invoices as i', 'i.journal_id', '=', 'j.id')
            ->leftJoin('bs_suppliers as s', 's.id', '=', 'i.supplier_id')
            ->leftJoin('bs_fiscal_periods as fp', function ($join) {
                $join->on('fp.year', '=', 'j.period_year')->on('fp.month', '=', 'j.period_month');
            })
            ->where('l.account_id', $akunSalahId)
            ->where('l.credit', '>', 0)
            ->where('j.source', 'ap')
            ->select([
                'l.id as line_id', 'l.credit',
                'j.id as journal_id', 'j.journal_number', 'j.journal_date',
                'j.period_year', 'j.period_month', 'j.status as journal_status',
                'i.id as invoice_id', 'i.invoice_number', 'i.source_type', 'i.payable_account_id',
                DB::raw("COALESCE(s.name, '—') as supplier"),
                DB::raw("CASE WHEN fp.status = 'closed' THEN 1 ELSE 0 END as periode_tutup"),
            ])
            ->orderBy('j.journal_date')
            ->orderBy('l.id');

        if (Schema::hasColumn('bs_journals', 'deleted_at')) {
            $q->whereNull('j.deleted_at');
        }
        if (Schema::hasColumn('bs_ap_invoices', 'deleted_at')) {
            $q->whereNull('i.deleted_at');
        }

        $ids = array_filter((array) $this->option('invoice'));
        if ($ids !== []) {
            $q->whereIn('i.id', $ids);
        }

        return $q->get();
    }

    /** @param  \Illuminate\Support\Collection<int, object>  $baris */
    private function tampilkanTabel(\Illuminate\Support\Collection $baris, string $kodeBenar): void
    {
        if ($baris->isEmpty()) {
            return;
        }

        $this->table(
            ['Invoice', 'Tanggal', 'Jurnal', 'Supplier', 'Sumber', 'Status', 'Nilai', 'Akun baru'],
            $baris->take(50)->map(fn ($r) => [
                $r->invoice_number ?? ('#'.$r->invoice_id),
                (string) $r->journal_date,
                $r->journal_number ?? ('#'.$r->journal_id),
                mb_strimwidth((string) $r->supplier, 0, 26, '…'),
                (string) ($r->source_type ?? '—'),
                (string) $r->journal_status,
                $this->rp((float) $r->credit),
                $kodeBenar,
            ])->all()
        );

        if ($baris->count() > 50) {
            $this->line('  … dan '.($baris->count() - 50).' baris lagi (tabel dipotong 50 baris pertama).');
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $baris
     * @return array{baris: int, header: int}
     */
    private function kerjakan(\Illuminate\Support\Collection $baris, int $akunBenarId, int $akunSalahId): array
    {
        return DB::transaction(function () use ($baris, $akunBenarId, $akunSalahId) {
            // Baris jurnal. Syarat account_id lama ikut dipasang supaya jalan kedua tidak
            // menyentuh apa pun, dan supaya baris yang keburu diperbaiki proses lain aman.
            $lineIds = $baris->pluck('line_id')->all();
            $nBaris  = DB::table('bs_journal_lines')
                ->whereIn('id', $lineIds)
                ->where('account_id', $akunSalahId)
                ->update(['account_id' => $akunBenarId]);

            // Header invoice: payable_account_id ikut dibetulkan, kalau tidak layar & laporan
            // yang membaca header tetap menampilkan akun lama walau jurnalnya sudah benar.
            $invoiceIds = $baris->pluck('invoice_id')->unique()->all();
            $nHeader = DB::table('bs_ap_invoices')
                ->whereIn('id', $invoiceIds)
                ->where('payable_account_id', $akunSalahId)
                ->update(['payable_account_id' => $akunBenarId]);

            return ['baris' => $nBaris, 'header' => $nHeader];
        });
    }

    private function rp(float $v): string
    {
        return 'Rp '.number_format($v, 0, ',', '.');
    }
}
