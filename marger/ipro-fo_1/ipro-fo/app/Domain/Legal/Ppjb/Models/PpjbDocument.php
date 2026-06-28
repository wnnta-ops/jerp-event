<?php

declare(strict_types=1);

namespace App\Domain\Legal\Ppjb\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PpjbDocument extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'contract_id', 'ppjb_number', 'signed_at', 'file_path', 'status'];
    protected $casts    = ['signed_at' => 'date'];

    public function contract(): BelongsTo { return $this->belongsTo(\App\Domain\Sales\Contract\Models\Contract::class); }
}
