<?php

namespace App\Modules\FO\Adminjual\Models;

use App\Models\Concerns\TenantAwareModel;
use Illuminate\Support\Str;

/**
 * Master "Sumber Data" — pilihan dari mana pemesan tahu produk (Instagram, Website,
 * Referral, dst). Dipakai di dropdown form pendaftaran NUP & Booked. Bisa ditambah
 * sendiri dari menu Setup Sales tanpa ubah kode.
 */
class SumberData extends TenantAwareModel
{
    protected $table = 'fo_sumber_data';

    protected $fillable = [
        'tenant_id', 'kode', 'nama', 'urutan', 'is_active', 'keterangan',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'urutan' => 'integer',
    ];

    protected static function booted(): void
    {
        parent::booted();
        // Kode (nilai tersimpan) otomatis dari nama bila dikosongkan.
        static::saving(function (self $m) {
            if (blank($m->kode)) {
                $m->kode = self::kodeDari($m->nama);
            }
        });
    }

    /** Kode stabil dari nama: huruf besar, non-alfanumerik → underscore. */
    public static function kodeDari(?string $nama): string
    {
        $kode = Str::upper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $nama), '_'));

        return $kode !== '' ? $kode : 'LAIN';
    }
}
