<?php

namespace App\Http\Controllers;

use App\Filament\System\Resources\UserResource;
use App\Filament\System\Support\ImporPengguna;
use App\Filament\System\Support\ImporPenggunaGagal;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Import Excel Pengguna lewat FORM UPLOAD BIASA (multipart), bukan FileUpload Livewire.
 *
 * Di server, FileUpload Filament/Livewire berulang kali kehilangan berkasnya saat dikirim
 * lalu melapor "field is required" walau unggahan tampak selesai — gejala yang sama dengan
 * Upload Unit FO (lihat catatan di UploadUnit). Unggahan Site Plan pernah dibereskan dengan
 * cara ini juga. Form biasa tidak punya tahap berkas sementara Livewire, jadi titik gagal itu
 * hilang seluruhnya.
 */
class ImporPenggunaController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless(UserResource::canCreate(), 403);
        $kembali = UserResource::getUrl('index', panel: 'system');

        $berkas = $request->file('berkas');
        if (! $berkas || ! $berkas->isValid()) {
            Notification::make()->danger()->title('Berkas belum terpilih atau gagal terunggah')
                ->body('Pilih berkas Excel (.xlsx), lalu tekan Import.')->persistent()->send();

            return redirect($kembali);
        }
        if (strtolower($berkas->getClientOriginalExtension()) !== 'xlsx') {
            Notification::make()->danger()->title('Format berkas salah')
                ->body('Berkas harus Excel (.xlsx). Unduh Format atau Export Excel untuk contohnya.')->persistent()->send();

            return redirect($kembali);
        }

        try {
            $h = ImporPengguna::jalankan($berkas->getRealPath(), $request->user());
        } catch (ImporPenggunaGagal $e) {
            Notification::make()->danger()->title($e->getMessage().' ('.count($e->galat).' kesalahan)')
                ->body(self::daftarGalat($e->galat))->persistent()->send();

            return redirect($kembali);
        }

        $isi = "{$h['baru']} pengguna baru, {$h['ubah']} diperbarui. Karyawan BO: {$h['karyawan_baru']} dibuat, {$h['karyawan_ubah']} disesuaikan.";
        if ($h['gagal_bo'] !== []) {
            Notification::make()->warning()->title('Import selesai, sebagian gagal sinkron ke BO')
                ->body(e($isi).'<br><b>Gagal ke BO</b> (ulangi lewat "Sinkronkan ke BO"):<ul>'
                    .implode('', array_map(fn ($x) => '<li>'.e($x).'</li>', $h['gagal_bo'])).'</ul>')
                ->persistent()->send();
        } else {
            Notification::make()->success()->title('Import selesai')->body($isi.' Berlaku di BO paling lama 1 menit.')->persistent()->send();
        }

        return redirect($kembali);
    }

    /**
     * "Baris 5 · cost_center — "XX" tidak ditemukan…" — satu butir per sel yang salah,
     * dibatasi 50 supaya notifikasinya tetap terbaca.
     *
     * @param  list<array{baris:string,kolom:string,pesan:string}>  $galat
     */
    private static function daftarGalat(array $galat): string
    {
        $butir = array_map(function ($g) {
            $letak = $g['baris'] === '—' ? '' : '<b>Baris '.e($g['baris']).' · kolom '.e($g['kolom']).'</b> — ';

            return '<li>'.$letak.e($g['pesan']).'</li>';
        }, array_slice($galat, 0, 50));
        $sisa = count($galat) > 50 ? '<li>… dan '.(count($galat) - 50).' kesalahan lain.</li>' : '';

        return 'Betulkan sel berikut di Excel, lalu import ulang:<ul style="margin-top:.4rem;list-style:disc;padding-left:1.2rem">'
            .implode('', $butir).$sisa.'</ul>';
    }
}
