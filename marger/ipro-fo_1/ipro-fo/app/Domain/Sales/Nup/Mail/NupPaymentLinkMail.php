<?php

declare(strict_types=1);

namespace App\Domain\Sales\Nup\Mail;

use App\Domain\Sales\Nup\Models\NupRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NupPaymentLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly NupRegistration $reg,
        public readonly ?string $payLink = null,
    ) {}

    public function build(): self
    {
        return $this->subject("Link Pembayaran NUP {$this->reg->nup_number} — Java Residence")
            ->view('mail.nup-payment-link', [
                'reg'     => $this->reg,
                'payLink' => $this->payLink,
            ]);
    }
}
