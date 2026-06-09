<?php

namespace App\Modules\FO\Launching\Services;

use App\Modules\FO\Launching\Events\NupRegistered;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Service for public NUP registration (BR-NUP, [04_BusinessFlow.md §2]).
 * Wraps creation of Customer (lead) + NupRegistration + file uploads + queue notification.
 * No NUP & urutan TIDAK diterbitkan di sini — keduanya terbit saat approval verifikasi.
 * UTJ (Xendit) langsung dibuat saat register dari Nilai "Jenis Tipe NUP" terpilih.
 */
class NupRegistrationService
{
    public function __construct(
        private readonly VerifikasiNupService $verifikasi,
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,UploadedFile|null>  $files  Keys: ktp, npwp, ktp_pasangan
     */
    public function register(NupEvent $event, array $data, array $files = []): NupRegistration
    {
        $utj = (float) ($data['utj_amount'] ?? 0);

        $registration = DB::transaction(function () use ($event, $data, $files, $utj) {
            // Project customer = project pilihan pendaftar, fallback project event.
            $data['project_id'] = $data['project_pref_id'] ?? $event->project_id;

            // Resolve or create Customer (lead)
            $customer = $this->resolveOrCreateLead($data);

            // Save uploaded documents
            $this->attachUploads($customer, $files);

            // Nomor urut NUP TIDAK dialokasikan saat pendaftaran. Urutan baru
            // diterbitkan setelah tim verifikasi menyetujui data (VerifikasiNupService::approve),
            // sehingga pendaftaran yang ditolak tidak memakan nomor antrian.
            return NupRegistration::create([
                'tenant_id' => $event->tenant_id,
                'nup_event_id' => $event->id,
                'project_pref_id' => $data['project_pref_id'] ?? $event->project_id,
                'agent_id' => $data['agent_id'] ?? null,
                'no_nup' => null,           // diterbitkan saat APPROVE
                'urutan' => null,           // diterbitkan saat APPROVE (FIFO per event)
                'customer_id' => $customer->id,
                'nama_pendaftar' => $data['full_name'],
                'nik' => $data['nik'] ?? null,
                'phone' => $data['mobile'] ?? $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'tgl_daftar' => now(),
                'status' => 'DAFTAR',
                'channel' => $data['channel'] ?? 'PUBLIC',
                'recaptcha_score' => $data['recaptcha_score'] ?? null,
                'ref_sales_name' => $data['ref_sales_name'] ?? null,
                'ref_sales_phone' => $data['ref_sales_phone'] ?? null,
                'tipe_nup' => $data['tipe_nup'] ?? null,
                'cara_bayar' => $data['cara_bayar'] ?? null,
                'utj_amount' => $utj,
                'preferensi_unit' => $data['preferensi_unit'] ?? null,
                'pilihan_budget' => $data['pilihan_budget'] ?? null,
                'pilihan_unit_1' => $data['pilihan_unit_1'] ?? null,
                'pilihan_unit_2' => $data['pilihan_unit_2'] ?? null,
                'pilihan_unit_3' => $data['pilihan_unit_3'] ?? null,
                'secondary_phone' => $data['secondary_phone'] ?? null,
                'pernyataan_setuju' => $data['pernyataan_setuju'] ?? false,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
            ]);
        });

        // UTJ mengikat saat register: buat invoice Xendit (panggilan HTTP di luar
        // transaksi DB). Jika gateway gagal, pendaftaran tetap sah — tim verifikasi
        // bisa membuat ulang invoice di "Verifikasi Data".
        if ($utj > 0) {
            try {
                $registration = $this->verifikasi->createXenditInvoice($registration, $utj);
            } catch (Throwable $e) {
                Log::error('Gagal membuat invoice UTJ saat register NUP', [
                    'nup_registration_id' => $registration->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Domain event — handlers enqueue notifikasi email/WA (berisi link bayar UTJ).
        event(new NupRegistered($registration));

        return $registration;
    }

    /**
     * Reuse customer by NIK if exists, otherwise create as LEAD.
     */
    private function resolveOrCreateLead(array $data): Customer
    {
        if (! empty($data['nik'])) {
            $existing = Customer::withoutGlobalScopes()
                ->where('tenant_id', $data['tenant_id'] ?? 1)
                ->where('nik', $data['nik'])
                ->first();
            if ($existing) {
                // Lengkapi data yang belum ada (project + No. Customer).
                $patch = [];
                if (empty($existing->project_id) && ! empty($data['project_id'])) {
                    $patch['project_id'] = $data['project_id'];
                }
                if (empty($existing->code)) {
                    $patch['code'] = $this->numerator->next('CUST');
                }
                if ($patch) {
                    $existing->update($patch);
                }

                return $existing;
            }
        }

        return Customer::create([
            'tenant_id' => $data['tenant_id'] ?? 1,
            // No. Customer terbit langsung saat daftar NUP (bukan nunggu approval).
            'code' => $this->numerator->next('CUST'),
            'project_id' => $data['project_id'] ?? null,
            'full_name' => $data['full_name'],
            'nik' => $data['nik'] ?? null,
            'npwp' => $data['npwp'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'birth_place' => $data['birth_place'] ?? null,
            'gender' => $data['gender'] ?? null,
            'marital_status' => $data['marital_status'] ?? null,
            'religion' => $data['religion'] ?? null,
            'phone' => $data['phone'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'jalan' => $data['jalan'] ?? null,
            'rt_rw' => $data['rt_rw'] ?? null,
            'kelurahan' => $data['kelurahan'] ?? null,
            'kecamatan' => $data['kecamatan'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'mail_same_as_ktp' => $data['mail_same_as_ktp'] ?? true,
            'mail_address' => $data['mail_address'] ?? null,
            'mail_city' => $data['mail_city'] ?? null,
            'mail_province' => $data['mail_province'] ?? null,
            'mail_postal_code' => $data['mail_postal_code'] ?? null,
            'spouse_full_name' => $data['spouse_full_name'] ?? null,
            'spouse_nik' => $data['spouse_nik'] ?? null,
            'spouse_email' => $data['spouse_email'] ?? null,
            'spouse_phone' => $data['spouse_phone'] ?? null,
            'spouse_birth_place' => $data['spouse_birth_place'] ?? null,
            'spouse_birth_date' => $data['spouse_birth_date'] ?? null,
            'spouse_occupation' => $data['spouse_occupation'] ?? null,
            'spouse_address_same_as_pemohon' => $data['spouse_address_same_as_pemohon'] ?? true,
            'spouse_address' => $data['spouse_address'] ?? null,
            'occupation' => $data['occupation'] ?? null,
            'sumber_data' => $data['sumber_data'] ?? null,
            'lead_status' => 'LEAD',
            'is_active' => true,
        ]);
    }

    /**
     * Persist uploaded files into private storage and update customer columns.
     *
     * @param  array<string,UploadedFile|null>  $files
     */
    private function attachUploads(Customer $customer, array $files): void
    {
        $disk = 'local';
        $folder = 'nup/'.$customer->id;

        $updates = [];
        foreach (['ktp' => 'ktp_file_path', 'npwp' => 'npwp_file_path', 'ktp_pasangan' => 'ktp_pasangan_file_path'] as $key => $column) {
            $file = $files[$key] ?? null;
            if ($file instanceof UploadedFile) {
                $path = $file->store($folder, $disk);
                $updates[$column] = $path;
            }
        }

        if ($updates) {
            $customer->update($updates);
        }
    }
}
