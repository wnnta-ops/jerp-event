<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** COGS Realisasi (Saldo Awal Realisasi + realisasi budget). */
class BsCogsRealization extends Model
{
    use HasTenant;

    protected $table = 'bs_cogs_realizations';
    protected $fillable = [
        'tenant_id', 'doc_no', 'year', 'project_id', 'location_id', 'category_id', 'account_id',
        'realization_date', 'amount', 'type', 'note', 'created_by',
    ];
    protected $casts = ['year' => 'integer', 'amount' => 'decimal:2', 'realization_date' => 'date'];

    public function category(): BelongsTo { return $this->belongsTo(BsCogsCategory::class, 'category_id'); }
    public function location(): BelongsTo { return $this->belongsTo(BsCogsLocation::class, 'location_id'); }
}
