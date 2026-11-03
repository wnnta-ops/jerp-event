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

    /**
     * Tahap approval setelah pengaju (Dept Head): level => [role approver, label tahap].
     * Dipakai ApprovalInboxSeeder untuk membuat chain step & oleh inbox untuk label tracker.
     */
    public const STEPS = [
        1 => ['Accounting', 'Accounting'],
        2 => ['Finance', 'Finance'],
        3 => ['GM', 'GM'],
        4 => ['Direktur', 'Direktur'],
        5 => ['Owner', 'MW / BA'],
    ];

    protected $fillable = [
        'tenant_id', 'memo_number', 'memo_date', 'department', 'supplier_id',
        'subject', 'body', 'amount', 'status', 'approval_id', 'created_by',
    ];

    protected $casts = [
        'memo_date' => 'date',
        'amount' => 'decimal:2',
    ];

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
