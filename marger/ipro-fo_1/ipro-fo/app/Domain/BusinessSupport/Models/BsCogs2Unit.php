<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu unit pada Rincian COGS.
 *
 * SELURUH kolom budget (Tanah/Housing/Infrastruktur/License/Total) dan realisasi pada lampiran
 * adalah RUMUS, jadi tidak satu pun disimpan di sini. Yang disimpan hanya identitas unit, luas
 * tanah & luas bangunannya, status booked, pilihan costing, dan nilai booked revenue.
 *
 * `status` + `costing` menentukan apakah unit ini masih ikut menanggung realisasi berjalan:
 * HANYA unit SUDAH BOOKED yang memilih STANDARD COSTING yang berhenti menanggung — HPP-nya
 * sudah dikunci di angka budget. Sisanya, termasuk unit sudah booked yang memilih REALISASI
 * COSTING, tetap ikut menanggung, dan justru itulah yang membuat perhitungannya melingkar.
 */
class BsCogs2Unit extends Model
{
    use HasTenant;

    public const STATUS = ['belum' => 'Belum Booked', 'sudah' => 'Sudah Booked'];

    public const COSTING = [
        'belum'     => 'Standard Costing / Realisasi Costing',
        'standard'  => 'Standard Costing',
        'realisasi' => 'Realisasi Costing',
    ];

    protected $table = 'bs_cogs2_unit';

    protected $fillable = [
        'tenant_id', 'periode_id', 'no_spr', 'nama_customer', 'unit_rumah',
        'lokasi', 'tahap', 'tipe', 'lt', 'lb', 'status', 'costing', 'booked_revenue', 'urut',
    ];

    protected $casts = [
        'periode_id'     => 'integer',
        'lt'             => 'decimal:2',
        'lb'             => 'decimal:2',
        'booked_revenue' => 'decimal:2',
        'urut'           => 'integer',
    ];

    /** HPP-nya terkunci di angka budget: tidak lagi ikut menanggung realisasi berjalan. */
    public function getTerkunciAttribute(): bool
    {
        return $this->status === 'sudah' && $this->costing === 'standard';
    }
}
