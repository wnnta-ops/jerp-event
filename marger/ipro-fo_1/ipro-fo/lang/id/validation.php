<?php

declare(strict_types=1);

/*
 * Pesan validasi bahasa Indonesia.
 *
 * Aplikasi ini ber-locale 'id' (config/app.php) tetapi belum punya berkas terjemahan sama
 * sekali, sehingga SETIAP pesan validasi tampil mentah sebagai kunci — mis. "validation.required"
 * pada form Set Up Barcode. Berkas ini memperbaikinya untuk seluruh form.
 */

return [
    'accepted' => ':attribute wajib disetujui.',
    'active_url' => ':attribute bukan URL yang sah.',
    'after' => ':attribute harus tanggal setelah :date.',
    'after_or_equal' => ':attribute harus tanggal setelah atau sama dengan :date.',
    'alpha' => ':attribute hanya boleh berisi huruf.',
    'alpha_dash' => ':attribute hanya boleh berisi huruf, angka, strip, dan garis bawah.',
    'alpha_num' => ':attribute hanya boleh berisi huruf dan angka.',
    'array' => ':attribute harus berupa daftar.',
    'before' => ':attribute harus tanggal sebelum :date.',
    'before_or_equal' => ':attribute harus tanggal sebelum atau sama dengan :date.',
    'between' => [
        'array' => ':attribute harus berisi antara :min dan :max item.',
        'file' => ':attribute harus berukuran antara :min dan :max kilobyte.',
        'numeric' => ':attribute harus bernilai antara :min dan :max.',
        'string' => ':attribute harus antara :min dan :max karakter.',
    ],
    'boolean' => ':attribute harus bernilai ya atau tidak.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'date' => ':attribute bukan tanggal yang sah.',
    'date_equals' => ':attribute harus tanggal yang sama dengan :date.',
    'date_format' => ':attribute tidak sesuai format :format.',
    'different' => ':attribute dan :other harus berbeda.',
    'digits' => ':attribute harus terdiri dari :digits angka.',
    'digits_between' => ':attribute harus terdiri dari :min sampai :max angka.',
    'dimensions' => ':attribute memiliki dimensi gambar yang tidak sesuai.',
    'distinct' => ':attribute memiliki nilai yang kembar.',
    'email' => ':attribute harus berupa alamat email yang sah.',
    'ends_with' => ':attribute harus diakhiri salah satu dari: :values.',
    'exists' => ':attribute yang dipilih tidak sah.',
    'file' => ':attribute harus berupa berkas.',
    'filled' => ':attribute wajib diisi.',
    'gt' => [
        'array' => ':attribute harus berisi lebih dari :value item.',
        'file' => ':attribute harus lebih besar dari :value kilobyte.',
        'numeric' => ':attribute harus lebih besar dari :value.',
        'string' => ':attribute harus lebih panjang dari :value karakter.',
    ],
    'gte' => [
        'array' => ':attribute harus berisi :value item atau lebih.',
        'file' => ':attribute harus :value kilobyte atau lebih.',
        'numeric' => ':attribute harus :value atau lebih.',
        'string' => ':attribute harus :value karakter atau lebih.',
    ],
    'image' => ':attribute harus berupa gambar.',
    'in' => ':attribute yang dipilih tidak sah.',
    'in_array' => ':attribute tidak ada di dalam :other.',
    'integer' => ':attribute harus berupa bilangan bulat.',
    'ip' => ':attribute harus berupa alamat IP yang sah.',
    'ipv4' => ':attribute harus berupa alamat IPv4 yang sah.',
    'ipv6' => ':attribute harus berupa alamat IPv6 yang sah.',
    'json' => ':attribute harus berupa JSON yang sah.',
    'lt' => [
        'array' => ':attribute harus berisi kurang dari :value item.',
        'file' => ':attribute harus lebih kecil dari :value kilobyte.',
        'numeric' => ':attribute harus lebih kecil dari :value.',
        'string' => ':attribute harus lebih pendek dari :value karakter.',
    ],
    'lte' => [
        'array' => ':attribute tidak boleh lebih dari :value item.',
        'file' => ':attribute harus :value kilobyte atau kurang.',
        'numeric' => ':attribute harus :value atau kurang.',
        'string' => ':attribute harus :value karakter atau kurang.',
    ],
    'max' => [
        'array' => ':attribute tidak boleh lebih dari :max item.',
        'file' => ':attribute tidak boleh lebih dari :max kilobyte.',
        'numeric' => ':attribute tidak boleh lebih dari :max.',
        'string' => ':attribute tidak boleh lebih dari :max karakter.',
    ],
    'mimes' => ':attribute harus berupa berkas berjenis: :values.',
    'mimetypes' => ':attribute harus berupa berkas berjenis: :values.',
    'min' => [
        'array' => ':attribute harus berisi minimal :min item.',
        'file' => ':attribute harus berukuran minimal :min kilobyte.',
        'numeric' => ':attribute harus bernilai minimal :min.',
        'string' => ':attribute harus terdiri minimal :min karakter.',
    ],
    'not_in' => ':attribute yang dipilih tidak sah.',
    'not_regex' => 'Format :attribute tidak sah.',
    'numeric' => ':attribute harus berupa angka.',
    'present' => ':attribute wajib ada.',
    'regex' => 'Format :attribute tidak sah.',
    'required' => ':attribute wajib diisi.',
    'required_if' => ':attribute wajib diisi bila :other adalah :value.',
    'required_unless' => ':attribute wajib diisi kecuali :other ada di dalam :values.',
    'required_with' => ':attribute wajib diisi bila terdapat :values.',
    'required_with_all' => ':attribute wajib diisi bila terdapat :values.',
    'required_without' => ':attribute wajib diisi bila tidak terdapat :values.',
    'required_without_all' => ':attribute wajib diisi bila tidak terdapat satupun :values.',
    'same' => ':attribute dan :other harus sama.',
    'size' => [
        'array' => ':attribute harus berisi :size item.',
        'file' => ':attribute harus berukuran :size kilobyte.',
        'numeric' => ':attribute harus berukuran :size.',
        'string' => ':attribute harus berukuran :size karakter.',
    ],
    'starts_with' => ':attribute harus diawali salah satu dari: :values.',
    'string' => ':attribute harus berupa teks.',
    'timezone' => ':attribute harus berupa zona waktu yang sah.',
    'unique' => ':attribute sudah dipakai.',
    'uploaded' => ':attribute gagal diunggah.',
    'url' => 'Format :attribute tidak sah.',
    'uuid' => ':attribute harus berupa UUID yang sah.',

    'custom' => [
        'attribute-name' => ['rule-name' => 'custom-message'],
    ],

    // Nama kolom yang sering muncul → pesannya terbaca wajar oleh operator.
    'attributes' => [
        'user_id' => 'Pengguna',
        'code' => 'Kode Barcode',
        'label' => 'Keterangan',
        'is_active' => 'Status aktif',
        'journal_date' => 'Tanggal jurnal',
        'debit_account_id' => 'Akun debit',
        'credit_account_id' => 'Akun kredit',
        'cost_center_id' => 'Cost center',
        'amount' => 'Nominal',
        'file' => 'Berkas',
        'notary_name' => 'Notaris',
        'date' => 'Tanggal',
        'reason' => 'Alasan',
    ],
];
