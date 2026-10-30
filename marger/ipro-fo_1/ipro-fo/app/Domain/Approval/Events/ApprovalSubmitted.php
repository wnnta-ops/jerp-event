<?php

declare(strict_types=1);

namespace App\Domain\Approval\Events;

use App\Domain\Approval\Models\Approval;
use Illuminate\Foundation\Events\Dispatchable;

/** Approval baru diajukan — level pertama menunggu. */
final class ApprovalSubmitted
{
    use Dispatchable;

    public function __construct(public readonly Approval $approval) {}
}
