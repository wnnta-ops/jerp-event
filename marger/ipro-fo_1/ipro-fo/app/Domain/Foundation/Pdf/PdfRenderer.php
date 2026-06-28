<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Pdf;

use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service untuk render PDF dari template Blade pakai Browsershot.
 */
final class PdfRenderer
{
    /**
     * Render PDF dari template Blade + data.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $view, array $data = [], string $filename = 'document.pdf'): StreamedResponse
    {
        $html = View::make($view, $data)->render();

        $pdf = Browsershot::html($html)
            ->setNodeBinary(env('NODE_BINARY_PATH', 'node'))
            ->setChromePath(config('domain.pdf.browsershot.chromium_path'))
            ->format('A4')
            ->margins(20, 15, 20, 15)
            ->showBackground()
            ->pdf();

        return response()->streamDownload(fn () => print($pdf), $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
