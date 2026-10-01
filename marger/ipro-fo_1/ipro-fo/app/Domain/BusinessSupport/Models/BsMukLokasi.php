<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Master\Unit\Models\Unit;
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
        'unit_id', 'dipasangkan_oleh', 'dipasangkan_pada', 'ditarik_pada',
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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
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
            // "… VI No. 15" / "… VI NO 15" / "… VI-15" — angka Romawi lalu nomor rumah.
            if (preg_match('/\b([IVXLC]+)\b[\s-]*(?:No\.?|NO\.?)?\s*([0-9]+[A-Za-z]?)\s*$/i', (string) $this->nama, $m) === 1) {
                $blok ??= strtoupper($m[1]);
                $no ??= strtoupper($m[2]);
            }
        }

        return ['blok' => $blok, 'no_rumah' => $no];
    }
}
