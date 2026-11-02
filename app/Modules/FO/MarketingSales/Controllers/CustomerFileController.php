<?php

namespace App\Modules\FO\MarketingSales\Controllers;

use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Support\TenantContext;
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
        $this->authorizeTenant($customer);

        $map = [
            'ktp' => 'ktp_file_path',
            'npwp' => 'npwp_file_path',
            'ktp_pasangan' => 'ktp_pasangan_file_path',
            'npwp_pasangan' => 'npwp_pasangan_file_path',
        ];
        abort_unless(isset($map[$type]), 404);

        $path = $customer->{$map[$type]};
        abort_unless($this->ada($path), 404, 'Berkas tidak ditemukan.');

        $name = $customer->code.'-'.$type.'.'.pathinfo($path, PATHINFO_EXTENSION);

        return request()->boolean('download')
            ? Storage::disk('local')->download($path, $name)
            : Storage::disk('local')->response($path, $name);
    }

    /** Unduh SEMUA berkas customer (KTP + NPWP + KTP/NPWP pasangan) sebagai satu ZIP. */
    public function zip(Customer $customer): mixed
    {
        $this->authorizeTenant($customer);

        $files = $this->berkasCustomer($customer);

        abort_if(empty($files), 404, 'Customer ini belum punya berkas.');

        // Nama file ZIP pakai NAMA customer; isi di dalam ZIP pakai jenis berkas (KTP/NPWP/...).
        $nama = $this->namaAman((string) ($customer->full_name ?: $customer->code)) ?: ($customer->code ?: 'customer');

        return $this->kirimZip(['' => $files], 'Berkas '.$nama.'.zip');
    }

    /**
     * Verifikasi Booked — KTP/NPWP customer + Bukti UTJ dalam satu ZIP. ?ids=1,2,3:
     * satu pendaftar → isi ZIP rata; beberapa (aksi massal) → satu folder per pendaftar.
     */
    public function bookedZip(): mixed
    {
        $ids = array_filter(array_map('intval', explode(',', (string) request('ids'))));
        abort_if(empty($ids), 404);

        $regs = NupRegistration::query()->with('customer')->whereIn('id', $ids)
            ->where('tenant_id', app(TenantContext::class)->id())->orderBy('nama_pendaftar')->get();

        $folders = [];
        foreach ($regs as $r) {
            $files = $r->customer ? $this->berkasCustomer($r->customer) : [];
            if ($this->ada($r->bukti_transfer_path)) {
                $files['Bukti UTJ'] = $r->bukti_transfer_path;
            }
            if ($files) {
                // id ikut di nama folder: dua pendaftar bisa bernama sama.
                $folders[($this->namaAman((string) $r->nama_pendaftar) ?: 'pendaftar').' ('.$r->id.')'] = $files;
            }
        }
        abort_if(empty($folders), 404, 'Belum ada berkas untuk pendaftar yang dipilih.');

        if (count($regs) === 1) {
            return $this->kirimZip(['' => reset($folders)], 'Berkas '.($this->namaAman((string) $regs[0]->nama_pendaftar) ?: 'pendaftar').'.zip');
        }

        return $this->kirimZip($folders, 'Berkas Booked '.now()->format('Y-m-d His').'.zip');
    }

    /** @return array<string,string> label → path, hanya berkas yang benar-benar ada. */
    private function berkasCustomer(Customer $customer): array
    {
        return array_filter([
            'KTP' => $customer->ktp_file_path,
            'NPWP' => $customer->npwp_file_path,
            'KTP Pasangan' => $customer->ktp_pasangan_file_path,
            'NPWP Pasangan' => $customer->npwp_pasangan_file_path,
        ], fn ($p) => $this->ada($p));
    }

    /**
     * Berkas ada di disk? Customer hasil migrasi eProperty menyimpan path Windows lama
     * ("C:\IPRO\...") yang membuat Flysystem melempar CorruptedPathDetected → dianggap tidak ada.
     */
    private function ada(?string $path): bool
    {
        try {
            return (bool) $path && Storage::disk('local')->exists($path);
        } catch (\League\Flysystem\FilesystemException) {
            return false;
        }
    }

    private function namaAman(string $s): string
    {
        return trim(preg_replace('/[^A-Za-z0-9 _.-]/', '', $s));
    }

    /** @param array<string, array<string,string>> $folders folder ('' = akar) → [label → path] */
    private function kirimZip(array $folders, string $namaZip): mixed
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cust-berkas-');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($folders as $folder => $files) {
            foreach ($files as $label => $path) {
                $ext = pathinfo($path, PATHINFO_EXTENSION);
                $zip->addFromString(($folder !== '' ? $folder.'/' : '').$label.($ext ? '.'.$ext : ''), Storage::disk('local')->get($path));
            }
        }
        $zip->close();

        return response()->download($tmp, $namaZip)->deleteFileAfterSend(true);
    }

    /** Bukti transfer Booked Manual (tersimpan di registrasi). ?download=1 untuk unduh. */
    public function bookedBukti(NupRegistration $registration): mixed
    {
        abort_unless((int) $registration->tenant_id === app(TenantContext::class)->id(), 403);

        $path = $registration->bukti_transfer_path;
        abort_unless($this->ada($path), 404, 'Bukti transfer tidak ditemukan.');

        $name = 'bukti-transfer-'.$registration->id.'.'.pathinfo($path, PATHINFO_EXTENSION);

        return request()->boolean('download')
            ? Storage::disk('local')->download($path, $name)
            : Storage::disk('local')->response($path, $name);
    }

    /**
     * Cegah akses berkas customer lintas-tenant (IDOR). Route hanya untuk user
     * login (middleware auth); guard ini memastikan customer di tenant yang sama.
     * Catatan: pembatasan per-role (hanya tim verifikasi) menyusul saat RBAC siap.
     */
    private function authorizeTenant(Customer $customer): void
    {
        abort_unless((int) $customer->tenant_id === app(TenantContext::class)->id(), 403);
    }
}
