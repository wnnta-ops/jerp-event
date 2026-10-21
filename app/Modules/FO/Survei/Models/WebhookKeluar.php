<?php

namespace App\Modules\FO\Survei\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu notifikasi webhook keluar (lihat migrasi create_webhook_keluar_table). */
class WebhookKeluar extends Model
{
    protected $table = 'webhook_keluar';

    protected $guarded = [];

    protected $casts = [
        'terjadi_pada' => 'datetime',
        'terkirim_pada' => 'datetime',
    ];

    public const STATUS = [
        'antre' => 'Menunggu / diulang',
        'terkirim' => 'Terkirim',
        'ditolak' => 'Ditolak penerima',
        'gagal' => 'Gagal (percobaan habis)',
    ];
}
