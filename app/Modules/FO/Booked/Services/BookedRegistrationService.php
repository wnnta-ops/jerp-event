<?php

namespace App\Modules\FO\Booked\Services;

use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pendaftaran Booked Manual (di luar launching). Reuse fo_nup_registrations
 * dengan jenis=BOOKED: tanpa event, tanpa UTJ/Xendit. Pembayaran via transfer
 * bank → diverifikasi manual oleh tim (cek mutasi) sebelum approve → SPRS.
 */
class BookedRegistrationService
{
    public function __construct(
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,UploadedFile|null>  $files  Keys: ktp, npwp, ktp_pasangan
     */
    public function register(array $data, array $files = []): NupRegistration
    {
        return DB::transaction(function () use ($data, $files) {
            $data['tenant_id'] = $data['tenant_id'] ?? 1;
            $data['project_id'] = $data['project_pref_id'] ?? null;

            $customer = $this->resolveOrCreateLead($data);
            $this->attachUploads($customer, $files);

            $reg = NupRegistration::create([
                'tenant_id' => $data['tenant_id'],
                'jenis' => 'BOOKED',
                'nup_event_id' => null,           // booked manual: tanpa event
                'project_pref_id' => $data['project_pref_id'] ?? null,
                'agent_id' => $data['agent_id'] ?? null,
                'no_nup' => null,
                'urutan' => null,
                'customer_id' => $customer->id,
                'nama_pendaftar' => $data['full_name'],
                'nik' => $data['nik'] ?? null,
                'phone' => $data['mobile'] ?? $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'tgl_daftar' => now(),
                'status' => 'DAFTAR',
                'verif_status' => 'MENUNGGU_BAYAR',   // menunggu verifikasi transfer manual
                'channel' => $data['channel'] ?? 'PUBLIC',
                'recaptcha_score' => $data['recaptcha_score'] ?? null,
                'ref_sales_name' => $data['ref_sales_name'] ?? null,
                'ref_sales_phone' => $data['ref_sales_phone'] ?? null,
                'cara_bayar' => $data['cara_bayar'] ?? 'TRANSFER',
                'utj_amount' => 0,
                'pilihan_unit_1' => $data['pilihan_unit_1'] ?? null,   // unit yang diinginkan
                'secondary_phone' => $data['secondary_phone'] ?? null,
                'pernyataan_setuju' => $data['pernyataan_setuju'] ?? false,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
            ]);

            // Bukti transfer disimpan di registrasi (bisa dilihat/unduh verifikator).
            $bukti = $files['bukti_transfer'] ?? null;
            if ($bukti instanceof UploadedFile) {
                $reg->update(['bukti_transfer_path' => $bukti->store('booked/bukti/'.$customer->id, 'local')]);
            }

            return $reg;
        });
    }

    /**
     * Tolak bila NIK sudah dipakai customer lain dengan nama berbeda (1 NIK = 1 nama).
     * Perbandingan mengabaikan beda kapital & spasi berganda. NIK beda + nama sama
     * tetap boleh (dua orang berbeda); hanya NIK sama + nama beda yang ditolak.
     */
    private function pastikanNamaCocok(Customer $existing, array $data): void
    {
        $norm = fn (?string $n): string => preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) $n)));
        $daftar = $norm($data['full_name'] ?? '');
        $pemilik = $norm($existing->full_name);

        if ($daftar !== '' && $pemilik !== '' && $daftar !== $pemilik) {
            throw ValidationException::withMessages([
                'nik' => 'NIK '.($data['nik'] ?? '').' sudah terdaftar atas nama "'.$existing->full_name.'". Nama harus sama dengan pemilik NIK ini.',
            ]);
        }
    }

    private function resolveOrCreateLead(array $data): Customer
    {
        if (! empty($data['nik'])) {
            $existing = Customer::withoutGlobalScopes()
                ->where('tenant_id', $data['tenant_id'] ?? 1)
                ->where('nik', $data['nik'])
                ->first();
            if ($existing) {
                // 1 NIK = 1 nama customer. Nama yang didaftarkan harus sama dengan
                // pemilik NIK ini — kalau beda → tolak (NIK tak boleh atas nama lain).
                $this->pastikanNamaCocok($existing, $data);

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
            'mail_kecamatan' => $data['mail_kecamatan'] ?? null,
            'mail_kelurahan' => $data['mail_kelurahan'] ?? null,
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

    /** @param array<string,UploadedFile|null> $files */
    private function attachUploads(Customer $customer, array $files): void
    {
        $updates = [];
        foreach (['ktp' => 'ktp_file_path', 'npwp' => 'npwp_file_path', 'ktp_pasangan' => 'ktp_pasangan_file_path'] as $key => $column) {
            $file = $files[$key] ?? null;
            if ($file instanceof UploadedFile) {
                $updates[$column] = $file->store('booked/'.$customer->id, 'local');
            }
        }
        if ($updates) {
            $customer->update($updates);
        }
    }
}
