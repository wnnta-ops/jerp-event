<?php

declare(strict_types=1);

namespace App\Domain\Sales\Contract\Models;

use App\Domain\Sales\Contract\Enums\DocumentType;
use App\Domain\Sales\Contract\Enums\PpjbStatus;
use App\Support\Traits\HasAuditTrail;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasAuditTrail, HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'booking_id', 'contract_number', 'signed_at', 'cancelled_at',
        'status', 'template_id', 'snapshot', 'notes', 'created_by',

        // ── Phase 4: PPJB lifecycle
        'ppjb_status',
        'ppjb_system_number', 'ppjb_manual_number', 'ppjb_use_manual_number',
        'ppjb_date', 'ppjb_printed_at', 'ppjb_signed_at',
    ];

    protected $casts = [
        'signed_at'              => 'date',
        'snapshot'               => 'array',
        'ppjb_status'            => PpjbStatus::class,
        'ppjb_use_manual_number' => 'boolean',
        'ppjb_date'              => 'date',
        'ppjb_printed_at'        => 'date',
        'ppjb_signed_at'         => 'date',
    ];

    // ── Relationships ─────────────────────────────────────

    public function booking(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class);
    }

    public function clauses(): HasMany
    {
        return $this->hasMany(ContractClause::class)->orderBy('clause_no');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function gimmicks(): HasMany
    {
        return $this->hasMany(\App\Domain\Sales\Pricing\Models\ContractGimmick::class)
            ->with('gimmick')
            ->orderBy('id');
    }

    /**
     * Total marketing cost (sum total_harga semua gimmicks diberikan).
     */
    public function getTotalGimmickCostAttribute(): float
    {
        return (float) $this->gimmicks()->where('diberikan', true)->sum('total_harga');
    }

    // ── PPJB lifecycle helpers ────────────────────────────

    /**
     * Nomor PPJB effective (manual atau system).
     */
    public function getEffectivePpjbNumberAttribute(): ?string
    {
        return $this->ppjb_use_manual_number
            ? $this->ppjb_manual_number
            : $this->ppjb_system_number;
    }

    /**
     * Apakah cara_bayar = KPR (untuk conditional tab UI)?
     */
    public function getIsKprAttribute(): bool
    {
        $scheme = $this->booking?->paymentScheme;
        return $scheme?->type === 'kpr' || str_contains(strtolower((string) $scheme?->name), 'kpr');
    }

    // ── Document Compliance ───────────────────────────────

    /**
     * Cek apakah semua dokumen wajib untuk action sudah `is_present`.
     *
     * @return array{complete: bool, missing: array<int, DocumentType>}
     */
    public function checkDocumentCompliance(string $action = 'ppjb_sign'): array
    {
        $required = DocumentType::requiredFor($action);
        $missing  = [];

        foreach ($required as $type) {
            $doc = $this->documents->where('document_type', $type)->first();
            if (! $doc || ! $doc->is_present) {
                $missing[] = $type;
            }
        }

        return [
            'complete' => empty($missing),
            'missing'  => $missing,
        ];
    }

    /**
     * Ensure semua document row exists (initialize jika belum).
     */
    public function ensureDocumentRows(): void
    {
        foreach (DocumentType::cases() as $type) {
            ContractDocument::firstOrCreate(
                ['contract_id' => $this->id, 'document_type' => $type->value],
                ['is_present' => false],
            );
        }
    }
}
