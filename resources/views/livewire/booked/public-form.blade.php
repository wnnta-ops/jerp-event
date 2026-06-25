<div>
    @if ($submitted)
        <div class="nup-card max-w-xl mx-auto text-center">
            <div class="mx-auto mb-4 w-14 h-14 rounded-full grid place-items-center" style="background:#dcfce7;">
                <svg class="w-7 h-7" style="color:#16a34a;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h1 class="text-xl font-semibold mb-2" style="color:#111827;">Pendaftaran Booking Berhasil</h1>
            <p class="text-sm mb-4" style="color:#6b7280;">Terima kasih, data booking Anda telah kami terima.</p>
            <div class="rounded-lg p-4 mb-2 text-left" style="background:#f0f9ff; border:1px solid #bae6fd;">
                <p class="text-sm" style="color:#075985;">
                    Mohon lakukan <strong>transfer pembayaran ke rekening perusahaan</strong>. Data Anda akan
                    <strong>diverifikasi oleh tim kami</strong> terlebih dahulu.
                </p>
            </div>
        </div>
    @elseif ($reviewing)
        {{-- ── Pratinjau data & dokumen sebelum daftar ───────────────── --}}
        <div class="max-w-3xl mx-auto" x-data="{ zoom: null, warn: false }" x-init="window.scrollTo({top:0,behavior:'smooth'})">
            <div class="nup-card mb-4">
                <h1 class="text-lg font-semibold mb-1" style="color:#111827;">Periksa Data Pendaftaran</h1>
                <p class="text-sm" style="color:#6b7280;">Pastikan data &amp; dokumen yang Anda unggah sudah benar. Bila sudah, klik <strong>Konfirmasi &amp; Daftar</strong>. Bila ada yang salah, klik <strong>Kembali</strong>.</p>
            </div>

            @php
                $prow = fn ($l, $v) => '<div><dt style="color:#6b7280;font-size:12px;margin-bottom:1px;">'.$l.'</dt><dd style="color:#111827;font-weight:600;">'.($v !== '' && $v !== null ? $v : '—').'</dd></div>';
            @endphp

            <div class="nup-card mb-4">
                <h2 class="text-sm font-semibold mb-3" style="color:#111827;">Data Pendaftar</h2>
                <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    {!! $prow('Nama Lengkap', e($full_name)) !!}
                    {!! $prow('NIK', e($nik)) !!}
                    {!! $prow('NPWP', e($npwp)) !!}
                    {!! $prow('Tempat / Tgl Lahir', e(trim(($birth_place ?? '').' / '.($birth_date ?? ''), ' /'))) !!}
                    {!! $prow('No. HP', e($mobile)) !!}
                    {!! $prow('Email', e($email)) !!}
                    {!! $prow('Alamat KTP', e(trim(preg_replace('/\s+/', ' ', ($jalan ?? '').' '.($rt_rw ?? '').' '.($kelurahan ?? '').' '.($kecamatan ?? '').' '.($city ?? '').' '.($province ?? '').' '.($postal_code ?? ''))))) !!}
                    {!! $prow('Unit Diinginkan', e($unit_diinginkan)) !!}
                    {!! $prow('Cara Bayar', e($cara_bayar)) !!}
                    {!! $prow('Sales', e($ref_sales_name)) !!}
                </dl>
            </div>

            @if ($marital_status === 'KAWIN')
                <div class="nup-card mb-4">
                    <h2 class="text-sm font-semibold mb-3" style="color:#111827;">Data Pasangan</h2>
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        {!! $prow('Nama Pasangan', e($spouse_full_name)) !!}
                        {!! $prow('NIK Pasangan', e($spouse_nik)) !!}
                    </dl>
                </div>
            @endif

            <div class="nup-card mb-4">
                <h2 class="text-sm font-semibold mb-1" style="color:#111827;">Dokumen Diunggah</h2>
                <p class="text-xs mb-3" style="color:#6b7280;">Klik gambar untuk memperbesar. Pastikan seluruh tulisan/foto terbaca jelas.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    @foreach (array_filter([['KTP', $ktp_file], ['NPWP', $npwp_file], ['KTP Pasangan', $ktp_pasangan_file], ['NPWP Pasangan', $npwp_pasangan_file], ['Bukti Transfer', $bukti_transfer_file]], fn ($d) => $d[1] || ! str_contains($d[0], 'Pasangan')) as $doc)
                        <div class="rounded-lg p-2 text-center" style="border:1px solid #e5e7eb;background:#f9fafb;">
                            <div class="text-sm font-semibold mb-2" style="color:#374151;">{{ $doc[0] }}</div>
                            @if ($doc[1])
                                @php $ext = strtolower($doc[1]->getClientOriginalExtension()); @endphp
                                @if (in_array($ext, ['jpg', 'jpeg', 'png']))
                                    <img src="{{ $doc[1]->temporaryUrl() }}" alt="{{ $doc[0] }}" class="mx-auto rounded w-full"
                                         style="height:260px;object-fit:contain;background:#fff;cursor:zoom-in;"
                                         @click="zoom = '{{ $doc[1]->temporaryUrl() }}'">
                                    <div class="text-xs mt-1" style="color:#2563eb;">🔍 klik untuk perbesar</div>
                                @else
                                    <div class="py-12">
                                        <div style="font-size:42px;">📄</div>
                                        <div class="break-all" style="color:#6b7280;font-size:12px;">{{ $doc[1]->getClientOriginalName() }}</div>
                                    </div>
                                @endif
                            @else
                                <div class="py-12 text-xs" style="color:#9ca3af;">— tidak ada —</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="nup-card flex flex-col md:flex-row gap-3 md:justify-between">
                <button type="button" wire:click="backToForm"
                        class="inline-flex items-center justify-center gap-2 rounded px-6 py-2 text-sm font-semibold whitespace-nowrap"
                        style="background:#e5e7eb;color:#374151;">
                    ← Kembali / Perbaiki
                </button>
                <button type="button" @click="warn = true"
                        class="inline-flex items-center justify-center gap-2 rounded px-6 py-2 text-sm font-semibold text-white whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed"
                        style="background:#16a34a;"
                        wire:loading.attr="disabled" wire:target="submit">
                    <svg wire:loading wire:target="submit" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                    <span wire:loading.remove wire:target="submit">Register</span>
                    <span wire:loading wire:target="submit">Mengirim…</span>
                </button>
            </div>

            {{-- Perbesar gambar: BERBINGKAI (bukan fullscreen), ramah HP. Klik luar / Tutup / Esc utk menutup --}}
            <div x-show="zoom" @click="zoom = null" @keydown.escape.window="zoom = null"
                 class="fixed inset-0 flex items-center justify-center p-4"
                 style="background:rgba(0,0,0,.6);z-index:60;">
                <div class="bg-white rounded-xl p-3" @click.stop
                     style="max-width:94vw;max-height:88vh;box-shadow:0 20px 60px rgba(0,0,0,.45);">
                    <div class="flex items-center justify-between mb-2 px-1">
                        <span class="text-xs font-semibold" style="color:#6b7280;">Perbesar — cubit/zoom untuk lebih detail</span>
                        <button type="button" @click="zoom = null"
                                class="text-sm font-semibold px-3 py-1 rounded" style="background:#e5e7eb;color:#374151;">✕ Tutup</button>
                    </div>
                    <img :src="zoom" alt="" class="rounded" style="display:block;margin:0 auto;max-width:90vw;max-height:74vh;object-fit:contain;">
                </div>
            </div>

            {{-- Konfirmasi sebelum daftar: muncul SETELAH klik Register di pratinjau --}}
            <div x-show="warn" @keydown.escape.window="warn = false"
                 class="fixed inset-0 flex items-center justify-center p-4"
                 style="background:rgba(0,0,0,.6);z-index:70;">
                <div class="rounded-xl bg-white w-full max-w-md p-6 text-center" style="box-shadow:0 20px 60px rgba(0,0,0,.4);" @click.stop>
                    <div class="mx-auto mb-3 w-14 h-14 rounded-full grid place-items-center" style="background:#fef3c7;">
                        <svg class="w-8 h-8" style="color:#d97706;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold mb-2" style="color:#111827;">Periksa Kembali Data Anda</h3>
                    <p class="text-sm mb-5" style="color:#4b5563;">Cek kembali <strong>Upload Berkas</strong> Anda. Jika ada kesalahan akan <strong>menghambat pemberkasan</strong>.</p>
                    <div class="flex flex-col sm:flex-row gap-2">
                        <button type="button" @click="warn = false"
                                class="inline-flex items-center justify-center rounded px-6 py-2.5 text-sm font-semibold w-full" style="background:#e5e7eb;color:#374151;">
                            Periksa Lagi
                        </button>
                        <button type="button" wire:click="submit" @click="warn = false"
                                class="inline-flex items-center justify-center gap-2 rounded px-6 py-2.5 text-sm font-semibold text-white w-full" style="background:#16a34a;">
                            Ya, Daftar Sekarang
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @else
    <form wire:submit="review" enctype="multipart/form-data"
          x-data
          @nup-validation-failed.window="
            const el = document.getElementById('errors-summary');
            if (el) { el.scrollIntoView({behavior:'smooth', block:'start'}); }
          ">

    @if ($errors->any())
        <div id="errors-summary" class="nup-card mb-4" style="border-color:#fecaca; background:#fef2f2;">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 w-6 h-6 grid place-items-center rounded-full text-white" style="background:#dc2626;">!</div>
                <div class="flex-1">
                    <h2 class="font-semibold text-sm" style="color:#991b1b;">
                        Pendaftaran tidak bisa dilanjutkan — {{ $errors->count() }} field perlu diperbaiki:
                    </h2>
                    <ul class="mt-2 list-disc list-inside text-xs space-y-1" style="color:#991b1b;">
                        @foreach ($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Data Asli ───────────────────────────────────────────────── --}}
    <div class="nup-card mb-4">
        <div class="mb-6">
            <h1 class="nup-section-title"><span class="nup-section-dot"></span>Data Asli</h1>
            <p class="nup-section-subtitle">Lengkapi form data diri pemesan (Booking Manual).</p>
            <p class="text-xs mt-1" style="color:#dc2626;">Data booking akan digunakan pada proses tahapan selanjutnya.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="nup-label">Project <span class="nup-required">*</span></label>
                <select wire:model.live="project_pref_id" class="nup-input">
                    <option value="">— Project</option>
                    @foreach ($projects as $p)
                        <option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>
                    @endforeach
                </select>
                @error('project_pref_id') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Sumber Data <span class="nup-required">*</span></label>
                <select wire:model="sumber_data" class="nup-input">
                    <option value="">— Pilih</option>
                    @foreach ($sumberDataOptions as $opt)
                        <option value="{{ $opt->kode }}">{{ $opt->nama }}</option>
                    @endforeach
                </select>
                @error('sumber_data') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="nup-label">Nama Lengkap <span class="nup-required">*</span></label>
                <input wire:model="full_name" type="text" class="nup-input" placeholder="Nama Lengkap Sesuai KTP">
                @error('full_name') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Nomor KTP <span class="nup-required">*</span></label>
                <input wire:model="nik" type="text" maxlength="16" inputmode="numeric" class="nup-input" placeholder="Nomor KTP (16 digit)">
                @error('nik') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="nup-label">Tempat Lahir <span class="nup-required">*</span></label>
                <input wire:model="birth_place" type="text" class="nup-input" placeholder="Tempat Lahir">
                @error('birth_place') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Tanggal Lahir <span class="nup-required">*</span></label>
                <div wire:ignore>
                    <input type="text" class="nup-input" placeholder="dd/mm/yyyy" autocomplete="off" x-data
                           x-init="flatpickr($el, { dateFormat:'Y-m-d', altInput:true, altFormat:'d/m/Y', altInputClass:'nup-input', allowInput:true, maxDate:'today', onChange:(s,str)=>$wire.set('birth_date', str, false) })">
                </div>
                @error('birth_date') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="nup-label">Email <span class="nup-required">*</span></label>
                <input wire:model="email" type="email" class="nup-input" placeholder="Email Aktif">
                @error('email') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Pekerjaan <span class="nup-required">*</span></label>
                <input wire:model="occupation" type="text" class="nup-input" placeholder="Pekerjaan">
                @error('occupation') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="nup-label" style="margin-bottom:0;">Nomor HP <span class="nup-required">*</span></label>
                    <label class="nup-toggle-row text-xs" style="color:#6b7280;">
                        <span class="nup-switch">
                            <input type="checkbox" wire:model.live="show_secondary_phone">
                            <span class="nup-switch-slider"></span>
                        </span>
                        <span>Tambah Nomor</span>
                    </label>
                </div>
                <div class="flex">
                    <span class="inline-flex items-center px-3 border border-r-0 text-sm"
                          style="background:#f3f4f6; border-color:#d1d5db; color:#4b5563; border-top-left-radius:6px; border-bottom-left-radius:6px;">+62</span>
                    <input wire:model="mobile" type="text" inputmode="numeric" class="nup-input"
                           style="border-top-left-radius:0; border-bottom-left-radius:0;"
                           placeholder="Nomor Handphone / Whatsapp Customer">
                </div>
                @if ($show_secondary_phone)
                    <input wire:model="secondary_phone" type="text" inputmode="numeric"
                           class="nup-input mt-2" placeholder="Nomor HP kedua (opsional)">
                @endif
                @error('mobile') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Nomor Pokok Wajib Pajak (NPWP) <span class="nup-required">*</span></label>
                <input wire:model="npwp" type="text" class="nup-input" placeholder="Nomor Pokok Wajib Pajak">
                @error('npwp') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-[1fr,160px] gap-4 mb-4">
            <div>
                <label class="nup-label">Alamat <span class="nup-required">*</span></label>
                <input wire:model="jalan" type="text" class="nup-input" placeholder="Jalan, Nomor Rumah">
                @error('jalan') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">RT/RW <span class="nup-required">*</span></label>
                <input wire:model="rt_rw" type="text" class="nup-input" placeholder="01/02">
                @error('rt_rw') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div wire:ignore data-wilayah
             class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4"
             x-data="ktpWilayah(@js(['prov' => $province, 'kab' => $city, 'kec' => $kecamatan, 'kel' => $kelurahan]), '')"
             x-init="initFromExisting()">
            <div>
                <label class="nup-label">Provinsi <span class="nup-required">*</span></label>
                <select x-model="provId" @change="onProv()" class="nup-input">
                    <option value="">— Pilih</option>
                    <template x-for="p in provinces" :key="p.id">
                        <option :value="p.id" x-text="p.name"></option>
                    </template>
                </select>
                @error('province') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Kabupaten / Kota <span class="nup-required">*</span></label>
                <select x-model="kabId" @change="onKab()" class="nup-input" :disabled="!provId">
                    <option value="">— Pilih</option>
                    <template x-for="k in regencies" :key="k.id">
                        <option :value="k.id" x-text="k.name"></option>
                    </template>
                </select>
                @error('city') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Kecamatan <span class="nup-required">*</span></label>
                <select x-model="kecId" @change="onKec()" class="nup-input" :disabled="!kabId">
                    <option value="">— Pilih</option>
                    <template x-for="d in districts" :key="d.id">
                        <option :value="d.id" x-text="d.name"></option>
                    </template>
                </select>
                @error('kecamatan') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Kelurahan <span class="nup-required">*</span></label>
                <select x-model="kelId" @change="onKel()" class="nup-input" :disabled="!kecId">
                    <option value="">— Pilih</option>
                    <template x-for="v in villages" :key="v.id">
                        <option :value="v.id" x-text="v.name"></option>
                    </template>
                </select>
                @error('kelurahan') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="mb-2">
            <div class="flex flex-wrap gap-4">
                @foreach (['KAWIN' => 'Menikah', 'CERAI' => 'Cerai', 'BELUM' => 'Belum Menikah', 'LAIN' => 'Lain-lain'] as $val => $lbl)
                    <label class="nup-toggle-row text-sm cursor-pointer">
                        <span class="nup-switch">
                            <input type="radio" wire:model.live="marital_status" value="{{ $val }}">
                            <span class="nup-switch-slider"></span>
                        </span>
                        <span style="color:#374151;">{{ $lbl }}</span>
                    </label>
                @endforeach
            </div>
            @error('marital_status') <span class="nup-error">{{ $message }}</span> @enderror
        </div>
    </div>

    {{-- ── Data Pasangan (muncul saat status Menikah/KAWIN) ────────── --}}
    @if ($marital_status === 'KAWIN')
        <div class="nup-card mb-4">
            <h2 class="nup-section-title mb-4"><span class="nup-section-dot"></span>Data Pasangan</h2>

            <label class="nup-toggle-row text-sm mb-4">
                <span class="nup-switch">
                    <input type="checkbox" wire:model.live="spouse_address_same_as_pemohon">
                    <span class="nup-switch-slider"></span>
                </span>
                <span style="color:#374151;">Alamat sama dengan pasangan</span>
            </label>

            @if (! $spouse_address_same_as_pemohon)
                <div class="mb-4">
                    <label class="nup-label">Alamat KTP <span class="nup-required">*</span></label>
                    <input wire:model="spouse_address" type="text" class="nup-input" placeholder="Jalan, Nomor Rumah, RT/RW, Desa">
                    @error('spouse_address') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="nup-label">Nama Lengkap <span class="nup-required">*</span></label>
                    <input wire:model="spouse_full_name" type="text" class="nup-input" placeholder="Nama Lengkap Sesuai KTP">
                    @error('spouse_full_name') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="nup-label">Nomor KTP <span class="nup-required">*</span></label>
                    <input wire:model="spouse_nik" type="text" inputmode="numeric" maxlength="16" class="nup-input" placeholder="Nomor KTP">
                    @error('spouse_nik') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                <div>
                    <label class="nup-label">Nomor HP</label>
                    <div class="flex">
                        <span class="inline-flex items-center px-3 border border-r-0 text-sm"
                              style="background:#f3f4f6; border-color:#d1d5db; color:#4b5563; border-top-left-radius:6px; border-bottom-left-radius:6px;">+62</span>
                        <input wire:model="spouse_phone" type="text" inputmode="numeric" class="nup-input"
                               style="border-top-left-radius:0; border-bottom-left-radius:0;"
                               placeholder="Nomor Handphone / Whatsapp">
                    </div>
                    @error('spouse_phone') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="nup-label">Email Aktif</label>
                    <input wire:model="spouse_email" type="email" class="nup-input" placeholder="Alamat Email Aktif">
                    @error('spouse_email') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="nup-label">Tempat Lahir</label>
                    <input wire:model="spouse_birth_place" type="text" class="nup-input" placeholder="Tempat Lahir">
                    @error('spouse_birth_place') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="nup-label">Tanggal Lahir</label>
                    <div wire:ignore>
                        <input type="text" class="nup-input" placeholder="dd/mm/yyyy" autocomplete="off" x-data
                               x-init="flatpickr($el, { dateFormat:'Y-m-d', altInput:true, altFormat:'d/m/Y', altInputClass:'nup-input', allowInput:true, maxDate:'today', onChange:(s,str)=>$wire.set('spouse_birth_date', str, false) })">
                    </div>
                    @error('spouse_birth_date') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="nup-label">Pekerjaan</label>
                    <input wire:model="spouse_occupation" type="text" class="nup-input" placeholder="Pekerjaan">
                    @error('spouse_occupation') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>
    @endif

    {{-- ── Alamat Surat Menyurat ─────────────────────────────────── --}}
    <div class="nup-card mb-4">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="nup-section-title"><span class="nup-section-dot"></span>Alamat Surat Menyurat <span class="nup-required">*</span></h2>
            <label class="nup-toggle-row text-sm">
                <span class="nup-switch">
                    <input type="checkbox" wire:model.live="mail_same_as_ktp">
                    <span class="nup-switch-slider"></span>
                </span>
                <span style="color:#374151;">Sama dengan alamat KTP</span>
            </label>
        </div>

        @if (! $mail_same_as_ktp)
            <div class="grid grid-cols-1 md:grid-cols-[1fr,160px] gap-4 mb-4">
                <div>
                    <label class="nup-label">Alamat <span class="nup-required">*</span></label>
                    <input wire:model="mail_address" type="text" class="nup-input" placeholder="Jalan, Nomor Rumah">
                    @error('mail_address') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="nup-label">RT/RW</label>
                    <input type="text" class="nup-input" placeholder="01/02">
                </div>
            </div>
            <div wire:ignore data-wilayah
                 class="grid grid-cols-1 md:grid-cols-4 gap-4"
                 x-data="ktpWilayah(@js(['prov' => $mail_province, 'kab' => $mail_city, 'kec' => $mail_kecamatan, 'kel' => $mail_kelurahan]), 'mail_')"
                 x-init="initFromExisting()">
                <div>
                    <label class="nup-label">Provinsi <span class="nup-required">*</span></label>
                    <select x-model="provId" @change="onProv()" class="nup-input">
                        <option value="">— Pilih</option>
                        <template x-for="p in provinces" :key="p.id"><option :value="p.id" x-text="p.name"></option></template>
                    </select>
                    @error('mail_province') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="nup-label">Kabupaten / Kota <span class="nup-required">*</span></label>
                    <select x-model="kabId" @change="onKab()" class="nup-input" :disabled="!provId">
                        <option value="">— Pilih</option>
                        <template x-for="k in regencies" :key="k.id"><option :value="k.id" x-text="k.name"></option></template>
                    </select>
                    @error('mail_city') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="nup-label">Kecamatan</label>
                    <select x-model="kecId" @change="onKec()" class="nup-input" :disabled="!kabId">
                        <option value="">— Pilih</option>
                        <template x-for="d in districts" :key="d.id"><option :value="d.id" x-text="d.name"></option></template>
                    </select>
                </div>
                <div>
                    <label class="nup-label">Kelurahan</label>
                    <select x-model="kelId" @change="onKel()" class="nup-input" :disabled="!kecId">
                        <option value="">— Pilih</option>
                        <template x-for="v in villages" :key="v.id"><option :value="v.id" x-text="v.name"></option></template>
                    </select>
                </div>
            </div>
        @else
            <p class="text-xs" style="color:#6b7280;">Menggunakan alamat KTP di atas.</p>
        @endif
    </div>

    {{-- ── Unit yang Diinginkan + Sales + Cara Bayar + Files ────── --}}
    <div class="nup-card mb-4">
        <h2 class="nup-section-title mb-4"><span class="nup-section-dot"></span>Unit yang Diinginkan <span class="nup-required">*</span></h2>
        <div class="mb-4">
            <label class="nup-label">Unit Diinginkan <span class="nup-required">*</span></label>
            <input wire:model="unit_diinginkan" type="text" class="nup-input"
                   placeholder="contoh: JRK3-002-V-7 / Blok A No. 12">
            <p class="nup-disclaimer mt-1">Tuliskan unit yang Anda inginkan. Tim verifikasi akan mengonfirmasi ketersediaan unit.</p>
            @error('unit_diinginkan') <span class="nup-error">{{ $message }}</span> @enderror
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
            <div>
                <label class="nup-label">Sales (Nama Sales - Agensi Properti) <span class="nup-required">*</span></label>
                <input wire:model.live.debounce.500ms="ref_sales_name" type="text" class="nup-input"
                       list="nup-agents-list" autocomplete="off" placeholder="Ketik / pilih nama sales">
                <datalist id="nup-agents-list">
                    @foreach ($agents as $a)
                        <option value="{{ $a->sales_label }}"></option>
                    @endforeach
                </datalist>
                @if ($agent_id)
                    <span class="text-xs" style="color:#16a34a;">✓ Sales terdaftar</span>
                @endif
                @error('ref_sales_name') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">No. Telp Sales <span class="nup-required">*</span></label>
                <input wire:model="ref_sales_phone" type="text" class="nup-input" placeholder="No. Telp Sales">
                @error('ref_sales_phone') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Cara Bayar <span class="nup-required">*</span></label>
                <select wire:model="cara_bayar" class="nup-input">
                    <option value="">— Pilih</option>
                    <option value="CASH">Cash</option>
                    <option value="CASH_BERTAHAP">Cash Bertahap</option>
                    <option value="KPR">KPR</option>
                </select>
                @error('cara_bayar') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
        </div>

    </div>

    {{-- ── Upload Berkas (semua dokumen dijadikan satu di sini) ───── --}}
    <div class="nup-card mb-4">
        <h2 class="nup-section-title mb-1"><span class="nup-section-dot"></span>Upload Berkas <span class="nup-required">*</span></h2>
        <p class="nup-disclaimer mb-4">Semua berkas maks. 5MB — format JPG, PNG, atau PDF.</p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="nup-label">Upload KTP Customer <span class="nup-required">*</span></label>
                <input type="file" wire:model="ktp_file" accept=".jpg,.jpeg,.png,.pdf" class="nup-input" style="padding:0.375rem 0.5rem;">
                <div wire:loading wire:target="ktp_file" class="text-xs mt-1" style="color:#2563eb;">Mengunggah…</div>
                @if ($ktp_file && ! $errors->has('ktp_file'))
                    <div class="text-xs mt-1" style="color:#16a34a;">✓ {{ method_exists($ktp_file, 'getClientOriginalName') ? $ktp_file->getClientOriginalName() : 'Terunggah' }}</div>
                @endif
                @error('ktp_file') <span class="nup-error">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="nup-label">Upload NPWP Customer <span class="nup-required">*</span></label>
                <input type="file" wire:model="npwp_file" accept=".jpg,.jpeg,.png,.pdf" class="nup-input" style="padding:0.375rem 0.5rem;">
                <div wire:loading wire:target="npwp_file" class="text-xs mt-1" style="color:#2563eb;">Mengunggah…</div>
                @if ($npwp_file && ! $errors->has('npwp_file'))
                    <div class="text-xs mt-1" style="color:#16a34a;">✓ {{ method_exists($npwp_file, 'getClientOriginalName') ? $npwp_file->getClientOriginalName() : 'Terunggah' }}</div>
                @endif
                @error('npwp_file') <span class="nup-error">{{ $message }}</span> @enderror
            </div>

            @if ($marital_status === 'KAWIN')
                <div>
                    <label class="nup-label">Upload KTP Pasangan</label>
                    <input type="file" wire:model="ktp_pasangan_file" accept=".jpg,.jpeg,.png,.pdf" class="nup-input" style="padding:0.375rem 0.5rem;">
                    <div wire:loading wire:target="ktp_pasangan_file" class="text-xs mt-1" style="color:#2563eb;">Mengunggah…</div>
                    @if ($ktp_pasangan_file && ! $errors->has('ktp_pasangan_file'))
                        <div class="text-xs mt-1" style="color:#16a34a;">✓ {{ method_exists($ktp_pasangan_file, 'getClientOriginalName') ? $ktp_pasangan_file->getClientOriginalName() : 'Terunggah' }}</div>
                    @endif
                    @error('ktp_pasangan_file') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="nup-label">Upload NPWP Pasangan</label>
                    <input type="file" wire:model="npwp_pasangan_file" accept=".jpg,.jpeg,.png,.pdf" class="nup-input" style="padding:0.375rem 0.5rem;">
                    <div wire:loading wire:target="npwp_pasangan_file" class="text-xs mt-1" style="color:#2563eb;">Mengunggah…</div>
                    @if ($npwp_pasangan_file && ! $errors->has('npwp_pasangan_file'))
                        <div class="text-xs mt-1" style="color:#16a34a;">✓ {{ method_exists($npwp_pasangan_file, 'getClientOriginalName') ? $npwp_pasangan_file->getClientOriginalName() : 'Terunggah' }}</div>
                    @endif
                    @error('npwp_pasangan_file') <span class="nup-error">{{ $message }}</span> @enderror
                </div>
            @endif
        </div>

        <div class="mt-4">
            <label class="nup-label">Upload Bukti Transfer <span class="nup-required">*</span></label>
            <input type="file" wire:model="bukti_transfer_file" accept=".jpg,.jpeg,.png,.pdf" class="nup-input" style="padding:0.375rem 0.5rem;">
            <div wire:loading wire:target="bukti_transfer_file" class="text-xs mt-1" style="color:#2563eb;">Mengunggah…</div>
            @if ($bukti_transfer_file && ! $errors->has('bukti_transfer_file'))
                <div class="text-xs mt-1" style="color:#16a34a;">✓ {{ method_exists($bukti_transfer_file, 'getClientOriginalName') ? $bukti_transfer_file->getClientOriginalName() : 'Terunggah' }}</div>
            @endif
            <p class="text-xs mt-1" style="color:#6b7280;">Bukti transfer pembayaran booking (struk/screenshot). Akan dicek tim verifikasi.</p>
            @error('bukti_transfer_file') <span class="nup-error">{{ $message }}</span> @enderror
        </div>

        <p class="text-xs mt-3" style="color:#dc2626;">*) KTP &amp; NPWP customer + bukti transfer wajib. Berkas pasangan muncul saat status Menikah.</p>
    </div>

    {{-- ── Pernyataan + Register ───────────────────────────────── --}}
    <div class="nup-card flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <label class="flex items-start gap-2 text-sm" style="color:#374151;">
            <input type="checkbox" wire:model.live="pernyataan_setuju" class="mt-1">
            <span>Saya menyatakan bahwa data yang saya berikan adalah <strong>data valid dan dapat dipertanggung jawabkan</strong>.</span>
        </label>
        @error('pernyataan_setuju') <span class="nup-error">{{ $message }}</span> @enderror
        <button type="submit"
                class="inline-flex items-center justify-center gap-2 rounded px-6 py-2 text-sm font-semibold text-white whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed"
                style="background:#2563eb;"
                @disabled(! $pernyataan_setuju)
                wire:loading.attr="disabled" wire:target="review">
            <svg wire:loading wire:target="review" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            <span wire:loading.remove wire:target="review">Register</span>
            <span wire:loading wire:target="review">Memeriksa…</span>
        </button>
    </div>

    </form>
    @endif
</div>

<script>
    function ktpWilayah(initial, prefix) {
        return {
            prefix: prefix || '',
            provId: '', kabId: '', kecId: '', kelId: '',
            provName: initial.prov || '', kabName: initial.kab || '',
            kecName: initial.kec || '', kelName: initial.kel || '',
            provinces: [], regencies: [], districts: [], villages: [],

            async initFromExisting() {
                await this.loadProvinces();
                if (this.provName) {
                    const p = this.provinces.find(x => x.name.toLowerCase() === this.provName.toLowerCase());
                    if (p) { this.provId = p.id; await this.loadRegencies(p.id); }
                }
                if (this.kabName) {
                    const k = this.regencies.find(x => x.name.toLowerCase() === this.kabName.toLowerCase());
                    if (k) { this.kabId = k.id; await this.loadDistricts(k.id); }
                }
                if (this.kecName) {
                    const d = this.districts.find(x => x.name.toLowerCase() === this.kecName.toLowerCase());
                    if (d) { this.kecId = d.id; await this.loadVillages(d.id); }
                }
                if (this.kelName) {
                    const v = this.villages.find(x => x.name.toLowerCase() === this.kelName.toLowerCase());
                    if (v) { this.kelId = v.id; }
                }
            },
            async loadProvinces() { try { this.provinces = await (await fetch('/wilayah/provinces')).json(); } catch (e) {} },
            async loadRegencies(id) { try { this.regencies = await (await fetch(`/wilayah/regencies/${id}`)).json(); } catch (e) {} },
            async loadDistricts(id) { try { this.districts = await (await fetch(`/wilayah/districts/${id}`)).json(); } catch (e) {} },
            async loadVillages(id) { try { this.villages = await (await fetch(`/wilayah/villages/${id}`)).json(); } catch (e) {} },
            async onProv() {
                this.provName = (this.provinces.find(x => x.id === this.provId)?.name) || '';
                this.kabId = this.kecId = this.kelId = ''; this.kabName = this.kecName = this.kelName = '';
                this.regencies = this.districts = this.villages = [];
                if (this.provId) await this.loadRegencies(this.provId);
                this.syncLivewire();
            },
            async onKab() {
                this.kabName = (this.regencies.find(x => x.id === this.kabId)?.name) || '';
                this.kecId = this.kelId = ''; this.kecName = this.kelName = '';
                this.districts = this.villages = [];
                if (this.kabId) await this.loadDistricts(this.kabId);
                this.syncLivewire();
            },
            async onKec() {
                this.kecName = (this.districts.find(x => x.id === this.kecId)?.name) || '';
                this.kelId = ''; this.kelName = ''; this.villages = [];
                if (this.kecId) await this.loadVillages(this.kecId);
                this.syncLivewire();
            },
            onKel() {
                this.kelName = (this.villages.find(x => x.id === this.kelId)?.name) || '';
                this.syncLivewire();
            },
            syncLivewire() {
                if (typeof this.$wire !== 'undefined') {
                    this.$wire.set(this.prefix + 'province', this.provName, false);
                    this.$wire.set(this.prefix + 'city', this.kabName, false);
                    this.$wire.set(this.prefix + 'kecamatan', this.kecName, false);
                    this.$wire.set(this.prefix + 'kelurahan', this.kelName, false);
                }
            },
            heal() {
                const optionCount = this.$el.querySelectorAll('select option').length;
                const expected = this.provinces.length + this.regencies.length + this.districts.length + this.villages.length + 4;
                if (optionCount < expected) { this.initFromExisting(); }
            },
        };
    }
    if (! window.__wilayahHealHooked) {
        window.__wilayahHealHooked = true;
        document.addEventListener('livewire:init', () => {
            Livewire.hook('morphed', ({ el }) => {
                const scope = el && el.querySelectorAll ? el : document;
                scope.querySelectorAll('[data-wilayah]').forEach((node) => {
                    const data = window.Alpine && Alpine.$data(node);
                    if (data && typeof data.heal === 'function') data.heal();
                });
            });
        });
    }
</script>
