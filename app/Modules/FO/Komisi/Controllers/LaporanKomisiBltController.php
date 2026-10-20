<?php

namespace App\Modules\FO\Komisi\Controllers;

use App\Filament\Fo\Pages\LaporanBlt;
use App\Filament\Fo\Pages\LaporanKomisi;
use App\Http\Controllers\Controller;
use App\Modules\FO\Komisi\Exports\LaporanBltExport;
use App\Modules\FO\Komisi\Exports\LaporanKomisiExport;
use App\Modules\FO\Komisi\Services\LaporanKomisiBlt;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Export Excel Laporan Komisi & Laporan BLT (filter sama dengan layarnya). */
class LaporanKomisiBltController extends Controller
{
    public function komisi(Request $request): BinaryFileResponse
    {
        abort_unless(LaporanKomisi::canAccess(), 403);
        $v = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'lokasi_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:PENDING,APPROVED,PAID'],
            'tanggal' => ['nullable', 'in:pengajuan,cair'],
        ]);
        $data = LaporanKomisiBlt::komisi($v['from'] ?? null, $v['to'] ?? null,
            isset($v['lokasi_id']) ? (int) $v['lokasi_id'] : null, $v['status'] ?? null, $v['tanggal'] ?? 'cair');

        return Excel::download(new LaporanKomisiExport($data), 'Laporan-Komisi-'.now()->format('Ymd-His').'.xlsx');
    }

    public function blt(Request $request): BinaryFileResponse
    {
        abort_unless(LaporanBlt::canAccess(), 403);
        $v = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return Excel::download(new LaporanBltExport($v['from'] ?? null, $v['to'] ?? null), 'Laporan-BLT-'.now()->format('Ymd-His').'.xlsx');
    }
}
