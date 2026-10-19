<?php

namespace App\Modules\FO\Komunikasi\Mail;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Email yang subjek & isinya diambil dari Pengaturan → Template Pesan.
 * Lampiran (PDF) dibuat saat dikirim; bila gagal dibuat, email tetap terkirim tanpa lampiran.
 */
class PesanTemplateMail extends Mailable
{
    use Queueable;

    /**
     * @param  Closure|null  $lampiran  fn(): array{0:string,1:string} → [isi PDF, nama berkas]
     */
    public function __construct(
        public readonly string $subjek,
        public readonly string $isiHtml,
        private readonly ?Closure $lampiran = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjek);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.pesan-template', with: ['isiHtml' => $this->isiHtml]);
    }

    /** @return array<int,Attachment> */
    public function attachments(): array
    {
        if (! $this->lampiran) {
            return [];
        }

        try {
            [$isi, $nama] = ($this->lampiran)();
            if ($isi === null || $isi === '') {
                return [];
            }

            return [Attachment::fromData(fn () => $isi, $nama)->withMime('application/pdf')];
        } catch (Throwable $e) {
            Log::warning('Template Pesan: lampiran gagal dibuat — '.$e->getMessage());

            return [];
        }
    }
}
