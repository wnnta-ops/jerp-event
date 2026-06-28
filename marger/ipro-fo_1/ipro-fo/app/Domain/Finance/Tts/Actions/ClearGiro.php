<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Actions;

use App\Domain\Finance\Tts\Models\Tts;
use App\Domain\Finance\Tts\Services\TtsAllocator;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Saat giro cair (clearing bank sukses): update status, allocate.
 */
final class ClearGiro
{
    public function __construct(private readonly TtsAllocator $allocator) {}

    public function execute(Tts $tts): Tts
    {
        if ($tts->payment_method !== 'giro') {
            throw new BusinessRuleException('TTS bukan giro.', errorCode: 'NOT_GIRO');
        }

        return DB::transaction(function () use ($tts): Tts {
            DB::table('tts_giros')->where('tts_receipt_id', $tts->id)->update([
                'status'     => 'cleared',
                'cleared_at' => now()->toDateString(),
                'updated_at' => now(),
            ]);

            $tts->update(['status' => 'verified']);

            if ($tts->booking_id) {
                $this->allocator->autoAllocate($tts);
            }

            return $tts->refresh();
        });
    }
}
