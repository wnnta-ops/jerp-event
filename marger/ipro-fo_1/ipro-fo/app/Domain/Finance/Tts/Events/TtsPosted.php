<?php

declare(strict_types=1);

namespace App\Domain\Finance\Tts\Events;

use App\Domain\Finance\Tts\Models\Tts;
use Illuminate\Foundation\Events\Dispatchable;

final class TtsPosted
{
    use Dispatchable;

    public function __construct(public readonly Tts $tts) {}
}
