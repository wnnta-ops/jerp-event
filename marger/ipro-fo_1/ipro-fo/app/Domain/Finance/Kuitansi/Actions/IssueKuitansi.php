<?php

declare(strict_types=1);

namespace App\Domain\Finance\Kuitansi\Actions;

use App\Domain\Finance\Kuitansi\Models\Kuitansi;
use App\Domain\Finance\Tts\Models\Tts;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class IssueKuitansi
{
    public function __construct(private readonly NumeratorService $numerator) {}

    public function execute(Tts $tts, ?string $signedBy = null): Kuitansi
    {
        if ($tts->status !== 'posted') {
            throw new BusinessRuleException(
                'Kuitansi hanya bisa diterbitkan untuk TTS yang sudah POSTED.',
                errorCode: 'TTS_NOT_POSTED',
            );
        }

        // Cek apakah sudah pernah issued
        $existing = Kuitansi::query()->where('tts_receipt_id', $tts->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($tts, $signedBy): Kuitansi {
            $kw = Kuitansi::create([
                'kuitansi_number' => $this->numerator->next('KW'),
                'tts_receipt_id'  => $tts->id,
                'customer_id'     => $tts->customer_id,
                'issued_date'     => now()->toDateString(),
                'amount'          => (float) $tts->amount,
                'terbilang'       => ucfirst(terbilang((int) round((float) $tts->amount))).' rupiah',
                'signed_by'       => $signedBy ?? auth()->user()?->name,
                'status'          => 'issued',
            ]);

            $kw->items()->create([
                'description' => "Pembayaran TTS {$tts->tts_number}".($tts->booking?->booking_number ? " — Booking {$tts->booking->booking_number}" : ''),
                'amount'      => (float) $tts->amount,
            ]);

            return $kw;
        });
    }
}
