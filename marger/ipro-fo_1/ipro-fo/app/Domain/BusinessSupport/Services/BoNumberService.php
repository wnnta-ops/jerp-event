<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Support\BoDocumentNumbers;
use App\Domain\Foundation\Numerator\Models\Numerator;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Penerbit nomor dokumen Back Office, mengikuti master Settings → Numerator Back Office.
 *
 * Mesinnya memakai ulang NumeratorService milik Front Office — sudah teruji dan sudah
 * mengunci barisnya (lockForUpdate), sehingga dua penyimpanan bersamaan tidak lagi bisa
 * mendapat nomor yang sama. Itu perbaikan tersendiri: sebelum ini hanya SIP yang mengunci,
 * sementara 34 dokumen lain menghitung COUNT lalu menyimpan — dua orang menekan Simpan pada
 * detik yang sama bisa memperoleh nomor kembar, dan indeks unik menolaknya sebagai galat.
 *
 * DUA hal yang membedakannya dari pemakaian NumeratorService di Front Office:
 *
 *  1. Segmen tanggal diambil dari TANGGAL DOKUMEN, bukan tanggal hari ini. Dokumen bertanggal
 *     mundur tetap memakai bulannya sendiri, persis perilaku sebelumnya. (Yang tetap memakai
 *     tanggal hari ini hanyalah penentuan kapan nomor urut kembali ke 1.)
 *
 *  2. Ada JALAN MUNDUR. Bila baris masternya belum ada atau apa pun gagal, penerbitan TIDAK
 *     dibatalkan — ia jatuh ke cara lama yang dikirim pemanggilnya. Dokumen yang gagal terbit
 *     hanya karena baris pengaturannya terhapus adalah kerugian yang jauh lebih besar
 *     daripada nomor yang formatnya belum mengikuti master.
 */
final class BoNumberService
{
    public function __construct(private readonly NumeratorService $numerator) {}

    /**
     * Terbitkan nomor untuk satu dokumen Back Office.
     *
     * @param  string    $code    kode katalog (lihat BoDocumentNumbers::all())
     * @param  ?Carbon   $date    tanggal dokumen — menentukan segmen {YY}/{MM}/{MM_ROMAN}/{YYYY}
     * @param  array<string,scalar|null> $extra token tambahan, mis. ['KATEGORI' => 'BIAYA-TELP']
     * @param  ?callable $lama    cara lama; dipakai bila master tidak bisa dipakai
     */
    public function next(string $code, ?Carbon $date = null, array $extra = [], ?callable $lama = null): string
    {
        $d = $date ?: Carbon::now();

        try {
            $terbit = fn () => $this->numerator->next($code, array_merge([
                'YYYY'     => $d->format('Y'),
                'YY'       => $d->format('y'),
                'MM'       => $d->format('m'),
                'DD'       => $d->format('d'),
                'MM_ROMAN' => NumeratorService::romanMonth((int) $d->format('n')),
                'MMM_ID'   => NumeratorService::monthAbbrId((int) $d->format('n')),
            ], $extra), BoDocumentNumbers::SCOPE);
            // scope adalah argumen KETIGA NumeratorService::next(), bukan kunci di dalam
            // context. Mengirimnya di dalam context membuat pencarian jatuh ke bawaan 'fo',
            // tidak menemukan satu pun baris BO, dan SETIAP penerbitan nomor diam-diam
            // kembali ke cara lama — master tidak pernah terpakai sama sekali.
            $nomor = $terbit();

            /* PENJAGA NOMOR GANDA. Penghitung master bisa TERTINGGAL dari dokumen yang sudah
               ada — data migrasi & sinkron eProperty ditulis langsung ke tabel tanpa menaikkan
               penghitungnya (kejadian 21 Okt 2026: AP.KUI di penghitung 8, kuitansi sudah
               sampai 00147, sehingga Simpan pelunasan AP selalu gagal "Duplicate entry").
               Nomor yang sudah terpakai dilewati — penghitungnya ikut naik — sampai ketemu
               nomor kosong. Batasnya longgar; selebihnya jalankan bo:numerator-sinkron. */
            $kat = BoDocumentNumbers::all()[$code] ?? null;
            if ($kat && ($kat['tabel'] ?? null) && ($kat['kolom'] ?? null) && \Illuminate\Support\Facades\Schema::hasTable($kat['tabel'])) {
                for ($i = 0; $i < 5000 && DB::table($kat['tabel'])->where($kat['kolom'], $nomor)->exists(); $i++) {
                    $nomor = $terbit();
                }
            }

            return $nomor;
        } catch (\Throwable $e) {
            // Dicatat, tidak didiamkan: kalau master tidak terpakai, seseorang harus tahu.
            report($e);

            if ($lama !== null) {
                return (string) $lama();
            }

            throw $e;
        }
    }

    /**
     * Rakit nomor memakai format master, TANPA menaikkan nomor urutnya.
     *
     * Untuk dokumen yang penghitungnya harus tetap di tempatnya. SIP satu-satunya: urutannya
     * disimpan di kolom bs_sips.print_seq, dihitung per TAHUN CETAK, dan dijaga indeks unik
     * (tenant_id, print_year, print_seq). Master numerator hanya punya satu current_seq yang
     * kembali ke 1 menurut tahun berjalan — ia tidak bisa menyatakan "urut per tahun cetak",
     * jadi memindahkan penghitungnya ke sana akan membuat SIP bertanggal mundur mengambil
     * urutan tahun berjalan lalu menyimpannya sebagai tahun lain, dan indeks uniknya menolak.
     *
     * Yang diambil dari master di sini hanya BENTUKNYA — itulah yang memang diatur operator.
     */
    public function render(string $code, ?Carbon $date, int $seq, array $extra = [], ?callable $lama = null): string
    {
        $d = $date ?: Carbon::now();

        try {
            $n = Numerator::where('scope', BoDocumentNumbers::SCOPE)->where('code', $code)->firstOrFail();

            $vars = array_merge([
                'PREFIX'   => $n->prefix,
                'YYYY'     => $d->format('Y'),
                'YY'       => $d->format('y'),
                'MM'       => $d->format('m'),
                'DD'       => $d->format('d'),
                'MM_ROMAN' => NumeratorService::romanMonth((int) $d->format('n')),
                'MMM_ID'   => NumeratorService::monthAbbrId((int) $d->format('n')),
                'SEQ'      => str_pad((string) $seq, (int) $n->seq_padding, '0', STR_PAD_LEFT),
            ], $extra);

            $hasil = $n->format ?: (string) config('domain.numerator.fallback_format');
            foreach ($vars as $k => $v) {
                $hasil = str_replace('{'.$k.'}', (string) $v, $hasil);
            }

            return $hasil;
        } catch (\Throwable $e) {
            report($e);

            if ($lama !== null) {
                return (string) $lama();
            }

            throw $e;
        }
    }

    /**
     * Nomor urut tertinggi yang SUDAH terpakai untuk sebuah kode, dibaca dari dokumennya
     * sendiri. Dipakai saat menanam baris master supaya nomor berikutnya melanjutkan —
     * bukan mulai dari 1 dan menabrak dokumen yang sudah terbit.
     *
     * Nomor urut dianggap sebagai kelompok ANGKA TERAKHIR pada nomor dokumen, kecuali SIP
     * yang meletakkannya di depan. Baris yang tidak berpola diabaikan — di bs_journals ada
     * baris yang nomornya ditimpa nomor invoice (RegisterApInvoice), dan memaksanya masuk
     * hitungan akan memberi nomor urut yang salah.
     */
    public static function urutTertinggi(string $code, string $tabel, string $kolom, string $prefix): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable($tabel)) {
            return 0;
        }

        // SIP dibaca dari SELURUH barisnya; sisanya cukup yang berawalan prefiksnya sendiri.
        $q = DB::table($tabel)->whereNotNull($kolom);
        if ($code !== 'SIP') {
            $q->where($kolom, 'like', $prefix.'/%');
        }

        $tertinggi = 0;
        foreach ($q->pluck($kolom) as $nomor) {
            $nomor = (string) $nomor;
            $bagian = preg_split('#[/\-]#', $nomor) ?: [];
            if ($bagian === []) {
                continue;
            }

            // SIP punya DUA bentuk yang hidup berdampingan, dan keduanya harus terbaca:
            //   lama  "SIP/MUP/26/VI/00002" — nomor urut di BELAKANG
            //   baru  "002/FIN/MITRA-SI/VI/2026" — nomor urut di DEPAN
            // Keduanya bisa dibedakan pasti: yang lama diawali "SIP/". Membaca segmen
            // terakhir pada bentuk baru akan mengambil TAHUNNYA (2026) dan melompatkan
            // nomor urut ke ribuan.
            $angka = $code === 'SIP'
                ? (str_starts_with($nomor, 'SIP/') ? $bagian[count($bagian) - 1] : $bagian[0])
                : $bagian[count($bagian) - 1];

            if ($angka !== '' && ctype_digit($angka)) {
                $tertinggi = max($tertinggi, (int) $angka);
            }
        }

        // SIP juga menyimpan penghitungnya sendiri di kolom print_seq — dipakai bila lebih
        // tinggi daripada yang terbaca dari nomornya.
        if ($code === 'SIP' && \Illuminate\Support\Facades\Schema::hasColumn($tabel, 'print_seq')) {
            $tertinggi = max($tertinggi, (int) DB::table($tabel)->max('print_seq'));
        }

        return $tertinggi;
    }

    /**
     * Nomor urut tertinggi TAHUN INI untuk sebuah kode — dibaca dari dokumennya, mengikuti
     * FORMAT baris masternya ({YY}/{YYYY} dikunci ke tahun berjalan, {SEQ} ditangkap, token
     * lain bebas). Penghitung seluruh katalog di-reset tahunan, jadi nomor tahun lalu tidak
     * boleh ikut menaikkannya. Format tanpa token tahun jatuh ke urutTertinggi() (semua tahun).
     */
    public static function urutTertinggiTahunIni(string $tabel, string $kolom, string $prefix, string $format): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable($tabel)) {
            return 0;
        }
        if (! str_contains($format, '{YY}') && ! str_contains($format, '{YYYY}')) {
            return self::urutTertinggi('', $tabel, $kolom, $prefix);
        }

        $now = Carbon::now();
        $pola = preg_quote($format, '#');
        $ganti = [
            preg_quote('{PREFIX}', '#') => preg_quote($prefix, '#'),
            preg_quote('{YYYY}', '#') => $now->format('Y'),
            preg_quote('{YY}', '#') => $now->format('y'),
            preg_quote('{MM}', '#') => '\\d{2}',
            preg_quote('{DD}', '#') => '\\d{2}',
            preg_quote('{MM_ROMAN}', '#') => '[IVXLC]+',
            preg_quote('{SEQ}', '#') => '(?P<seq>\\d+)',
        ];
        $pola = strtr($pola, $ganti);
        // Token lain ({KATEGORI}, {PROJ}, {MMM_ID}, …) — bebas, tanpa garis miring.
        $pola = preg_replace('#\\\{[A-Z_]+\\\}#', '[^/]+', $pola);
        $pola = '#^'.$pola.'$#';

        $tertinggi = 0;
        $q = DB::table($tabel)->whereNotNull($kolom);
        if (! str_starts_with($format, '{SEQ}')) {
            $q->where($kolom, 'like', $prefix.'%');
        }
        foreach ($q->pluck($kolom) as $nomor) {
            if (preg_match($pola, (string) $nomor, $m)) {
                $tertinggi = max($tertinggi, (int) $m['seq']);
            }
        }

        return $tertinggi;
    }

    /**
     * Naikkan penghitung master yang TERTINGGAL dari dokumen yang sudah ada (tahun ini).
     * Penghitung tidak pernah diturunkan. $jalankan=false hanya melaporkan rencananya.
     *
     * @return array<int, array{code:string, label:string, sekarang:int, tertinggi:int, baru:int}>
     */
    public static function sinkronPenghitung(bool $jalankan): array
    {
        $katalog = BoDocumentNumbers::all();
        $periode = Carbon::now()->format('Y');
        $hasil = [];

        foreach (Numerator::withoutGlobalScopes()->where('scope', BoDocumentNumbers::SCOPE)->orderBy('code')->get() as $n) {
            $d = $katalog[$n->code] ?? null;
            if (! $d || ! ($d['tabel'] ?? null) || ! ($d['kolom'] ?? null) || $n->code === 'SIP') {
                continue;   // SIP dihitung dari print_seq-nya sendiri
            }
            $sekarang = $n->current_period === $periode ? (int) $n->current_seq : 0;
            $tertinggi = self::urutTertinggiTahunIni($d['tabel'], $d['kolom'], (string) $n->prefix, (string) ($n->format ?: $d['format']));
            if ($tertinggi <= $sekarang) {
                continue;
            }
            $hasil[] = ['code' => (string) $n->code, 'label' => (string) $d['label'], 'sekarang' => $sekarang, 'tertinggi' => $tertinggi, 'baru' => $tertinggi];
            if ($jalankan) {
                DB::table('numerators')->where('id', $n->id)
                    ->update(['current_period' => $periode, 'current_seq' => $tertinggi, 'updated_at' => now()]);
            }
        }

        return $hasil;
    }

    /**
     * Pastikan seluruh dokumen katalog punya baris master, dengan nomor urut yang
     * MELANJUTKAN dokumen yang sudah ada. Aman diulang.
     *
     * @return array<string,int> kode => nomor urut awal yang ditanam
     */
    public static function tanamMaster(int $tenantId): array
    {
        $hasil = [];
        $periode = Carbon::now()->format('Y');   // seluruh katalog memakai reset tahunan

        foreach (BoDocumentNumbers::all() as $code => $d) {
            $urut = $d['tabel'] && $d['kolom']
                ? self::urutTertinggi($code, $d['tabel'], $d['kolom'], $d['prefix'])
                : 0;

            $ada = Numerator::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('scope', BoDocumentNumbers::SCOPE)
                ->where('code', $code)->first();

            if ($ada) {
                // Baris yang sudah ada TIDAK ditimpa — formatnya mungkin sudah disunting
                // operator, dan menimpanya akan membatalkan pengaturannya diam-diam.
                $hasil[$code] = (int) $ada->current_seq;

                continue;
            }

            Numerator::create([
                'tenant_id' => $tenantId, 'project_id' => null, 'scope' => BoDocumentNumbers::SCOPE,
                'code' => $code, 'prefix' => $d['prefix'], 'format' => $d['format'],
                'seq_padding' => $d['padding'], 'reset_strategy' => $d['reset'],
                'current_period' => $periode, 'current_seq' => $urut,
            ]);
            $hasil[$code] = $urut;
        }

        return $hasil;
    }
}
