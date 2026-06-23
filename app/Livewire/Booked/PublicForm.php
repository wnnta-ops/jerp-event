<?php

namespace App\Livewire\Booked;

use App\Models\Project;
use App\Modules\FO\Adminjual\Models\SumberData;
use App\Modules\FO\Booked\Services\BookedRegistrationService;
use App\Modules\FO\Komisi\Models\Agent;
use App\Services\Recaptcha\RecaptchaVerifier;
use App\Settings\FoSettings;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Booked Manual — pendaftaran booking di luar launching. Sama dengan form NUP,
 * bedanya: tanpa event, tanpa UTJ/Xendit, dan "Unit Diinginkan" diisi langsung
 * (textbox). Verifikasi manual (cek mutasi bank), approval → SPRS → SPR.
 */
class PublicForm extends Component
{
    use WithFileUploads;

    public ?int $project_pref_id = null;

    public ?string $sumber_data = null;

    public string $full_name = '';

    public string $nik = '';

    public ?string $npwp = null;

    public ?string $birth_place = null;

    public ?string $birth_date = null;

    public ?string $gender = null;

    public ?string $marital_status = null;

    public ?string $religion = null;

    public ?string $occupation = null;

    public string $mobile = '';

    public ?string $phone = null;

    public bool $show_secondary_phone = false;

    public ?string $secondary_phone = null;

    public ?string $email = null;

    // Alamat KTP
    public ?string $jalan = null;

    public ?string $rt_rw = null;

    public ?string $kelurahan = null;

    public ?string $kecamatan = null;

    public ?string $city = null;

    public ?string $province = null;

    public ?string $postal_code = null;

    // Alamat surat
    public bool $mail_same_as_ktp = true;

    public ?string $mail_address = null;

    public ?string $mail_city = null;

    public ?string $mail_province = null;

    public ?string $mail_kecamatan = null;

    public ?string $mail_kelurahan = null;

    public ?string $mail_postal_code = null;

    // Pasangan (kondisional saat KAWIN)
    public ?string $spouse_full_name = null;

    public ?string $spouse_nik = null;

    public ?string $spouse_email = null;

    public ?string $spouse_phone = null;

    public ?string $spouse_birth_place = null;

    public ?string $spouse_birth_date = null;

    public ?string $spouse_occupation = null;

    public bool $spouse_address_same_as_pemohon = true;

    public ?string $spouse_address = null;

    // Unit langsung diinginkan (textbox) — unit asli dipilih verifikator.
    public ?string $unit_diinginkan = null;

    public ?string $cara_bayar = null;

    public ?int $agent_id = null;

    public ?string $ref_sales_name = null;

    public ?string $ref_sales_phone = null;

    // Files
    public $ktp_file = null;

    public $npwp_file = null;

    public $ktp_pasangan_file = null;

    public $bukti_transfer_file = null;

    public ?string $recaptcha_token = null;

    public bool $pernyataan_setuju = false;

    public bool $submitted = false;

    // Langkah pratinjau: klik Register → tampil preview data & dokumen → konfirmasi baru daftar.
    public bool $reviewing = false;

    public function updatedKtpFile(): void
    {
        $this->validateOnly('ktp_file');
    }

    public function updatedNpwpFile(): void
    {
        $this->validateOnly('npwp_file');
    }

    public function updatedRefSalesName(): void
    {
        $value = trim((string) $this->ref_sales_name);
        $match = Agent::query()->where('is_active', true)->salesPicker()->get()
            ->first(fn (Agent $a) => $a->sales_label === $value);

        $this->agent_id = $match?->id;
        if ($match && blank($this->ref_sales_phone)) {
            $this->ref_sales_phone = $match->phone ?: $match->whatsapp;
        }
    }

    protected function rules(): array
    {
        return [
            'project_pref_id' => ['required', 'exists:projects,id'],
            'full_name' => ['required', 'string', 'max:255'],
            'nik' => ['required', 'string', 'digits:16'],
            'npwp' => ['nullable', 'string', 'max:32'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['L', 'P'])],
            'marital_status' => ['nullable', Rule::in(['BELUM', 'KAWIN', 'CERAI'])],
            'religion' => ['nullable', 'string', 'max:32'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'min:8', 'max:32'],
            'phone' => ['nullable', 'string', 'max:32'],
            'show_secondary_phone' => ['boolean'],
            'secondary_phone' => ['nullable', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
            'jalan' => ['nullable', 'string', 'max:255'],
            'rt_rw' => ['nullable', 'string', 'max:16'],
            'kelurahan' => ['nullable', 'string', 'max:255'],
            'kecamatan' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'mail_same_as_ktp' => ['boolean'],
            'mail_address' => ['nullable', 'required_if:mail_same_as_ktp,false', 'string'],
            'mail_city' => ['nullable', 'required_if:mail_same_as_ktp,false', 'string'],
            'mail_province' => ['nullable', 'required_if:mail_same_as_ktp,false', 'string'],
            'mail_kecamatan' => ['nullable', 'string', 'max:255'],
            'mail_kelurahan' => ['nullable', 'string', 'max:255'],
            'mail_postal_code' => ['nullable', 'string', 'max:16'],

            'spouse_full_name' => ['nullable', 'required_if:marital_status,KAWIN', 'string', 'max:255'],
            'spouse_nik' => ['nullable', 'required_if:marital_status,KAWIN', 'string', 'digits:16'],
            'spouse_email' => ['nullable', 'email'],
            'spouse_phone' => ['nullable', 'string', 'max:32'],
            'spouse_birth_place' => ['nullable', 'string'],
            'spouse_birth_date' => ['nullable', 'date'],
            'spouse_occupation' => ['nullable', 'string'],
            'spouse_address_same_as_pemohon' => ['boolean'],
            'spouse_address' => ['nullable', 'required_if:spouse_address_same_as_pemohon,false', 'string'],

            'unit_diinginkan' => ['required', 'string', 'max:255'],
            'cara_bayar' => ['nullable', 'string', 'max:32'],
            'agent_id' => ['nullable', 'exists:fo_agents,id'],
            'ref_sales_name' => ['required', 'string', 'max:255'],
            'ref_sales_phone' => ['required', 'string', 'max:32'],

            'ktp_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'npwp_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'ktp_pasangan_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'bukti_transfer_file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],

            'pernyataan_setuju' => ['accepted'],
            'sumber_data' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function messages(): array
    {
        return [
            'pernyataan_setuju.accepted' => 'Anda harus menyatakan data benar.',
            'nik.digits' => 'NIK harus 16 digit.',
            'spouse_nik.digits' => 'NIK pasangan harus 16 digit.',
            'ktp_file.required' => 'Foto KTP wajib di-upload.',
            'bukti_transfer_file.required' => 'Bukti transfer wajib di-upload.',
            'unit_diinginkan.required' => 'Unit yang diinginkan wajib diisi.',
        ];
    }

    /** Langkah 1: validasi → tampilkan pratinjau data & dokumen (belum mendaftar). */
    public function review(): void
    {
        try {
            $this->validate();
        } catch (ValidationException $e) {
            $first = collect($e->errors())->keys()->first();
            $this->dispatch('nup-validation-failed', field: (string) $first, count: count($e->errors()));
            throw $e;
        }

        $this->reviewing = true;
        $this->dispatch('scroll-to-top');
    }

    /** Kembali dari pratinjau ke form untuk perbaiki data. */
    public function backToForm(): void
    {
        $this->reviewing = false;
    }

    public function submit(BookedRegistrationService $service, RecaptchaVerifier $recaptcha, FoSettings $settings): void
    {
        try {
            $this->validate();
        } catch (ValidationException $e) {
            $first = collect($e->errors())->keys()->first();
            $this->dispatch('nup-validation-failed', field: (string) $first, count: count($e->errors()));
            throw $e;
        }

        if ($settings->recaptcha_enabled) {
            $score = $recaptcha->verify($this->recaptcha_token, action: 'booked_daftar', ip: request()->ip());
            if ($score === null || $score < $settings->recaptcha_min_score) {
                $this->addError('recaptcha_token', 'Verifikasi keamanan gagal. Muat ulang halaman dan coba lagi.');

                return;
            }
        } else {
            $score = null;
        }

        try {
            $service->register(
                data: [
                    'project_pref_id' => $this->project_pref_id,
                    'sumber_data' => $this->sumber_data,
                    'full_name' => $this->full_name,
                    'nik' => $this->nik,
                    'npwp' => $this->npwp,
                    'birth_place' => $this->birth_place,
                    'birth_date' => $this->birth_date,
                    'gender' => $this->gender,
                    'marital_status' => $this->marital_status,
                    'religion' => $this->religion,
                    'occupation' => $this->occupation,
                    'mobile' => $this->mobile,
                    'phone' => $this->phone,
                    'secondary_phone' => $this->show_secondary_phone ? $this->secondary_phone : null,
                    'email' => $this->email,
                    'jalan' => $this->jalan,
                    'rt_rw' => $this->rt_rw,
                    'kelurahan' => $this->kelurahan,
                    'kecamatan' => $this->kecamatan,
                    'city' => $this->city,
                    'province' => $this->province,
                    'postal_code' => $this->postal_code,
                    'mail_same_as_ktp' => $this->mail_same_as_ktp,
                    'mail_address' => $this->mail_address,
                    'mail_city' => $this->mail_city,
                    'mail_kecamatan' => $this->mail_kecamatan,
                    'mail_kelurahan' => $this->mail_kelurahan,
                    'mail_province' => $this->mail_province,
                    'mail_postal_code' => $this->mail_postal_code,
                    'spouse_full_name' => $this->spouse_full_name,
                    'spouse_nik' => $this->spouse_nik,
                    'spouse_email' => $this->spouse_email,
                    'spouse_phone' => $this->spouse_phone,
                    'spouse_birth_place' => $this->spouse_birth_place,
                    'spouse_birth_date' => $this->spouse_birth_date,
                    'spouse_occupation' => $this->spouse_occupation,
                    'spouse_address_same_as_pemohon' => $this->spouse_address_same_as_pemohon,
                    'spouse_address' => $this->spouse_address,
                    'cara_bayar' => $this->cara_bayar,
                    'agent_id' => $this->agent_id,
                    'ref_sales_name' => $this->ref_sales_name,
                    'ref_sales_phone' => $this->ref_sales_phone,
                    'pilihan_unit_1' => $this->unit_diinginkan,    // unit yang diinginkan
                    'channel' => 'PUBLIC',
                    'recaptcha_score' => $score !== null ? (string) $score : null,
                    'pernyataan_setuju' => $this->pernyataan_setuju,
                    'ip_address' => request()->ip(),
                    'user_agent' => substr((string) request()->userAgent(), 0, 512),
                ],
                files: [
                    'ktp' => $this->ktp_file,
                    'npwp' => $this->npwp_file,
                    'ktp_pasangan' => $this->ktp_pasangan_file,
                    'bukti_transfer' => $this->bukti_transfer_file,
                ],
            );
        } catch (ValidationException $e) {
            // Error aturan NIK (1 NIK = 1 nama) dari service → tampil di field NIK
            // + scroll ke field-nya, sama seperti validasi form lainnya.
            $first = collect($e->errors())->keys()->first();
            $this->dispatch('nup-validation-failed', field: (string) $first, count: count($e->errors()));
            throw $e;
        }

        $this->submitted = true;
    }

    public function render()
    {
        return view('livewire.booked.public-form', [
            'projects' => Project::query()->where('is_active', true)->orderBy('name')->get(),
            'agents' => Agent::query()->where('is_active', true)->salesPicker()->orderBy('name')->get(),
            'sumberDataOptions' => SumberData::query()->where('is_active', true)->orderBy('urutan')->orderBy('nama')->get(),
            'settings' => app(FoSettings::class),
        ])->layout('layouts.nup-public', ['title' => 'Pendaftaran Booked', 'systemName' => 'BOOKED SYSTEM']);
    }
}
