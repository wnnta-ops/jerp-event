<?php

namespace App\Modules\BO\GeneralLedger\Controllers;

use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Spatie\Browsershot\Browsershot;

/**
 * Renders a printable voucher/journal. Returns print-friendly HTML by default;
 * pass ?pdf=1 to render a PDF via Browsershot (production — needs Chromium).
 */
class JurnalPrintController
{
    public function show(JurnalHeader $jurnal): View|Response
    {
        $jurnal->load('detil', 'company');

        if (request()->boolean('pdf') && class_exists(Browsershot::class)) {
            $html = view('pdf.bo.jurnal', ['jurnal' => $jurnal])->render();
            $pdf = Browsershot::html($html)->noSandbox()->addChromiumArguments(['disable-dev-shm-usage'])->format('A4')->pdf();

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$jurnal->no_jurnal.'.pdf"',
            ]);
        }

        return view('pdf.bo.jurnal', ['jurnal' => $jurnal]);
    }
}
