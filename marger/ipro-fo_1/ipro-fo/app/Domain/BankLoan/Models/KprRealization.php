<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Models;

use App\Domain\Finance\Tts\Models\Tts;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KprRealization extends Model
{
    protected $table = 'kpr_realizations';

    protected $fillable = [
        'kpr_application_id', 'disbursed_at', 'disbursed_amount', 'retention_amount', 'retention_note',
        'retention_category_id', 'reference_no', 'bank_account_id', 'tts_id',
        'ssp_bphtb_date', 'ssp_bphtb_amount', 'ssp_tts_id', // #146 DAJAM Pajak: pembayaran SSP/BPHTB
        'journal_id', 'approval_status', 'reject_reason',   // Approval Retensi — baris PENCAIRAN (net) dijurnal ke GL
    ];

    protected $casts = [
        'disbursed_at'      => 'date',
        'disbursed_amount'  => 'decimal:2',
        'retention_amount'  => 'decimal:2',
        'ssp_bphtb_date'    => 'date',     // #146
        'ssp_bphtb_amount'  => 'decimal:2', // #146
    ];

    public function application(): BelongsTo { return $this->belongsTo(KprApplication::class, 'kpr_application_id'); }

    public function retentions(): HasMany { return $this->hasMany(KprRetention::class); }

    public function category(): BelongsTo { return $this->belongsTo(RetentionCategory::class, 'retention_category_id'); }

    public function tts(): BelongsTo { return $this->belongsTo(Tts::class, 'tts_id'); }

    /** #146 — TTS pembayaran SSP/BPHTB (DAJAM Pajak). */
    public function sspTts(): BelongsTo { return $this->belongsTo(Tts::class, 'ssp_tts_id'); }

    /** Jurnal GL hasil Approval Retensi untuk baris PENCAIRAN (net) ini. */
    public function journal(): BelongsTo { return $this->belongsTo(\App\Domain\BusinessSupport\Models\BsJournal::class, 'journal_id'); }

    /**
     * #146 — Reversal aman TTS SSP/BPHTB saat realisasi dihapus (ReverseDisbursement hanya
     * membalik tts utama). Hook `deleting` membatalkan alokasi & menghapus TTS SSP agar
     * outstanding AR customer kembali seperti semula. Diblokir bila TTS sudah diposting.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $realization): void {
            // Pencairan yang sudah disetujui/dijurnal di Approval Retensi tidak boleh dihapus — jurnal GL
            // akan menggantung. Void/hapus jurnalnya dulu (mirror guard TTS SSP di bawah & retensi).
            if ($realization->journal_id) {
                throw new \App\Exceptions\BusinessRuleException(
                    'Pencairan ini sudah dijurnal di Approval Retensi — batalkan/void jurnalnya dulu sebelum menghapus realisasi.',
                    errorCode: 'REALIZATION_JOURNALED',
                );
            }

            $sspTts = $realization->ssp_tts_id ? Tts::with('allocations')->find($realization->ssp_tts_id) : null;
            if (! $sspTts) {
                return;
            }
            if ((string) $sspTts->status === 'posted') {
                throw new \App\Exceptions\BusinessRuleException(
                    'TTS pembayaran SSP/BPHTB sudah diposting — void dulu di modul TTS sebelum menghapus realisasi ini.',
                    errorCode: 'SSP_TTS_POSTED',
                );
            }
            foreach ($sspTts->allocations as $alloc) {
                $sch = PaymentSchedule::find($alloc->payment_schedule_id);
                if (! $sch) {
                    continue;
                }
                $newPaid = max(0, (float) $sch->paid_amount - (float) $alloc->amount);
                $sch->paid_amount = $newPaid;
                $sch->status = $newPaid <= 0 ? 'unpaid' : ($newPaid >= (float) $sch->amount ? 'paid' : 'partial');
                if ($newPaid < (float) $sch->amount) {
                    $sch->paid_at = null;
                }
                $sch->save();
            }
            $sspTts->allocations()->delete();
            $sspTts->forceDelete();
        });
    }
}
