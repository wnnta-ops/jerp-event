<?php

namespace App\Modules\FO\Komisi\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FO\Komisi\Models\Blt;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Browsershot\Browsershot;

/**
 * Cetak "Permohonan Pengajuan BLT" — 1 dokumen, 3 halaman (Sales / Koordinator /
 * Kantor) sesuai format legacy. Sumber: BLT terpilih (?ids=) atau seluruh BLT DRAFT.
 * HTML printable; ?pdf=1 → Browsershot.
 */
class BltPrintController extends Controller
{
    public function pengajuan(Request $request): mixed
    {
        $q = Blt::query()->with(['agent', 'kontrak.customer', 'kontrak.unit.lokasi']);

        if ($pengajuan = $request->query('pengajuan')) {
            $q->where('no_pengajuan', $pengajuan);
        } elseif ($ids = $request->query('ids')) {
            $q->whereIn('id', array_filter(explode(',', (string) $ids)));
        } else {
            $q->where('status', $request->query('status', 'DRAFT'));
        }

        $blt = $q->orderBy('level_type')->orderBy('id')->get();

        // Nama lokasi dari unit kontrak pertama (untuk judul "UNTUK PENJUALAN ...").
        $lokasiName = $blt->map(fn ($b) => $b->kontrak?->unit?->lokasi?->nama)->filter()->first() ?? '';

        // Kelompokkan per level, urut Sales → Koordinator → Kantor.
        $urut = ['SALES' => 0, 'KOORDINATOR' => 1, 'KANTOR' => 2];
        $groups = $blt->groupBy('level_type')
            ->sortBy(fn ($g, $type) => $urut[$type] ?? 9);

        $data = [
            'groups' => $groups,
            'lokasiName' => $lokasiName,
            'tanggal' => now(),
        ];

        if ($request->boolean('pdf') && class_exists(Browsershot::class)) {
            $html = view('pdf.fo.blt-pengajuan', $data)->render();
            $pdf = Browsershot::html($html)->format('A4')->landscape()->pdf();

            return new Response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Pengajuan-BLT.pdf"',
            ]);
        }

        return view('pdf.fo.blt-pengajuan', $data);
    }
}
