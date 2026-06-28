<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankPartner extends Model
{
    use HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'code', 'name', 'branch', 'account_no', 'account_name',
        'address', 'city', 'notes', 'contact_person', 'phone', 'email', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }

    public function products(): HasMany { return $this->hasMany(BankProduct::class); }

    /** Ketentuan retensi per kategori untuk bank ini. */
    public function retentionTerms(): HasMany { return $this->hasMany(BankRetentionTerm::class); }
}
