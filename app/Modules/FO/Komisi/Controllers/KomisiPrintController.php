<?php

namespace App\Modules\FO\Komisi\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FO\Komisi\Models\Komisi;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Browsershot\Browsershot;

/**
 * Cetak "Permohonan Pengajuan Komisi" per No. Pengajuan (1 dokumen, 1 tabel).
 * Sumber: komisi terpilih (?ids=) / batch (?pengajuan=) / status. HTML printable;
 * ?pdf=1 → Browsershot.
 */
class KomisiPrintController extends Controller
{
    public function pengajuan(Request $request): mixed
    {
        $q = Komisi::query()->with(['agent', 'kontrak.customer', 'kontrak.unit.lokasi']);

        if ($pengajuan = $request->query('pengajuan')) {
            $q->where('no_pengajuan', $pengajuan);
        } elseif ($ids = $request->query('ids')) {
            $q->whereIn('id', array_filter(explode(',', (string) $ids)));
        } else {
            $q->where('status', $request->query('status', 'DRAFT'));
        }

        $rows = $q->orderBy('id')->get();
        $lokasiName = $rows->map(fn ($k) => $k->kontrak?->unit?->lokasi?->nama)->filter()->first() ?? '';

        $data = [
            'rows' => $rows,
            'noPengajuan' => $request->query('pengajuan', ''),
            'lokasiName' => $lokasiName,
            'tanggal' => now(),
        ];

        if ($request->boolean('pdf') && class_exists(Browsershot::class)) {
            $html = view('pdf.fo.komisi-pengajuan', $data)->render();
            $pdf = Browsershot::html($html)->noSandbox()->addChromiumArguments(['disable-dev-shm-usage'])->format('A4')->landscape()->pdf();

            return new Response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Pengajuan-Komisi.pdf"',
            ]);
        }

        return view('pdf.fo.komisi-pengajuan', $data);
    }
}
