<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu lokasi milik satu kontrak MUK, beserta progres dan pasangan unitnya.
 *
 * Alasan setiap kolomnya ada di migrasi 2026_10_01_110000. Ringkasnya: inilah lapisan yang
 * membuat opname dan tagihan dari MUK bisa dibaca per unit.
 */
class BsMukLokasi extends Model
{
    use HasTenant;

    protected $table = 'bs_muk_lokasi';

    protected $fillable = [
        'tenant_id', 'muk_id', 'no_kontrak', 'lokasi_id', 'lokasi_kode',
        'nama', 'tipe_rumah', 'ukuran', 'blok', 'no_rumah', 'baris_kontrak',
        'nilai_kontrak', 'opname_nilai', 'opname_persen', 'opname_terakhir',
        'ditagih_nilai', 'ditagih_persen', 'belum_ditagih', 'retensi_nilai',
        'ditarik_pada',
    ];

    protected $casts = [
        'opname_terakhir'  => 'date',
        'dipasangkan_pada' => 'datetime',
        'ditarik_pada'     => 'datetime',
        'nilai_kontrak'    => 'decimal:2',
        'opname_nilai'     => 'decimal:2',
        'opname_persen'    => 'decimal:2',
        'ditagih_nilai'    => 'decimal:2',
        'ditagih_persen'   => 'decimal:2',
        'belum_ditagih'    => 'decimal:2',
        'retensi_nilai'    => 'decimal:2',
    ];

    /**
     * Pasangan unitnya - lewat lokasi_kode, bukan kolom di baris ini.
     *
     * Satu rumah muncul di beberapa kontrak dengan lokasi_id berbeda tetapi kode yang
     * sama; pasangannya diputuskan sekali untuk kodenya. Lihat migrasi 2026_10_01_120000.
     */
    public function pasangan(): BelongsTo
    {
        return $this->belongsTo(BsMukUnitPasangan::class, 'lokasi_kode', 'lokasi_kode');
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(BsMukContract::class, 'muk_id', 'muk_id');
    }

    /**
     * Blok & nomor rumah yang DITEBAK dari nama, dipakai hanya untuk MENGUSULKAN pasangan.
     *
     * MUK mengirim kolom blok/no_rumah dalam keadaan kosong (diperiksa 1 Okt 2026), padahal
     * keduanya ada di dalam nama: "CENDANA JRKC2 VI No. 15". Yang dipulangkan di sini TIDAK
     * PERNAH disimpan sebagai kebenaran — ia hanya menyodorkan kandidat supaya operator tidak
     * memasangkan ratusan unit dari nol.
     *
     * @return array{blok: ?string, no_rumah: ?string}
     */
    public function tebakBlokNomor(): array
    {
        $blok = $this->blok ?: null;
        $no = $this->no_rumah ?: null;

        if ($blok === null || $no === null) {
            $nama = (string) $this->nama;

            // 1) "<BLOK> No. 15" - kata TEPAT SEBELUM "No." adalah bloknya, apa pun bentuknya.
            //    Blok di lapangan tidak selalu angka Romawi: "AKASIA JRK RAYA No. 22" bloknya
            //    "RAYA", dan 191 kode terlewat ketika hanya Romawi yang dikenali.
            //
            //    Kata itu WAJIB memuat huruf. Tanpa syarat ini "CLUSTER 5 No. 3" terbaca
            //    berblok "5" - padahal 5 itu nomor cluster, dan usulan pasangannya akan
            //    menunjuk rumah yang salah.
            if (preg_match('/([^\s]*[A-Za-z][^\s]*)\s+(?:No\.?|NO\.?)\s*([0-9]+[A-Za-z]?)\s*$/u', $nama, $m) === 1) {
                $blok ??= mb_strtoupper($m[1]);
                $no ??= mb_strtoupper($m[2]);
            }
            // 2) Cadangan tanpa kata "No.": "VI-15" / "VI 15". Dibatasi Romawi supaya tidak
            //    menyambar potongan nama yang kebetulan berakhiran angka.
            elseif (preg_match('/\b([IVXLC]+)\b[\s-]*([0-9]+[A-Za-z]?)\s*$/i', $nama, $m) === 1) {
                $blok ??= mb_strtoupper($m[1]);
                $no ??= mb_strtoupper($m[2]);
            }
        }

        return ['blok' => $blok, 'no_rumah' => $no];
    }
}
