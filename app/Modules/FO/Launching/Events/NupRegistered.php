<?php

namespace App\Modules\FO\Launching\Events;

use App\Modules\FO\Launching\Models\NupRegistration;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after public NUP registration succeeds.
 * Listeners enqueue email + WhatsApp confirmation jobs (BR-NUP-04).
 */
class NupRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly NupRegistration $registration) {}
}
