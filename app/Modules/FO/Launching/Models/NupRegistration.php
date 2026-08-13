<?php

namespace App\Modules\FO\Launching\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Models\User;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Komisi\Models\Agent;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NupRegistration extends TenantAwareModel
{
    protected $table = 'fo_nup_registrations';

    protected $fillable = [
        'tenant_id', 'jenis', 'nup_event_id', 'project_pref_id', 'agent_id',
        'no_nup', 'urutan',
        'customer_id', 'nama_pendaftar', 'nik', 'phone', 'secondary_phone', 'email',
        'tgl_daftar', 'tgl_check_in', 'tgl_payment',
        'booking_fee_paid', 'tts_id', 'transfer_anonim_id', 'unit_id', 'reservasi_id',
        'status', 'channel', 'recaptcha_score',
        'ref_sales_name', 'ref_sales_phone', 'tipe_nup', 'cara_bayar', 'bank_id', 'bank_nama',
        'preferensi_unit', 'pilihan_budget', 'preferensi_unit_id', 'preferensi_unit_nama',
        'pilihan_unit_1', 'pilihan_unit_2', 'pilihan_unit_3', 'rekomendasi_unit',
        'pernyataan_setuju',
        'ip_address', 'user_agent', 'bukti_transfer_path',
        'keterangan',
        // Verifikasi Data / UTJ
        'verif_status', 'is_hold', 'hold_reason', 'utj_amount',
        'xendit_external_id', 'xendit_invoice_id', 'xendit_invoice_url', 'xendit_status', 'xendit_paid_at', 'xendit_paid_amount',
        'verified_by', 'verified_at', 'is_manual_approve', 'verif_note',
    ];

    /** Verification statuses (NUP → Verifikasi Data). */
    public const VERIF_MENUNGGU_BAYAR = 'MENUNGGU_BAYAR';

    public const VERIF_PAID = 'PAID';

    public const VERIF_APPROVED = 'APPROVED';

    public const VERIF_PENDING = 'PENDING';

    public const VERIF_REJECTED = 'REJECTED';

    public const VERIF_REFUNDED = 'REFUNDED';

    protected $casts = [
        'urutan' => 'integer',
        'tgl_daftar' => 'datetime',
        'tgl_check_in' => 'datetime',
        'tgl_payment' => 'datetime',
        'booking_fee_paid' => 'decimal:2',
        'utj_amount' => 'decimal:2',
        'xendit_paid_at' => 'datetime',
        'xendit_paid_amount' => 'decimal:2',
        'verified_at' => 'datetime',
        'is_manual_approve' => 'boolean',
        'is_hold' => 'boolean',
        'preferensi_unit' => 'array',
        'pilihan_budget' => 'array',
        'pernyataan_setuju' => 'boolean',
    ];

    public function isUtjPaid(): bool
    {
        return $this->xendit_status === 'PAID' || $this->verif_status === self::VERIF_PAID;
    }

    public function isXenditSimulasi(): bool
    {
        return str_starts_with((string) $this->xendit_invoice_id, 'fake_');
    }

    /** Selisih nilai transfer Xendit − nilai UTJ sistem (null bila nilai transfer belum ditarik). */
    public function xenditSelisih(): ?float
    {
        if ($this->xendit_paid_amount === null) {
            return null;
        }

        return round((float) $this->xendit_paid_amount - (float) $this->utj_amount, 2);
    }

    /**
     * Status rekonsiliasi Xendit ↔ UTJ:
     *  SIMULASI · MENUNGGU · TIDAK DIBAYAR · PERLU SINKRON · SESUAI · SELISIH.
     */
    public function reconStatus(): string
    {
        if ($this->isXenditSimulasi()) {
            return 'SIMULASI';
        }
        if (! $this->isUtjPaid()) {
            return $this->xendit_status === 'EXPIRED' ? 'TIDAK DIBAYAR' : 'MENUNGGU';
        }
        if ($this->xendit_paid_amount === null) {
            return 'PERLU SINKRON';
        }

        return abs((float) $this->xenditSelisih()) < 0.01 ? 'SESUAI' : 'SELISIH';
    }

    public function canSelectUnit(): bool
    {
        return $this->verif_status === self::VERIF_APPROVED;
    }

    /**
     * Returns the highest-priority unit preference the customer typed as a
     * referensi free-text string (NOT a foreign-key — pilihan unit is only
     * informational, not bound to fo_units).
     */
    public function prioritasUnitText(): ?string
    {
        return $this->pilihan_unit_1 ?: ($this->pilihan_unit_2 ?: $this->pilihan_unit_3);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(NupEvent::class, 'nup_event_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function projectPref(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_pref_id');
    }

    /** Sales/Agent yang mendaftarkan NUP (dari master /fo/agents). */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** Bank Pilihan (KPR) dari master Setup Bank. Nama-nya di-snapshot di bank_nama. */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /** Preferensi Unit (satu pilihan) dari master Setup Preferensi Unit. */
    public function preferensiUnit(): BelongsTo
    {
        return $this->belongsTo(PreferensiUnit::class, 'preferensi_unit_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tts(): BelongsTo
    {
        return $this->belongsTo(Tts::class);
    }

    public function transferAnonim(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\FO\FinanceAR\Models\TransferAnonim::class, 'transfer_anonim_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function reservasi(): BelongsTo
    {
        return $this->belongsTo(Reservasi::class);
    }
}
