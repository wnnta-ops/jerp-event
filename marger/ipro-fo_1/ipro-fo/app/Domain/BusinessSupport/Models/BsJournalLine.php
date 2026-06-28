<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BsJournalLine extends Model
{
    protected $table = 'bs_journal_lines';
    protected $fillable = ['journal_id', 'account_id', 'cost_center_id', 'department_id', 'ledger_id', 'location_id', 'currency', 'kurs', 'description', 'debit', 'credit'];
    protected $casts = ['debit' => 'decimal:2', 'credit' => 'decimal:2', 'kurs' => 'decimal:6'];

    public function journal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'journal_id'); }
    public function account(): BelongsTo { return $this->belongsTo(BsAccount::class, 'account_id'); }
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }
    public function department(): BelongsTo { return $this->belongsTo(BsDepartment::class, 'department_id'); }
    public function ledger(): BelongsTo { return $this->belongsTo(BsLedger::class, 'ledger_id'); }
    public function location(): BelongsTo { return $this->belongsTo(BsLocation::class, 'location_id'); }
}
