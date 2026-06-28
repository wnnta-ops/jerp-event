<?php

declare(strict_types=1);

namespace App\Domain\Security\Models;

use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SecTechnicalProblem extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'sec_technical_problems';
    protected $fillable = [
        'tenant_id', 'ticket_no', 'title', 'description', 'module', 'priority', 'status',
        'reported_by', 'assigned_to', 'resolved_at',
    ];
    protected $casts = ['resolved_at' => 'date'];

    public const PRIORITIES = ['low' => 'Rendah', 'medium' => 'Sedang', 'high' => 'Tinggi'];
    public const STATUSES = ['open' => 'Open', 'in_progress' => 'Dikerjakan', 'resolved' => 'Selesai', 'closed' => 'Ditutup'];

    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reported_by'); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
}
