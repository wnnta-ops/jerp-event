<?php

namespace App\Modules\FO\MarketingSales\Controllers;

use App\Modules\FO\MarketingSales\Models\Customer;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streaming berkas private customer (KTP/NPWP/KTP pasangan) untuk tim verifikasi.
 * File disimpan di disk 'local' (private) — hanya bisa diakses user login.
 * ?download=1 untuk paksa unduh; default tampil inline (preview gambar/PDF).
 */
class CustomerFileController
{
    public function show(Customer $customer, string $type): StreamedResponse
    {
        $map = [
            'ktp' => 'ktp_file_path',
            'npwp' => 'npwp_file_path',
            'ktp_pasangan' => 'ktp_pasangan_file_path',
        ];
        abort_unless(isset($map[$type]), 404);

        $path = $customer->{$map[$type]};
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Berkas tidak ditemukan.');

        $name = $customer->code.'-'.$type.'.'.pathinfo($path, PATHINFO_EXTENSION);

        return request()->boolean('download')
            ? Storage::disk('local')->download($path, $name)
            : Storage::disk('local')->response($path, $name);
    }

    /** Unduh SEMUA berkas customer (KTP + NPWP + KTP pasangan) sebagai satu ZIP. */
    public function zip(Customer $customer): mixed
    {
        $files = array_filter([
            'KTP' => $customer->ktp_file_path,
            'NPWP' => $customer->npwp_file_path,
            'KTP Pasangan' => $customer->ktp_pasangan_file_path,
        ], fn ($p) => $p && Storage::disk('local')->exists($p));

        abort_if(empty($files), 404, 'Customer ini belum punya berkas.');

        // Nama file ZIP pakai NAMA customer; isi di dalam ZIP pakai jenis berkas (KTP/NPWP/...).
        $nama = trim(preg_replace('/[^A-Za-z0-9 _.-]/', '', (string) ($customer->full_name ?: $customer->code))) ?: ($customer->code ?: 'customer');

        $tmp = tempnam(sys_get_temp_dir(), 'cust-berkas-');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $label => $path) {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            $zip->addFromString($label.($ext ? '.'.$ext : ''), Storage::disk('local')->get($path));
        }
        $zip->close();

        return response()->download($tmp, 'Berkas '.$nama.'.zip')->deleteFileAfterSend(true);
    }
}
