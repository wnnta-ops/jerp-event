<?php

declare(strict_types=1);

namespace App\Domain\Collection\SuratPeringatan\Models;

use App\Domain\Approval\Models\Approval;
use App\Domain\Master\Customer\Models\Customer;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SuratPeringatan extends Model
{
    use HasTenant;

    protected $fillable = [
        'tenant_id', 'sp_number', 'customer_id', 'tunggakan_header_id',
        'level', 'issued_at', 'deadline_at', 'status', 'signed_by',
    ];

    protected $casts = [
        'issued_at'   => 'date',
        'deadline_at' => 'date',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tunggakan(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Collection\Tunggakan\Models\TunggakanHeader::class, 'tunggakan_header_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'signed_by');
    }

    /**
     * Approval(s) untuk SP ini (generic Approval domain, polymorphic).
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}
