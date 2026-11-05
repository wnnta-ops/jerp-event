<?php

namespace App\Modules\FO\Launching\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup Pilih Unit per cluster — giliran HP & batas waktu memilih.
 * Dikelola di Setup Pilih Unit, dibaca halaman Pilih Unit (PilihUnitGiliran).
 */
class PilihUnitSetup extends TenantAwareModel
{
    protected $table = 'fo_pilih_unit_setup';

    protected $fillable = [
        'tenant_id', 'lokasi_id', 'giliran_aktif', 'jumlah_hp', 'giliran_hp', 'timer_aktif', 'timer_menit',
    ];

    protected $casts = [
        'giliran_aktif' => 'boolean',
        'timer_aktif' => 'boolean',
        'jumlah_hp' => 'integer',
        'giliran_hp' => 'integer',
        'timer_menit' => 'integer',
    ];

    protected static function booted(): void
    {
        parent::booted();

        // Jumlah HP dikurangi sampai di bawah giliran yang berjalan → giliran kembali ke HP 1.
        static::saving(function (self $s) {
            if ($s->giliran_hp < 1 || $s->giliran_hp > $s->jumlah_hp) {
                $s->giliran_hp = 1;
            }
        });
    }

    public function lokasi(): BelongsTo
    {
        return $this->belongsTo(Lokasi::class);
    }
}
