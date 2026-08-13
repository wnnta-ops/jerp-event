<?php

namespace App\Modules\FO\Launching\Mail;

use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Controllers\ClosingPrintController;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * Email kwitansi UTJ — dikirim SETELAH pembayaran Xendit berhasil.
 * Isinya sama dengan pesan WhatsApp; lampirannya kwitansi satu halaman.
 */
class KwitansiUtjMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly NupRegistration $registration) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Pembayaran UTJ Diterima — '.$this->registration->nama_pendaftar);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.kwitansi-utj', with: ['r' => $this->registration]);
    }

    /**
     * Lampiran kwitansi PDF. GRACEFUL: butuh Browsershot+Chromium; bila tak
     * tersedia (INSTALL_CHROMIUM=false) lampiran dilewati dan email TETAP
     * terkirim — kegagalannya dicatat di log, bukan membatalkan pemberitahuan.
     *
     * @return array<int,Attachment>
     */
    public function attachments(): array
    {
        if (! class_exists(Browsershot::class)) {
            return [];
        }

        try {
            $html = view('pdf.fo.kwitansi-utj', ClosingPrintController::kwitansiUtjData($this->registration))->render();
            // noSandbox + disable-dev-shm: wajib utk Chromium di dalam container.
            $pdf = Browsershot::html($html)->noSandbox()->addChromiumArguments(['disable-dev-shm-usage'])->format('A4')->pdf();

            return [
                Attachment::fromData(fn () => $pdf, ClosingPrintController::namaBerkasKwitansiUtj($this->registration).'.pdf')
                    ->withMime('application/pdf'),
            ];
        } catch (Throwable $e) {
            Log::warning('Gagal lampirkan kwitansi UTJ PDF (Chromium?): '.$e->getMessage());

            return [];
        }
    }
}
