<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Import;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashFlowCategory;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use Illuminate\Support\Facades\DB;

/**
 * Impor Chart of Account.
 *
 * Dipakai BERSAMA oleh tombol Import di layar dan perintah `coa:impor`, supaya aturan
 * pemeriksaannya cuma ada satu. Bila keduanya punya aturan sendiri-sendiri, berkas yang
 * ditolak layar bisa lolos lewat perintah — dan bagan akun adalah master yang paling
 * ditumpangi seluruh modul.
 *
 * Bentuk berkasnya mengikuti lampiran "COA Aktiva": satu baris per akun, dari level 1 sampai
 * 6, dengan kode yang panjangnya menentukan tingkatannya (2 x level − 1 digit).
 */
final class CoaImporter
{
    /** Kolom berkas impor, urutannya mengikuti lampiran. */
    public const KOLOM = ['level', 'kode', 'akun', 'nama_level', 'tipe', 'normal', 'postable',
        'cost_center', 'cash_flow_direct', 'cash_flow_indirect'];

    /** Nama tingkatan → dipakai memeriksa kolom "nama_level" bila diisi. */
    public const LEVEL = [1 => 'Kategori', 2 => 'Sub Kategori', 3 => 'Ledger',
        4 => 'Detil', 5 => 'Detil Transaksi', 6 => 'Detil Sub Transaksi'];

    /** Tulisan tipe pada lampiran → nilai kolom `type`. */
    private const TIPE = [
        'aktiva' => 'asset', 'asset' => 'asset',
        'kewajiban' => 'liability', 'pasiva' => 'liability', 'liability' => 'liability',
        'ekuitas' => 'equity', 'modal' => 'equity', 'equity' => 'equity',
        'pendapatan' => 'income', 'income' => 'income',
        'beban' => 'expense', 'biaya' => 'expense', 'expense' => 'expense',
    ];

    /**
     * Ubah baris mentah menjadi akun siap simpan.
     *
     * SELURUH berkas diperiksa; galat dikumpulkan, tidak dilempar di baris pertama, supaya
     * operator bisa membetulkan semuanya sekaligus alih-alih menemukannya satu per satu.
     *
     * @param  array<int, array<int, string>>  $baris  baris CSV TANPA baris judul
     * @return array{akun: array<int, array<string, mixed>>, galat: array<int, string>}
     */
    public function baca(array $baris): array
    {
        $kodeCc  = BsCostCenter::pluck('id', 'code');
        $kodeCf  = BsCashFlowCategory::pluck('id', 'code');
        $akun    = [];
        $galat   = [];
        $adaKode = [];

        foreach ($baris as $i => $b) {
            $no = $i + 2;   // +1 karena 0-indeks, +1 karena baris judul
            $b  = array_pad(array_map(fn ($x) => trim((string) $x), $b), count(self::KOLOM), '');
            [$lvl, $kode, $nama, $namaLevel, $tipe, $normal, $postable, $cc, $cfd, $cfi] = $b;

            if ($kode === '' && $nama === '') {
                continue;   // baris kosong
            }

            $level = (int) $lvl;
            if ($level < 1 || $level > 6) {
                $galat[] = "Baris {$no}: level \"{$lvl}\" harus 1 sampai 6.";

                continue;
            }
            if (! ctype_digit($kode)) {
                $galat[] = "Baris {$no}: kode akun \"{$kode}\" hanya boleh berisi angka.";

                continue;
            }
            if (strlen($kode) !== 2 * $level - 1) {
                $galat[] = "Baris {$no}: kode level {$level} (".self::LEVEL[$level].') harus '
                    .(2 * $level - 1)." digit, bukan ".strlen($kode).'.';

                continue;
            }
            if ($nama === '') {
                $galat[] = "Baris {$no}: nama akun kosong.";

                continue;
            }
            if ($namaLevel !== '' && $namaLevel !== '180' && mb_strtolower($namaLevel) !== mb_strtolower(self::LEVEL[$level])) {
                $galat[] = "Baris {$no}: kolom nama level \"{$namaLevel}\" tidak cocok dengan level {$level} (".self::LEVEL[$level].').';

                continue;
            }

            // Kolom Tipe pada baris header lampiran berisi angka sisa format (mis. "180").
            // Angka semacam itu diperlakukan KOSONG, lalu tipenya diturunkan dari induknya —
            // menyalinnya apa adanya akan membuat akun header bertipe sampah.
            $tipeKunci = mb_strtolower($tipe);
            $tipeAkun  = self::TIPE[$tipeKunci] ?? null;
            if ($tipe !== '' && $tipeAkun === null && ! ctype_digit($tipe)) {
                $galat[] = "Baris {$no}: tipe \"{$tipe}\" tidak dikenali (Aktiva/Kewajiban/Ekuitas/Pendapatan/Beban).";

                continue;
            }

            // Kolom Cost Center pada lampiran DITERIMA supaya berkasnya bisa dipakai apa adanya,
            // tetapi tidak disimpan: bs_accounts memang tidak punya kolom itu. Isinya tetap
            // diperiksa agar salah ketik ketahuan, bukan hilang diam-diam.
            if ($cc !== '' && ! ctype_digit($cc) && ! $kodeCc->has($cc)) {
                $galat[] = "Baris {$no}: cost center \"{$cc}\" tidak ada di master.";

                continue;
            }

            $cfdId = null;
            if ($cfd !== '' && ! ctype_digit($cfd)) {
                if (! $kodeCf->has($cfd)) {
                    $galat[] = "Baris {$no}: kategori cash flow direct \"{$cfd}\" tidak ada.";

                    continue;
                }
                $cfdId = $kodeCf[$cfd];
            }
            $cfiId = null;
            if ($cfi !== '' && ! ctype_digit($cfi)) {
                if (! $kodeCf->has($cfi)) {
                    $galat[] = "Baris {$no}: kategori cash flow indirect \"{$cfi}\" tidak ada.";

                    continue;
                }
                $cfiId = $kodeCf[$cfi];
            }

            if (isset($adaKode[$kode])) {
                $galat[] = "Baris {$no}: kode {$kode} muncul dua kali di berkas ini.";

                continue;
            }
            $adaKode[$kode] = true;

            $akun[] = [
                'level'          => $level,
                'code'           => $kode,
                'name'           => $nama,
                'type'           => $tipeAkun,
                'normal_balance' => in_array(mb_strtoupper($normal), ['D', 'K'], true) ? mb_strtoupper($normal) : null,
                // Level 6 adalah satu-satunya tingkat yang dijurnal. Tanda centang pada kolom
                // Postable dihormati, tetapi levelnya yang menentukan — begitulah bagan akun
                // ini disusun, dan dua sumber kebenaran untuk hal yang sama hanya bikin ragu.
                'is_postable'    => $level === 6,
                'is_active'      => true,
                'parent_code'    => $level > 1 ? substr($kode, 0, strlen($kode) - 2) : null,
                'cf_direct_category_id'   => $cfdId,
                'cf_indirect_category_id' => $cfiId,
            ];
        }

        // Induk harus ada — di berkas ini ATAU sudah ada di bagan akun. Tanpa itu, akun yang
        // ditulis akan menggantung tanpa induk dan hierarkinya putus tanpa pemberitahuan.
        if ($akun !== []) {
            $adaDb = BsAccount::whereIn('code', array_values(array_filter(array_column($akun, 'parent_code'))))
                ->pluck('code')->flip();
            foreach ($akun as $a) {
                if ($a['parent_code'] !== null && ! isset($adaKode[$a['parent_code']]) && ! $adaDb->has($a['parent_code'])) {
                    $galat[] = "Kode {$a['code']}: induknya ({$a['parent_code']}) tidak ada di berkas maupun di bagan akun.";
                }
            }
        }

        // Induk selalu ditulis sebelum anaknya.
        usort($akun, fn ($x, $y) => $x['level'] <=> $y['level'] ?: strcmp($x['code'], $y['code']));

        return ['akun' => $akun, 'galat' => $galat];
    }

    /**
     * Tulis ke bagan akun. Dicocokkan lewat KODE: yang ada diperbarui, yang belum dibuat.
     *
     * Seluruhnya dalam SATU transaksi — impor yang berhenti separuh jalan meninggalkan bagan
     * akun setengah berubah, dan itu jauh lebih sulit dibereskan daripada mengulang impornya.
     *
     * @param  array<int, array<string, mixed>>  $akun
     * @return array{baru: int, ubah: int}
     */
    public function tulis(array $akun): array
    {
        $baru = 0;
        $ubah = 0;

        DB::transaction(function () use ($akun, &$baru, &$ubah): void {
            foreach ($akun as $a) {
                $induk = $a['parent_code'] !== null
                    ? BsAccount::where('code', $a['parent_code'])->first()
                    : null;

                $isi = [
                    'code'          => $a['code'],
                    'name'          => $a['name'],
                    'level'         => $a['level'],
                    'parent_id'     => $induk?->id,
                    'parent_code'   => $induk?->code,
                    'category_code' => BsAccount::categoryOf($a['code']),
                    // Tipe & saldo normal diturunkan dari induk bila barisnya tidak menyebutkan —
                    // baris header pada lampiran memang mengosongkannya.
                    'type'           => $a['type'] ?? $induk?->type ?? 'asset',
                    'normal_balance' => $a['normal_balance'] ?? $induk?->normal_balance ?? 'D',
                    'is_postable'    => $a['is_postable'],
                    'is_active'      => $a['is_active'],
                ];
                foreach (['cf_direct_category_id', 'cf_indirect_category_id'] as $k) {
                    if ($a[$k] !== null) {
                        $isi[$k] = $a[$k];
                    }
                }

                $ada = BsAccount::where('code', $a['code'])->first();
                if ($ada) {
                    $ubah++;
                    $ada->update($isi);
                } else {
                    $baru++;
                    BsAccount::create($isi);
                }
            }
        });

        return ['baru' => $baru, 'ubah' => $ubah];
    }

    /**
     * Baca CSV mentah menjadi baris, membuang BOM dan baris judul bila ada.
     *
     * @return array<int, array<int, string>>
     */
    public function csv(string $isiBerkas): array
    {
        $bom   = chr(0xEF).chr(0xBB).chr(0xBF);
        $isi   = str_replace($bom, '', $isiBerkas);
        $baris = array_values(array_filter(
            array_map(fn ($b) => str_getcsv($b, ',', chr(34), ''), preg_split('/\r\n|\r|\n/', $isi) ?: []),
            fn ($b) => $b !== [null] && implode('', array_map('strval', $b)) !== '',
        ));

        if ($baris !== []) {
            $kepala = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $baris[0]);
            if (in_array('kode', $kepala, true) || in_array('level', $kepala, true)) {
                array_shift($baris);
            }
        }

        return $baris;
    }
}
