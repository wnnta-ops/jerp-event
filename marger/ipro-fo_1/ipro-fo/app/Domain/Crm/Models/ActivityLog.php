<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\ActivityCategory;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CRM Activity Log (eProperty "Jurnal").
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property ActivityCategory $category
 * @property string $description
 */
class ActivityLog extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'subject_type', 'subject_id',
        'category', 'description', 'logged_at', 'logged_by',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
    ];

    protected $casts = [
        'category'  => ActivityCategory::class,
        'logged_at' => 'datetime',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_id');
    }

    public function loggedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'logged_by');
    }
}
