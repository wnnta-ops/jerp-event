<?php

declare(strict_types=1);

namespace App\Domain\ApprovalInbox\Models;

use App\Domain\Approval\Models\Approval;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Models\User;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Memo Internal — diajukan staf/Dept Head, disetujui lewat rantai approval
 * 'memo_internal' (lihat ApprovalInboxSeeder untuk urutan tahapnya).
 *
 * @property int $id
 * @property string $memo_number
 * @property string $status diajukan | disetujui | ditolak
 * @property int|null $supplier_id
 * @property int|null $approval_id
 * @property int $created_by
 */
class MemoInternal extends Model
{
    use HasTenant, SoftDeletes;

    public const DOCUMENT_TYPE = 'memo_internal';

    /** Jenis memo — menentukan approval chain (match_conditions {"jenis": ...}) di System › Approval Chain. */
    public const JENIS = ['urgent' => 'Memo Urgent', 'reguler' => 'Memo Reguler'];

    /** Label tahap chain yang dipakai aturan pembayaran & cetakan (nama tahap di Approval Chain). */
    public const LABEL_DIREKTUR = 'Direktur';

    public const LABEL_MW_BA = 'MW / BA';

    /**
     * Tahap approval bawaan setelah pengaju (Dept Head): level => [role approver, label tahap].
     * Susunan yang berlaku diatur di System › Approval Chain; ini hanya untuk seeder & label
     * approval lama yang belum punya snapshot.
     */
    public const STEPS = [
        1 => ['Accounting', 'Accounting'],
        2 => ['Finance', 'Finance'],
        3 => ['GM', 'GM'],
        4 => ['Direktur', 'Direktur'],
        5 => ['Owner', 'MW / BA'],
    ];

    protected $fillable = [
        'tenant_id', 'memo_number', 'memo_date', 'jenis', 'department', 'supplier_id',
        'subject', 'body', 'amount', 'status', 'approval_id', 'created_by',
        'dpp', 'ppn_mode', 'ppn_pct', 'ppn_incl', 'ppn_base', 'pph_mode', 'pph_type', 'pph_pct', 'pph_base',
    ];

    /** Kolom rincian pajak (sama dengan tahap 2 Registrasi Invoice Hutang). */
    public const KOLOM_PAJAK = ['dpp', 'ppn_mode', 'ppn_pct', 'ppn_incl', 'ppn_base', 'pph_mode', 'pph_type', 'pph_pct', 'pph_base'];

    protected $casts = [
        'memo_date' => 'date',
        'amount' => 'decimal:2',
        'ppn_incl' => 'boolean',
    ];

    /**
     * Rincian pajak untuk mengisi (dan mengunci) tahap 2 invoice; null = memo lama tanpa rincian.
     *
     * @return array{dpp:float, ppn_mode:string, ppn_pct:float, ppn_incl:bool, ppn_base:float, pph_mode:string, pph_type:?string, pph_pct:float, pph_base:float}|null
     */
    public function pajak(): ?array
    {
        if ($this->dpp === null) {
            return null;
        }

        return [
            'dpp' => (float) $this->dpp, 'ppn_mode' => $this->ppn_mode ?? 'none', 'ppn_pct' => (float) $this->ppn_pct,
            'ppn_incl' => (bool) $this->ppn_incl, 'ppn_base' => (float) $this->ppn_base,
            'pph_mode' => $this->pph_mode ?? 'none', 'pph_type' => $this->pph_type, 'pph_pct' => (float) $this->pph_pct, 'pph_base' => (float) $this->pph_base,
        ];
    }

    /** Nama tahap sebuah approval step (snapshot chain; approval lama jatuh ke STEPS). */
    public static function labelTahap(?object $step): ?string
    {
        return $step ? ($step->label ?? (self::STEPS[(int) $step->level][1] ?? null)) : null;
    }

    /** Memo yang sudah lolos tahap Direktur — atau sudah disetujui penuh (chain tanpa tahap bernama Direktur). */
    public function scopeLewatDirektur($q)
    {
        return $q->where(fn ($w) => $w
            ->whereHas('approval', fn ($a) => $a->where('status', 'approved'))
            ->orWhereHas('approval.steps', fn ($s) => $s->where('status', 'approved')
                ->where(fn ($l) => $l->where('label', self::LABEL_DIREKTUR)
                    ->orWhere(fn ($x) => $x->whereNull('label')->where('level', 4)))));
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(BsSupplier::class, 'supplier_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
