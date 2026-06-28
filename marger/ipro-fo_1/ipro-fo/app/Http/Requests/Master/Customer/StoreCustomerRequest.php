<?php

declare(strict_types=1);

namespace App\Http\Requests\Master\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('master.customer.create') ?? false;
    }

    /**
     * @return array<string, array<int, string|object>>
     */
    /** Bersihkan KTP/NPWP/telepon menjadi angka saja sebelum divalidasi. */
    protected function prepareForValidation(): void
    {
        $digits = fn ($v) => ($v === null || $v === '') ? $v : preg_replace('/\D/', '', (string) $v);
        $this->merge([
            'id_number' => $digits($this->input('id_number')),
            'npwp'      => $digits($this->input('npwp')),
            'phone'     => $digits($this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        $phoneRegex = 'regex:/^[\d+\-\s()]+$/';
        // KTP wajib 16 angka; identitas lain (Passport/SIM) cukup 6–32 karakter.
        $idRule = $this->input('id_type') === 'KTP' ? ['required', 'digits:16'] : ['required', 'string', 'min:6', 'max:32'];

        return [
            // ── 1. Identitas
            'project_id'     => ['nullable', 'integer', 'exists:projects,id'],
            'data_source_id' => ['nullable', 'integer', 'exists:data_sources,id'],
            'full_name'      => ['required', 'string', 'min:2', 'max:150'],
            'nationality'    => ['nullable', Rule::in(['WNI', 'WNA'])],

            // ── 2. Kartu Identitas
            'id_type'        => ['required', Rule::in(['KTP', 'Passport', 'SIM'])],
            'id_number'      => $idRule,
            'id_expires_at'  => ['nullable', 'date'],
            'id_lifetime'    => ['nullable', 'boolean'],
            'address_line'   => ['nullable', 'string', 'max:500'],
            'address_rt_rw'  => ['nullable', 'string', 'max:20'],
            'kelurahan'      => ['nullable', 'string', 'max:100'],
            'kecamatan'      => ['nullable', 'string', 'max:100'],
            'city'           => ['nullable', 'string', 'max:100'],
            'province'       => ['nullable', 'string', 'max:64'],
            'postal_code'    => ['nullable', 'string', 'max:12'],

            // ── 3. Data Pribadi
            'dob'            => ['nullable', 'date', 'before:today'],
            'birth_place'    => ['nullable', 'string', 'max:100'],
            'gender'         => ['nullable', Rule::in(['M', 'F'])],
            'religion'       => ['nullable', Rule::in(['islam','kristen','katolik','hindu','budha','konghucu','lainnya'])],
            'marital_status' => ['nullable', Rule::in(['menikah','cerai','belum_menikah','lain_lain'])],

            // ── 4. NPWP (wajib 16 angka)
            'npwp'                => ['required', 'digits:16'],
            'npwp_name'           => ['nullable', 'string', 'max:150'],
            'npwp_same_as_ktp'    => ['nullable', 'boolean'],
            'npwp_address'        => ['nullable', 'string', 'max:255'],
            'npwp_rt_rw'          => ['nullable', 'string', 'max:20'],
            'npwp_kelurahan'      => ['nullable', 'string', 'max:100'],
            'npwp_kecamatan'      => ['nullable', 'string', 'max:100'],
            'npwp_kota'           => ['nullable', 'string', 'max:100'],
            'npwp_postal_code'    => ['nullable', 'string', 'max:12'],

            // ── 5. Kontak (telepon wajib, minimal 12 angka)
            'phone'          => ['required', 'digits_between:12,15'],
            'phone_alt'      => ['nullable', 'string', $phoneRegex, 'max:30'],
            'home_phone'     => ['nullable', 'string', $phoneRegex, 'max:30'],
            'fax'            => ['nullable', 'string', $phoneRegex, 'max:30'],
            'email'          => ['nullable', 'email:rfc', 'max:150'],

            // ── 6. Alamat Surat Menyurat
            'mailing_same_as_ktp' => ['nullable', 'boolean'],
            'mailing_address'     => ['nullable', 'string', 'max:255'],
            'mailing_rt_rw'       => ['nullable', 'string', 'max:20'],
            'mailing_kelurahan'   => ['nullable', 'string', 'max:100'],
            'mailing_kecamatan'   => ['nullable', 'string', 'max:100'],
            'mailing_kota'        => ['nullable', 'string', 'max:100'],
            'mailing_postal_code' => ['nullable', 'string', 'max:12'],

            // ── 7. Pekerjaan
            'occupation'      => ['nullable', 'string', 'max:128'],
            'employer_name'   => ['nullable', 'string', 'max:128'],
            'job_title'       => ['nullable', 'string', 'max:100'],
            'monthly_income'  => ['nullable', 'numeric', 'min:0'],
            'office_phone'    => ['nullable', 'string', $phoneRegex, 'max:30'],
            'office_address'  => ['nullable', 'string', 'max:255'],
            'office_rt_rw'    => ['nullable', 'string', 'max:20'],
            'office_kelurahan'=> ['nullable', 'string', 'max:100'],
            'office_kecamatan'=> ['nullable', 'string', 'max:100'],
            'office_kota'     => ['nullable', 'string', 'max:100'],
            'office_postal_code' => ['nullable', 'string', 'max:12'],

            // ── 8. Emergency Contact
            'emergency_name'         => ['nullable', 'string', 'max:150'],
            'emergency_relationship' => ['nullable', Rule::in(['orang_tua','anak','suami','istri','saudara','teman','lainnya'])],
            'emergency_phone'        => ['nullable', 'string', $phoneRegex, 'max:30'],
            'emergency_email'        => ['nullable', 'email:rfc', 'max:150'],

            // Misc
            'channel'        => ['nullable', 'string', 'max:32'],
            'referrer_agent_id' => ['nullable', 'integer', 'exists:agents,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'full_name.required' => 'Nama lengkap wajib diisi.',
            'full_name.min'      => 'Nama minimal 2 karakter.',
            'id_type.required'   => 'Jenis identitas wajib dipilih.',
            'id_type.in'         => 'Jenis identitas tidak valid (KTP/Passport/SIM).',
            'id_number.required' => 'Nomor identitas wajib diisi.',
            'id_number.min'      => 'Nomor identitas minimal 6 digit.',
            'id_number.digits'   => 'Nomor KTP harus 16 angka.',
            'npwp.required'      => 'NPWP wajib diisi.',
            'npwp.digits'        => 'NPWP harus 16 angka.',
            'phone.required'     => 'Nomor telepon wajib diisi.',
            'phone.digits_between'=> 'Nomor telepon minimal 12 angka (hanya angka).',
            'dob.before'         => 'Tanggal lahir harus sebelum hari ini.',
            'email.email'        => 'Format email tidak valid.',
        ];
    }
}
