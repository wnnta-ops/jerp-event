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
        'received_document_date', 'approvals', 'cost_center_id', // #125, #127
        'approval_mode', 'payment_method', 'va_expired_date', 'credit_card_id',
    ];
    protected $casts = [
        'advance_date' => 'date', 'paid_date' => 'date', 'realized_date' => 'date',
        'received_document_date' => 'date:Y-m-d', // #125 agar <input type=date> ter-prefill
        'va_expired_date' => 'date:Y-m-d',        // idem — agar terbaca <input type=date>
        'amount' => 'decimal:2', 'realized_amount' => 'decimal:2', 'settlement' => 'decimal:2',
        'approvals' => 'array',
    ];

    public const STATUSES = [
        'requested'   => 'Diajukan',
        'outstanding' => 'Outstanding',
        'realized'    => 'Realized',
    ];

    /**
     * Cara persetujuan — DIPILIH PER PENGAJUAN saat bon dibuat.
     *
     *   online  : jenjang persetujuan berjalan di aplikasi; pembayaran & jurnal baru terbuka
     *             setelah seluruh tanda tangan terpenuhi.
     *   offline : tanda tangannya di kertas (formulir Pengajuan Kasbon yang dicetak). Jenjang
     *             di aplikasi dimatikan, dan Finance boleh mencatat pembayaran serta membentuk
     *             jurnalnya tanpa menunggu tanda tangan digital.
     *
     * Yang TIDAK berubah pada offline: bonnya tetap dicetak dengan formulir berjenjang yang
     * sama, sebab justru kertas itulah tempat tanda tangannya dikumpulkan.
     */
    public const APPROVAL_MODES = [
        'online'  => 'Persetujuan Online (berjenjang di aplikasi)',
        'offline' => 'Persetujuan Offline (tanda tangan di kertas)',
    ];

    /**
     * Cara pembayaran — dipilih Finance saat mencatat pembayaran.
     *
     *   reguler  : transfer/tunai, alur seperti selama ini.
     *   debit_va : lewat Virtual Account. VA punya masa berlaku, dan tanggal expired-nya WAJIB
     *              dicatat: VA yang lewat tanggalnya tidak bisa lagi didebit, sehingga uangnya
     *              tidak pernah berpindah meski jurnalnya sudah terbentuk — selisih yang baru
     *              ketahuan saat rekonsiliasi bank.
     *
     * Jurnalnya SAMA untuk keduanya (Dr Uang Muka Operasional · Cr Bank/Kas): yang berbeda
     * cara uangnya berpindah, bukan akuntansinya.
     */
    public const PAYMENT_METHODS = [
        'reguler'  => 'Pembayaran Reguler',
        'debit_va' => 'Pembayaran Debit VA',
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
    /** Persetujuannya dikumpulkan di kertas, bukan di aplikasi? */
    public function isOfflineApproval(): bool
    {
        // Bawaannya ONLINE. Kolomnya baru ada sejak 2026-09-04, dan bon lama bernilai NULL —
        // membacanya sebagai offline akan membuka pembayaran seluruh bon lama tanpa satu pun
        // persetujuan, diam-diam.
        return ($this->approval_mode ?? 'online') === 'offline';
    }

    public function requiredSigners(): array
    {
        // Offline: tidak ada slot yang diminta aplikasi, sehingga isFullyApproved() bernilai
        // true dan gerbang pembayaran terbuka — persis yang dimaksud "menonaktifkan jenjang".
        if ($this->isOfflineApproval()) {
            return [];
        }

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
    /** Cost center (departemen) penanggung bon ini — dimensi yang dibaca Budget Control. */
    public function costCenter(): BelongsTo { return $this->belongsTo(BsCostCenter::class, 'cost_center_id'); }
    /** Kartu kredit/VA yang dipakai — hanya terisi pada pembayaran Debit VA. */
    public function creditCard(): BelongsTo { return $this->belongsTo(BsCreditCard::class, 'credit_card_id'); }
    public function department(): BelongsTo { return $this->belongsTo(BsHrDepartment::class, 'department_id'); } // #150
    public function lines(): HasMany { return $this->hasMany(BsCashAdvanceLine::class, 'cash_advance_id'); }
}
