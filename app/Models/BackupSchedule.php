<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Satu jadwal backup (frekuensi/jam/lokasi sendiri). Scheduler loop yang enabled. */
class BackupSchedule extends Model
{
    protected $table = 'backup_schedules';

    protected $fillable = [
        'name', 'enabled', 'frequency', 'time',
        'weekly_day', 'monthly_day', 'yearly_month', 'yearly_day',
        'only_db', 'disk', 'local_path', 'retention_days',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'only_db' => 'boolean',
        'weekly_day' => 'integer',
        'monthly_day' => 'integer',
        'yearly_month' => 'integer',
        'yearly_day' => 'integer',
        'retention_days' => 'integer',
    ];
}
