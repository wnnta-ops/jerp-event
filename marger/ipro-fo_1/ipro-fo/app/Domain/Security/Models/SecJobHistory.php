<?php

declare(strict_types=1);

namespace App\Domain\Security\Models;

use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecJobHistory extends Model
{
    use HasTenant;

    protected $table = 'sec_job_histories';
    protected $fillable = ['tenant_id', 'employee_id', 'position', 'division', 'start_date', 'end_date', 'note', 'created_by'];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date'];

    public function employee(): BelongsTo { return $this->belongsTo(BsEmployee::class, 'employee_id'); }
}
