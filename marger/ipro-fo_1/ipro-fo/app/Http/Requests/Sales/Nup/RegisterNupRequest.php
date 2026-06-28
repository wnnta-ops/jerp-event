<?php

declare(strict_types=1);

namespace App\Http\Requests\Sales\Nup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterNupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sales.nup.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $phone = 'regex:/^[\d+\-\s()]+$/';

        return [
            'nup_scheme_id'  => ['required', 'integer', 'exists:nup_schemes,id'],
            'project_id'     => ['nullable', 'integer', 'exists:projects,id'],
            'data_source_id' => ['nullable', 'integer', 'exists:data_sources,id'],

            // Data pemesan
            'full_name'      => ['required', 'string', 'min:2', 'max:150'],
            'id_number'      => ['required', 'string', 'min:6', 'max:32'],
            'birth_place'    => ['required', 'string', 'max:100'],
            'dob'            => ['required', 'date', 'before:today'],
            'gender'         => ['nullable', Rule::in(['M', 'F'])],
            'marital_status' => ['required', Rule::in(['menikah', 'cerai', 'belum_menikah', 'lain_lain'])],
            'email'          => ['required', 'email:rfc', 'max:150'],
            'phone'          => ['required', 'string', $phone, 'max:30'],
            'phone_alt'      => ['nullable', 'string', $phone, 'max:30'],
            'occupation'     => ['required', 'string', 'max:128'],
            'npwp'           => ['nullable', 'string', 'max:32'],
            'address'        => ['required', 'string', 'max:500'],
            'rt_rw'          => ['nullable', 'string', 'max:20'],
            'province'       => ['required', 'string', 'max:64'],
            'city'           => ['required', 'string', 'max:100'],
            'kecamatan'      => ['required', 'string', 'max:100'],
            'kelurahan'      => ['required', 'string', 'max:100'],

            // Preferensi & pembayaran
            'preferensi_unit'   => ['required', 'array', 'min:1'],
            'preferensi_unit.*' => ['string', 'max:64'],
            'pilihan_unit_1'    => ['nullable', 'string', 'max:64'],
            'pilihan_unit_2'    => ['nullable', 'string', 'max:64'],
            'pilihan_unit_3'    => ['nullable', 'string', 'max:64'],
            'cara_bayar'        => ['required', Rule::in(['Cash', 'In House', 'KPR'])],
            'jenis_nup'         => ['nullable', 'string', 'max:64'],

            // Sales
            'sales_name'  => ['nullable', 'string', 'max:150'],
            'sales_phone' => ['nullable', 'string', $phone, 'max:30'],

            // Pasangan (opsional)
            'spouse'                 => ['nullable', 'array'],
            'spouse.full_name'       => ['nullable', 'string', 'max:150'],
            'spouse.id_number'       => ['nullable', 'string', 'max:32'],
            'spouse.phone'           => ['nullable', 'string', $phone, 'max:30'],
            'spouse.email'           => ['nullable', 'email:rfc', 'max:150'],
            'spouse.birth_place'     => ['nullable', 'string', 'max:100'],
            'spouse.dob'             => ['nullable', 'date'],
            'spouse.occupation'      => ['nullable', 'string', 'max:128'],
            'spouse.address'         => ['nullable', 'string', 'max:500'],

            // Surat menyurat (opsional)
            'mailing'             => ['nullable', 'array'],
            'mailing.same_as_ktp' => ['nullable', 'boolean'],
            'mailing.address'     => ['nullable', 'string', 'max:500'],
            'mailing.rt_rw'       => ['nullable', 'string', 'max:20'],
            'mailing.province'    => ['nullable', 'string', 'max:64'],
            'mailing.city'        => ['nullable', 'string', 'max:100'],
            'mailing.kecamatan'   => ['nullable', 'string', 'max:100'],
            'mailing.kelurahan'   => ['nullable', 'string', 'max:100'],

            // Upload (opsional)
            'ktp_file'  => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'npwp_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],

            // Disclaimer wajib dicentang
            'disclaimer' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preferensi_unit.required' => 'Pilih minimal satu preferensi unit.',
            'disclaimer.accepted'      => 'Anda harus menyetujui pernyataan data valid.',
            'cara_bayar.in'            => 'Cara bayar tidak valid (Cash / In House / KPR).',
        ];
    }
}
