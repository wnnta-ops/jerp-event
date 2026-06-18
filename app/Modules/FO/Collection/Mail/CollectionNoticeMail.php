<?php

namespace App\Modules\FO\Collection\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email surat penagihan (reminder / SP). Subjek & isi sudah dirender dari template
 * Setup SP oleh CollectionService — Mailable ini hanya membungkusnya jadi email.
 */
class CollectionNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjek,
        public string $isi,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjek);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.fo.collection-notice',
            with: ['isi' => $this->isi, 'subjek' => $this->subjek],
        );
    }
}
