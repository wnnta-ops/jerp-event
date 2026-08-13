<?php

namespace App\Modules\FO\Launching\Models;

use App\Models\Concerns\TenantAwareModel;

/**
 * Setup Preferensi Unit — master pilihan "Preferensi Unit" pada form pendaftaran
 * NUP publik. Pendaftar memilih SATU saja; hasilnya tampil di menu NUP → Data
 * Preferensi Unit (nama customer, telepon, preferensi unit).
 */
class PreferensiUnit extends TenantAwareModel
{
    protected $table = 'fo_preferensi_unit';

    protected $fillable = [
        'tenant_id', 'nama', 'urutan', 'keterangan', 'is_active',
    ];

    protected $casts = [
        'urutan' => 'integer',
        'is_active' => 'boolean',
    ];
}
