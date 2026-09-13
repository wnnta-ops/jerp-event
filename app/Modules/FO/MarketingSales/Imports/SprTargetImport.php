<?php

namespace App\Modules\FO\MarketingSales\Imports;

use App\Modules\FO\MarketingSales\Models\SprTarget;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import Target SPR per bulan — satu baris per SKENARIO.
 *
 *   Tahun | Skenario                     | Jan | Feb | Mar | ... | Des
 *   2026  | Target (WPnB)                | 100 |  73 |  45 | ... |  33
 *   2026  | Adjust 1 (4&5 Feb 26)        |  58 |  73 |  57 | ... |  60
 *
 * Nama skenario diambil apa adanya dan menjadi label garis di grafik, jadi menambah revisi
 * target cukup menambah BARIS — tidak ada kolom tetap yang harus ditambah di kode.
 *
 * Impor ulang MEMPERBARUI baris dengan (tahun + skenario) yang sama, bukan menumpuknya:
 * tanpa itu, revisi yang diunggah dua kali muncul sebagai dua garis kembar di grafik.
 */
class SprTargetImport implements ToCollection, WithHeadingRow
{
    use Importable;

    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /** @var array<int,string> */
    public array $errors = [];

    /**
     * Ejaan kolom bulan yang diterima. Operator menulis "Jan", "Januari", atau "JANUARI"
     * tergantung siapa yang membuat berkasnya; menolak salah satunya berarti berkas yang
     * isinya benar ditolak karena huruf.
     */
    private const BULAN_KUNCI = [
        1  => ['jan', 'januari', 'january'],
        2  => ['feb', 'februari', 'february', 'pebruari'],
        3  => ['mar', 'maret', 'march'],
        4  => ['apr', 'april'],
        5  => ['mei', 'may'],
        6  => ['jun', 'juni', 'june'],
        7  => ['jul', 'juli', 'july'],
        8  => ['ags', 'agu', 'agt', 'agustus', 'aug', 'august'],
        9  => ['sep', 'sept', 'september'],
        10 => ['okt', 'oct', 'oktober', 'october'],
        11 => ['nov', 'nop', 'november', 'nopember'],
        12 => ['des', 'dec', 'desember', 'december'],
    ];

    public function __construct(
        private readonly int $tenantId = 1,
        private readonly ?int $userId = null,
    ) {}

    public function headingRow(): int
    {
        return 1;
    }

    public function collection(Collection $rows): void
    {
        /*
         * Urutan HANYA diberikan kepada skenario BARU, melanjutkan nomor terbesar yang sudah
         * ada. Skenario yang sudah tersimpan mempertahankan urutannya.
         *
         * Kalau urutan ikut ditulis ulang setiap impor, mengunggah satu skenario saja — hal
         * yang wajar dilakukan ketika satu revisi target diperbaiki — akan melompatkannya ke
         * urutan pertama. Akibatnya legenda grafik berubah susunannya, dan keterangan di bawah
         * judul ("Target tahunan … unit") tiba-tiba menyebut skenario yang salah sebagai target
         * induk. Tidak ada galat apa pun yang muncul; angkanya sekadar berpindah arti.
         */
        $urutan = 0;

        foreach ($rows as $i => $row) {
            $noBaris = $i + 2; // +1 heading, +1 karena indeks mulai 0

            $skenario = trim((string) ($row['skenario'] ?? $row['nama'] ?? $row['target'] ?? ''));
            $tahun    = (int) ($row['tahun'] ?? $row['year'] ?? 0);

            if ($skenario === '' && $tahun === 0) {
                continue; // baris kosong di ujung sheet — bukan kesalahan
            }
            if ($skenario === '') {
                $this->skipped++;
                $this->errors[] = "Baris {$noBaris}: kolom Skenario kosong.";

                continue;
            }
            if ($tahun < 2000 || $tahun > 2100) {
                $this->skipped++;
                $this->errors[] = "Baris {$noBaris} ({$skenario}): kolom Tahun tidak masuk akal — terbaca '".($row['tahun'] ?? '')."'.";

                continue;
            }

            [$bulan, $ketemu] = $this->bulanDariBaris($row);
            if ($ketemu === 0) {
                $this->skipped++;
                $this->errors[] = "Baris {$noBaris} ({$skenario}): tidak ada satu pun kolom bulan yang dikenali. "
                    ."Judul kolomnya harus Jan…Des (atau Januari…Desember).";

                continue;
            }
            if ($ketemu < 12) {
                // Bukan alasan menolak: sebagian perusahaan hanya menargetkan separuh tahun.
                // Tapi harus DIBERITAHUKAN, karena bulan yang hilang tersimpan sebagai 0 dan
                // di grafik tidak bisa dibedakan dari target nol yang disengaja.
                $this->errors[] = "Baris {$noBaris} ({$skenario}): hanya {$ketemu} kolom bulan yang dikenali, sisanya dianggap 0.";
            }

            $atribut = ['updated_by' => $this->userId];
            foreach ($bulan as $m => $v) {
                $atribut['m'.$m] = $v;
            }

            // withoutGlobalScopes: impor bisa dijalankan dari konteks yang TenantContext-nya
            // belum di-set (perintah artisan, pengujian). Tanpa ini, query pencarian pulang
            // kosong TANPA galat dan setiap impor ulang membuat baris baru.
            $ada = SprTarget::query()->withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('tahun', $tahun)
                ->where('skenario', $skenario)
                ->first();

            if ($ada) {
                $ada->forceFill($atribut)->save();   // urutan sengaja tidak ikut diubah
                $this->updated++;

                continue;
            }

            if ($urutan === 0) {
                $urutan = (int) SprTarget::query()->withoutGlobalScopes()
                    ->where('tenant_id', $this->tenantId)->where('tahun', $tahun)->max('urutan');
            }
            $urutan++;

            SprTarget::query()->withoutGlobalScopes()->create($atribut + [
                'tenant_id' => $this->tenantId,
                'tahun' => $tahun,
                'skenario' => $skenario,
                'urutan' => $urutan,
                'created_by' => $this->userId,
            ]);
            $this->created++;
        }
    }

    /**
     * @return array{0: array<int,int>, 1: int} nilai bulan 1..12 dan berapa kolom yang dikenali
     */
    private function bulanDariBaris(Collection $row): array
    {
        $bulan  = array_fill(1, 12, 0);
        $ketemu = 0;

        foreach (self::BULAN_KUNCI as $m => $ejaan) {
            $kandidat = array_merge($ejaan, ['m'.$m, 'bulan_'.$m, (string) $m]);
            foreach ($kandidat as $k) {
                if (! $row->has($k)) {
                    continue;
                }
                $bulan[$m] = $this->angka($row[$k]);
                $ketemu++;
                break;
            }
        }

        return [$bulan, $ketemu];
    }

    /** Sel kosong, '-', dan teks tak bernilai sama-sama berarti nol unit. */
    private function angka(mixed $v): int
    {
        if ($v === null || $v === '' || $v === '-') {
            return 0;
        }
        if (is_numeric($v)) {
            return (int) round((float) $v);
        }

        return (int) round((float) preg_replace('/[^\d.-]/', '', (string) $v));
    }
}
