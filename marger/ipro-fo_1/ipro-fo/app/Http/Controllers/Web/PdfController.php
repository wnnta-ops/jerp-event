<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Finance\Kuitansi\Models\Kuitansi;
use App\Domain\Foundation\Pdf\PdfRenderer;
use App\Domain\Legal\Ppjb\Models\PpjbDocument;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PdfController extends Controller
{
    public function __construct(private readonly PdfRenderer $renderer) {}

    public function kuitansi(Kuitansi $kuitansi): StreamedResponse
    {
        abort_unless(auth()->user()?->can('finance.kuitansi.view'), 403);

        return $this->renderer->render('pdf.kuitansi', [
            'kuitansi' => $kuitansi->load(['items', 'customer']),
        ], "Kuitansi-{$kuitansi->kuitansi_number}.pdf");
    }

    public function ppjb(PpjbDocument $ppjb): StreamedResponse
    {
        abort_unless(auth()->user()?->can('legal.ppjb.view'), 403);

        return $this->renderer->render('pdf.ppjb', [
            'ppjb' => $ppjb->load(['contract.clauses']),
        ], "PPJB-{$ppjb->ppjb_number}.pdf");
    }
}
