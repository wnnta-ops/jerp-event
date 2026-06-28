<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * #98 — SIP (Standing Instruction Payment): header instruksi pembayaran per bank sumber.
 */
class BsSip extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_sips';
    protected $fillable = [
        'tenant_id', 'sip_number', 'sip_date', 'title', 'cash_account_id',
        'description', 'total', 'status', 'approvals', 'created_by',
        'rejection_reason', 'rejected_by', 'rejected_at', // #122
    ];
    protected $casts = [
        'sip_date'    => 'date',
        'total'       => 'decimal:2',
        'approvals'   => 'array',
        'rejected_at' => 'datetime',
    ];

    public const STATUSES = [
        'submitted' => 'Diajukan',
        'printed'   => 'Dicetak',          // #111: sudah dicetak, BELUM disetujui semua pihak
        'approved'  => 'Disetujui',        // #111: sudah disetujui SELURUH signer (SIGNERS)
        'rejected'  => 'Tidak Disetujui',  // #122: ditolak salah satu pihak — finance revisi
        'cancelled' => 'Dibatalkan',
    ];

    /**
     * 7 slot tanda tangan / approval — URUT (kiri→kanan di dokumen & urutan persetujuan).
     * NO108: Boy Arifin diposisikan di sebelah KANAN Moch Winarto.
     */
    public const SIGNERS = [
        'staf_finance'    => 'Staf Finance',
        'spv_finance'     => 'SPV Finance',
        'manager_finance' => 'Manager Finance',
        'gm'              => 'General Manager',
        'direksi'         => 'Direksi',
        'moch_winarto'    => 'Moch Winarto',
        'boy_arifin'      => 'Boy Arifin',
    ];

    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }

    public function lines(): HasMany { return $this->hasMany(BsSipLine::class, 'sip_id'); }

    /** #111: true bila SEMUA slot signer (SIGNERS) sudah menandatangani → status 'Disetujui'. */
    public function isFullyApproved(): bool
    {
        $a = $this->approvals ?? [];
        foreach (array_keys(self::SIGNERS) as $slot) {
            if (! isset($a[$slot])) {
                return false;
            }
        }

        return true;
    }
}
