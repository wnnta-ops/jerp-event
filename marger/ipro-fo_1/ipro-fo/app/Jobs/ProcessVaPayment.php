<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Finance\VirtualAccount\Actions\ProcessVaPayment as Action;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessVaPayment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $backoff = 30;

    public function __construct(
        public readonly string $bankCode,
        public readonly string $vaNumber,
        public readonly float $amount,
        public readonly string $externalRef,
        public readonly Carbon $receivedAt,
    ) {}

    public function handle(Action $action): void
    {
        $action->execute($this->bankCode, $this->vaNumber, $this->amount, $this->externalRef, $this->receivedAt);
    }

    public function uniqueId(): string
    {
        return "va:{$this->bankCode}:{$this->externalRef}";
    }
}
