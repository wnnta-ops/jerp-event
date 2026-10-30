<?php

declare(strict_types=1);

namespace App\Domain\Approval\Events;

use App\Domain\Approval\Models\Approval;
use Illuminate\Foundation\Events\Dispatchable;

/** Satu level disetujui dan masih ada level berikutnya (approval belum final). */
final class ApprovalStepApproved
{
    use Dispatchable;

    public function __construct(public readonly Approval $approval) {}
}
