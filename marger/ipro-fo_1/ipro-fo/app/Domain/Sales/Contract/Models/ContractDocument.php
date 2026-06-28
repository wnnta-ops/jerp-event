<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Models;

use App\Domain\Sales\Contract\Enums\DocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $contract_id
 * @property DocumentType $document_type
 * @property bool $is_present
 * @property string|null $file_path
 */
class ContractDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contract_id', 'document_type', 'is_present',
        'file_path', 'original_name', 'mime_type', 'file_size',
        'notes', 'verified_at', 'verified_by', 'uploaded_by',
    ];

    protected $casts = [
        'document_type' => DocumentType::class,
        'is_present'    => 'boolean',
        'file_size'     => 'integer',
        'verified_at'   => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'uploaded_by');
    }
}
