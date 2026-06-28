<?php

declare(strict_types=1);

namespace App\Domain\Security\Models;

use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecAttendance extends Model
{
    use HasTenant;

    protected $table = 'sec_attendances';
    protected $fillable = ['tenant_id', 'employee_id', 'date', 'clock_in', 'clock_out', 'status', 'note', 'created_by'];
    protected $casts = ['date' => 'date'];

    public const STATUSES = ['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa', 'cuti' => 'Cuti', 'dinas' => 'Dinas Luar'];

    public function employee(): BelongsTo { return $this->belongsTo(BsEmployee::class, 'employee_id'); }
}
