<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris log status jurnal (append-only). Satu baris per transisi status; tidak pernah
 * di-update/dihapus sehingga riwayat approve/batal approve/reject tetap utuh.
 */
class BsJournalStatusLog extends Model
{
    protected $table = 'bs_journal_status_logs';

    public $timestamps = false; // hanya created_at, diisi manual saat pencatatan

    protected $fillable = ['tenant_id', 'journal_id', 'action', 'from_status', 'to_status', 'user_id', 'note', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function journal(): BelongsTo { return $this->belongsTo(BsJournal::class, 'journal_id'); }

    public function user(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'user_id'); }
}
