<?php

declare(strict_types=1);

namespace App\Domain\Finance\Kuitansi\Listeners;

use App\Domain\Finance\Kuitansi\Actions\IssueKuitansi;
use App\Domain\Finance\Tts\Events\TtsPosted;

final class AutoIssueKuitansi
{
    public function __construct(private readonly IssueKuitansi $action) {}

    public function handle(TtsPosted $event): void
    {
        $this->action->execute($event->tts);
    }
}
