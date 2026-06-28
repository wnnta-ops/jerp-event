<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Domain\Finance\Kuitansi\Models\Kuitansi;
use App\Domain\Finance\Tts\Models\Tts;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Inisiasi KPR — satu record alur pra-kualifikasi 7 tahap (lihat migration).
 * Sumber awal = TTS (Tanda Terima Setoran); output = Draft KprApplication (kpr_application_id).
 */
class KprInitiation extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'kpr_initiations';

    protected $fillable = [
        'tenant_id', 'initiation_number', 'kuitansi_id', 'tts_receipt_id', 'customer_id', 'booking_id',
        'bank_partner_id', 'plafon_requested', 'current_stage', 'status', 'stages',
        'kpr_application_id', 'initiated_by', 'notes',
    ];

    protected $casts = [
        'stages'           => 'array',
        'plafon_requested' => 'decimal:2',
        'current_stage'    => 'integer',
    ];

    public function customer(): BelongsTo       { return $this->belongsTo(\App\Domain\Master\Customer\Models\Customer::class); }
    public function kuitansi(): BelongsTo       { return $this->belongsTo(Kuitansi::class); }
    public function ttsReceipt(): BelongsTo     { return $this->belongsTo(Tts::class, 'tts_receipt_id'); }
    public function booking(): BelongsTo        { return $this->belongsTo(\App\Domain\Sales\Booking\Models\Booking::class); }
    public function bank(): BelongsTo           { return $this->belongsTo(BankPartner::class, 'bank_partner_id'); }
    public function kprApplication(): BelongsTo { return $this->belongsTo(KprApplication::class, 'kpr_application_id'); }
}
