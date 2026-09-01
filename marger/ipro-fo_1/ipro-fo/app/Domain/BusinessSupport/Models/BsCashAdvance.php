<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BsCashAdvance extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_cash_advances';
    protected $fillable = [
        'tenant_id', 'advance_number', 'advance_date', 'paid_date', 'holder', 'department_id', 'purpose', 'cash_account_id',
        'advance_account_id', 'amount', 'status', 'realized_date', 'realized_amount', 'settlement',
        'settlement_status', 'journal_id', 'realize_journal_id', 'settle_journal_id', 'created_by',
        'received_document_date', 'approvals', // #125, #127
    ];
    protected $casts = [
        'advance_date' => 'date', 'paid_date' => 'date', 'realized_date' => 'date',
        'received_document_date' => 'date:Y-m-d', // #125 agar <input type=date> ter-prefill
        'amount' => 'decimal:2', 'realized_amount' => 'decimal:2', 'settlement' => 'decimal:2',
        'approvals' => 'array',
    ];

    public const STATUSES = [
        'requested'   => 'Diajukan',
        'outstanding' => 'Outstanding',
        'realized'    => 'Realized',
    ];

    /** #127 — slot approval berjenjang (urut). Moch Winarto & Boy Arifin hanya untuk pengajuan MULAI Rp 1 juta. */
    public const SIGNERS = [
        'pengaju'            => 'Pengaju (User Dept)',
        'spv_manager_dept'   => 'SPV / Manager Dept',
        'general_manager'    => 'General Manager',
        'direksi'            => 'Direksi',
        'moch_winarto'       => 'Moch Winarto',
        'boy_arifin'         => 'Boy Arifin',
        'staf_accounting'    => 'Staf Accounting',
        'manager_accounting' => 'Manager Accounting',
        'manager_finance'    => 'Manager Finance',
    ];

    /**
     * Nominal MULAI dari angka ini memakai rantai penuh 9 jenjang.
     *
     * Dipakai juga oleh PengajuanKasbonPrinter untuk memilih formulir cetaknya, supaya
     * jumlah kotak tanda tangan pada kertas selalu sama dengan jumlah persetujuan yang
     * benar-benar diminta aplikasi.
     */
    public const APPROVAL_THRESHOLD = 1_000_000;

    /**
     * Slot approval yang DIPERLUKAN bon ini — Moch Winarto & Boy Arifin dilepas hanya bila
     * nominalnya DI BAWAH Rp 1 juta.
     *
     * Perbandingannya '<', bukan '<='. Nominal tepat Rp 1.000.000 ikut rantai penuh 9 jenjang:
     * sebelumnya ia hanya meminta 7 persetujuan sementara formulir cetaknya sudah memakai
     * bentuk besar berkotak 9 — kertas dan aplikasi menuntut orang yang berbeda untuk bon
     * yang sama, dan tidak ada galat apa pun yang menandainya.
     */
    public function requiredSigners(): array
    {
        $keys = array_keys(self::SIGNERS);
        if ((float) $this->amount < self::APPROVAL_THRESHOLD) {
            $keys = array_values(array_diff($keys, ['moch_winarto', 'boy_arifin']));
        }

        return $keys;
    }

    public function isFullyApproved(): bool
    {
        $a = $this->approvals ?? [];
        foreach ($this->requiredSigners() as $slot) {
            if (! isset($a[$slot])) {
                return false;
            }
        }

        return true;
    }

    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }
    public function department(): BelongsTo { return $this->belongsTo(BsHrDepartment::class, 'department_id'); } // #150
    public function lines(): HasMany { return $this->hasMany(BsCashAdvanceLine::class, 'cash_advance_id'); }
}
