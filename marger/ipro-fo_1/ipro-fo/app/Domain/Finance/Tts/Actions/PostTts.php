<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Actions;

use App\Domain\Finance\Tts\Enums\TtsStatus;
use App\Domain\Finance\Tts\Models\Tts;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

final class PostTts
{
    public function execute(Tts $tts): Tts
    {
        if ($tts->status !== 'verified') {
            throw new BusinessRuleException(
                "TTS hanya bisa di-posting jika status VERIFIED. Sekarang: {$tts->status}.",
                errorCode: 'TTS_NOT_POSTABLE',
            );
        }

        return DB::transaction(function () use ($tts): Tts {
            $tts->update([
                'status'    => TtsStatus::Posted->value,
                'posted_by' => auth()->id(),
                'posted_at' => now(),
            ]);

            event(new \App\Domain\Finance\Tts\Events\TtsPosted($tts));

            return $tts;
        });
    }
}
