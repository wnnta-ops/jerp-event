<?php

namespace App\Filament\Fo\Pages\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Membaca jalur berkas fisik dari state Filament FileUpload.
 *
 * Dipakai halaman impor Excel (Unit, Price List, Koordinat) yang memakai
 * storeFiles(false): berkas TIDAK dipindahkan ke disk, state menyimpan
 * TemporaryUploadedFile apa adanya dan importer membacanya langsung.
 *
 * Sebelumnya ketiga halaman memakai ->disk('local')->directory(...), dan
 * pemindahan itulah yang gagal — state jadi kosong sehingga validasi
 * melaporkannya sebagai "field is required", pesan yang menyesatkan karena
 * berkasnya sendiri sudah terunggah utuh dan lolos validasi. Impor hanya perlu
 * membaca berkas sekali; menyalinnya dulu ke disk tidak memberi manfaat apa pun,
 * hanya menambah satu titik gagal.
 */
trait MembacaBerkasUnggahan
{
    /** @return string|null jalur absolut, atau null bila berkas tak terbaca */
    protected function jalurBerkas(mixed $state): ?string
    {
        // FileUpload kadang membungkus nilainya dalam array berkunci uuid.
        if (is_array($state)) {
            $state = reset($state) ?: null;
        }

        if ($state instanceof UploadedFile) {
            $jalur = $state->getRealPath();

            return is_string($jalur) && is_file($jalur) ? $jalur : null;
        }

        // Bentuk string: berkas yang terlanjur tersimpan di disk oleh versi sebelumnya.
        if (is_string($state) && $state !== '') {
            $jalur = Storage::disk('local')->path($state);

            return is_file($jalur) ? $jalur : null;
        }

        return null;
    }
}
