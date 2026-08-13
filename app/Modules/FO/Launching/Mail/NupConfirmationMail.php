<?php

namespace App\Modules\FO\Launching\Mail;

use App\Modules\FO\Launching\Models\NupRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Throwable;

class NupConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly NupRegistration $registration,
        public readonly ?string $sprsUrl = null,
    ) {}

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
                'sprsUrl' => $this->sprsUrl,
            ],
        );
    }

    /**
     * Lampirkan SPRS PDF saat sudah APPROVED. GRACEFUL: butuh Browsershot+Chromium;
     * jika tak tersedia (mis. INSTALL_CHROMIUM=false), lampiran dilewati — email
     * tetap terkirim dengan tautan SPRS di body.
     *
     * @return array<int,Attachment>
     */
    public function attachments(): array
    {
        $reg = $this->registration;
        if ($reg->urutan === null || ! class_exists(Browsershot::class)) {
            return [];
        }

        try {
            $reg->loadMissing(['customer', 'agent']);
            $html = view('pdf.fo.sprs', ['reg' => $reg])->render();
            // noSandbox + disable-dev-shm: wajib utk Chromium di dalam container.
            $pdf = Browsershot::html($html)->noSandbox()->addChromiumArguments(['disable-dev-shm-usage'])->format('A4')->pdf();

            return [
                Attachment::fromData(fn () => $pdf, \App\Modules\FO\MarketingSales\Controllers\ClosingPrintController::namaBerkasSprs($reg).'.pdf')
                    ->withMime('application/pdf'),
            ];
        } catch (Throwable $e) {
            Log::warning('Gagal lampirkan SPRS PDF (Chromium?): '.$e->getMessage());

            return [];
        }
    }
}
