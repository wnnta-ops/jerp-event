<?php

namespace App\Modules\FO\Launching\Mail;

use App\Modules\FO\Launching\Models\NupRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NupConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly NupRegistration $registration) {}

    public function envelope(): Envelope
    {
        $reg = $this->registration;

        if ($reg->urutan !== null) {
            $subject = 'Nomor Urut NUP Anda — '.$reg->no_nup;
        } elseif ((float) $reg->utj_amount > 0) {
            $subject = 'Pembayaran UTJ NUP — '.$reg->nama_pendaftar;
        } else {
            $subject = 'Pendaftaran NUP Diterima — menunggu verifikasi';
        }

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.nup-confirmation',
            with: [
                'r' => $this->registration,
            ],
        );
    }
}
