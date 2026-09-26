<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris budget pada Set Up Kategori COGS (Tanah / Housing / Infrastruktur / License Permit).
 *
 * Satu tabel untuk empat komponen: bentuk barisnya nyaris sama dan kolom yang tidak dipakai
 * sebuah komponen dibiarkan NULL. Empat tabel terpisah berarti empat pengendali dan empat layar
 * yang isinya sama, dan perbedaan kecil di antaranya menjadi selisih yang tak pernah terlihat.
 */
class BsCogs2Kategori extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs2_kategori';

    protected $fillable = [
        'tenant_id', 'periode_id', 'komponen', 'lokasi', 'tahap',
        'kategori', 'no_spk', 'luas_tanah', 'harga_rata2', 'nominal', 'urut',
    ];

    protected $casts = [
        'periode_id'  => 'integer',
        'luas_tanah'  => 'decimal:2',
        'harga_rata2' => 'decimal:6',
        'nominal'     => 'decimal:2',
        'urut'        => 'integer',
    ];
}
