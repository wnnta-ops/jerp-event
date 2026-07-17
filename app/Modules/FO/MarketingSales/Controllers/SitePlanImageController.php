<?php

namespace App\Modules\FO\MarketingSales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Ganti/Hapus gambar Site Plan per Cluster via upload HTML biasa (multipart POST),
 * TANPA FilePond/Livewire — jalur andal bila widget FileUpload bermasalah di browser.
 * Logika penyimpanan identik dengan UploadSitePlan::submit().
 */
class SitePlanImageController extends Controller
{
    public function upload(Request $request): RedirectResponse
    {
        $request->validate([
            'lokasi_id' => 'required|integer|exists:fo_lokasi,id',
            'image'     => 'required|image|max:10240', // maks 10 MB
        ]);

        $lokasi = Lokasi::findOrFail($request->integer('lokasi_id'));

        // Hapus gambar lama milik cluster ini.
        if ($lokasi->siteplan_image_path && Storage::disk('public')->exists($lokasi->siteplan_image_path)) {
            Storage::disk('public')->delete($lokasi->siteplan_image_path);
        }

        $file = $request->file('image');
        $path = $file->store('siteplans', 'public');
        $size = @getimagesize($file->getRealPath()) ?: [null, null];

        $lokasi->update([
            'siteplan_image_path' => $path,
            'siteplan_width'      => $size[0] ?? null,
            'siteplan_height'     => $size[1] ?? null,
        ]);

        return redirect()->to('/fo/upload-site-plan?lok='.$lokasi->id)->with('siteplan_msg',
            'Gambar site plan "'.$lokasi->nama.'" diganti ('.($size[0] ?? '?').'×'.($size[1] ?? '?').' px). '.
            'Layout sama? Cukup "Geser Semua" di Polygon Editor — tak perlu gambar ulang.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['lokasi_id' => 'required|integer|exists:fo_lokasi,id']);

        $lokasi = Lokasi::findOrFail($request->integer('lokasi_id'));
        if ($lokasi->siteplan_image_path && Storage::disk('public')->exists($lokasi->siteplan_image_path)) {
            Storage::disk('public')->delete($lokasi->siteplan_image_path);
        }
        $lokasi->update(['siteplan_image_path' => null, 'siteplan_width' => null, 'siteplan_height' => null]);

        return redirect()->to('/fo/upload-site-plan?lok='.$lokasi->id)->with('siteplan_msg',
            'Gambar site plan "'.$lokasi->nama.'" dihapus — kembali ke fallback gambar project.');
    }
}
