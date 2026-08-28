<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsPurchaseOrder extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_purchase_orders';
    protected $fillable = [
        'tenant_id', 'project_id', 'supplier_id', 'material_request_id', 'po_number', 'po_date', 'due_date',
        'po_type', 'term_payment', 'subtotal', 'ppn_pct', 'ppn_amount', 'pph_pct', 'total', 'status',
        'dp_amount', 'dp_account_id', 'dp_journal_id', 'revision_count', 'revised_at',
        'notes', 'created_by', 'approved_at',
        'printed_at', 'print_count', 'printed_by', 'print_meta',
    ];
    protected $casts = [
        'po_date' => 'date', 'due_date' => 'date', 'approved_at' => 'datetime', 'revised_at' => 'datetime',
        'subtotal' => 'decimal:2', 'ppn_pct' => 'decimal:2', 'ppn_amount' => 'decimal:2',
        'pph_pct' => 'decimal:2', 'total' => 'decimal:2', 'dp_amount' => 'decimal:2',
        'revision_count' => 'integer',
        'printed_at' => 'datetime', 'print_count' => 'integer', 'print_meta' => 'array',
    ];

    public function lines(): HasMany { return $this->hasMany(BsPurchaseOrderLine::class, 'purchase_order_id'); }
    public function supplier(): BelongsTo { return $this->belongsTo(BsSupplier::class, 'supplier_id'); }

    /** Pembuat PO — dipakai mengisi kolom "Dibuat Oleh" pada lembar cetak. */
    public function creator(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'created_by'); }
    public function project(): BelongsTo { return $this->belongsTo(\App\Domain\Foundation\Tenant\Models\Project::class, 'project_id'); }
}
