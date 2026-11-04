{{-- Halaman publik link berkas supplier (tanpa login). Dipanggil BerkasSupplierController::show. --}}
@php
    $wajib = $jenis->where('is_mandatory', true);
    // Link hidup selama invoicenya belum disimpan / dibatalkan — supplier boleh mengunggah ulang.
    $terima = ! $link->aktif();
    $sudah = $link->status === \App\Domain\BusinessSupport\Models\BsApBerkasLink::TERISI;
    $riwayat = array_reverse($link->riwayat ?? []);
    $jam = fn ($iso) => \Illuminate\Support\Carbon::parse($iso)->timezone(config('app.timezone'))->format('d/m/Y H:i');
    $idLainnya = $jenis->firstWhere('code', \App\Domain\BusinessSupport\Models\BsApBerkas::KODE_LAINNYA)?->id;
    // Tebakan jenis dari nama file (pengunggah tetap bisa mengganti).
    $tebak = $jenis->mapWithKeys(fn ($j) => [$j->id => match ($j->code) {
        'INVOICE' => 'invoice|inv|nota|tagihan', 'KUITANSI' => 'kuitansi|kwitansi|receipt', 'FAKTUR-PAJAK' => 'faktur|efaktur|e-faktur|pajak|tax', default => '',
    }])->filter();
@endphp
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Unggah Dokumen Tagihan — {{ $perusahaan }}</title>
<style>
    :root { --bg: #f4f5f7; --card: #fff; --text: #111827; --muted: #6b7280; --line: #e5e7eb; --primary: #b45309; --danger: #dc2626; --ok: #047857; }
    @media (prefers-color-scheme: dark) { :root { --bg: #0b0f17; --card: #111827; --text: #f3f4f6; --muted: #9ca3af; --line: #1f2937; } }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; background: var(--bg); color: var(--text); font-size: 14px; }
    .wrap { max-width: 640px; margin: 0 auto; padding: 24px 16px 48px; }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; margin-top: 16px; }
    h1 { font-size: 20px; margin: 0; } .muted { color: var(--muted); font-size: 13px; }
    .noinv { width: 100%; font: inherit; font-weight: 600; color: inherit; background: transparent; border: 1px solid var(--line); border-radius: 8px; padding: 7px 8px; }
    .noinv:focus { outline: 2px solid var(--primary); outline-offset: -1px; }
    dl { display: grid; align-items: center; grid-template-columns: 140px 1fr; gap: 6px 12px; margin: 0; } dt { color: var(--muted); } dd { margin: 0; font-weight: 600; }
    .row { display: grid; grid-template-columns: 1fr 1.3fr auto; gap: 8px; align-items: center; padding: 8px 0; border-top: 1px solid var(--line); }
    select, input[type=file], textarea { width: 100%; font: inherit; color: inherit; background: transparent; border: 1px solid var(--line); border-radius: 8px; padding: 7px 8px; }
    button { font: inherit; cursor: pointer; border-radius: 8px; border: 1px solid var(--line); background: transparent; color: inherit; padding: 8px 12px; }
    .primary { background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 600; width: 100%; padding: 11px; margin-top: 14px; }
    .x { border: 0; color: var(--muted); font-size: 18px; padding: 4px 8px; }
    .err { color: var(--danger); background: color-mix(in srgb, var(--danger) 10%, transparent); border-radius: 8px; padding: 8px 10px; margin-top: 12px; }
    .ok { color: var(--ok); font-size: 16px; font-weight: 700; }
    .wajib { color: var(--danger); }
    .drop { margin-top: 4px; border: 2px dashed var(--line); border-radius: 14px; padding: 28px 16px; text-align: center; cursor: pointer; transition: .15s; }
    .drop:hover, .drop.aktif { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 7%, transparent); }
    .drop .ikon { font-size: 30px; line-height: 1; } .drop b { display: block; margin-top: 6px; font-size: 15px; }
    .cek { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0 4px; }
    .cek span { border: 1px solid var(--line); border-radius: 999px; padding: 3px 10px; font-size: 12px; color: var(--muted); }
    .cek span.ok2 { border-color: var(--ok); color: var(--ok); background: color-mix(in srgb, var(--ok) 8%, transparent); }
    .file { display: grid; grid-template-columns: 36px 1fr 220px auto; gap: 10px; align-items: center; padding: 10px 0; border-top: 1px solid var(--line); }
    .file .tipe { width: 36px; height: 36px; border-radius: 8px; display: grid; place-items: center; font-size: 10px; font-weight: 700; color: #fff; background: #6b7280; }
    .file .tipe.pdf { background: #dc2626; } .file .tipe.img { background: #2563eb; }
    .file .nama { min-width: 0; } .file .nama div:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
    .file input[type=text] { grid-column: 2 / -1; width: 100%; font: inherit; color: inherit; background: transparent; border: 1px solid var(--line); border-radius: 8px; padding: 7px 8px; }
    .file input[type=text].salah, .file select.salah { border-color: var(--danger); }
    @media (max-width: 520px) { .file { grid-template-columns: 36px 1fr auto; } .file select { grid-column: 2 / -1; } }
    @media (max-width: 520px) { .row { grid-template-columns: 1fr auto; } .row select { grid-column: 1 / -1; } dl { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
    <div class="muted">{{ $perusahaan }} · Java Core System</div>
    <h1>Unggah Dokumen Tagihan</h1>

    <div class="card">
        <dl>
            {{-- No. Invoice diisi supplier (nomor tagihan mereka) → jadi No. Invoice Supplier di invoice hutang kami. --}}
            <dt><label for="no_invoice">No. Invoice @unless ($terima)<span class="wajib">*</span>@endunless</label></dt>
            <dd>
                @if ($terima)
                    {{ $link->data['supplier_invoice_no'] ?? '—' }}
                @else
                    <input id="no_invoice" name="no_invoice" form="kirim" type="text" maxlength="80" required autocomplete="off"
                        value="{{ old('no_invoice', $link->data['supplier_invoice_no'] ?? '') }}" placeholder="Nomor invoice / tagihan Anda" class="noinv">
                @endif
            </dd>
            <dt>Supplier</dt><dd>{{ $link->supplier?->name ?? '—' }}</dd>
            @if (! empty($link->data['description']))<dt>Keterangan</dt><dd>{{ $link->data['description'] }}</dd>@endif
        </dl>
    </div>

    @if ($sudah || $terima)
        <div class="card">
            <div class="ok">✓ Dokumen sudah kami terima</div>
            <p class="muted" style="margin-bottom:0">Terakhir diunggah <b>{{ $link->filled_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</b> untuk invoice {{ $link->data['supplier_invoice_no'] ?? '' }}.
                @if ($terima) Link ini sudah ditutup — hubungi tim AP kami bila ada dokumen yang perlu diganti.
                @else Masih bisa <b>mengunggah ulang</b> di bawah bila ada dokumen yang salah / kurang — unggahan baru <b>menggantikan</b> yang sebelumnya (unggah ulang semua dokumennya).@endif</p>
            @if ($riwayat)
                <div class="muted" style="margin-top:10px;font-weight:600">Riwayat unggahan</div>
                <ul class="muted" style="margin:4px 0 0;padding-left:18px">
                    @foreach ($riwayat as $i => $r)
                        <li @if ($i > 0) style="opacity:.6" @endif>{{ $jam($r['at']) }} · {{ $r['file'] }} file{{ ! empty($r['no_invoice']) ? ' · No. '.$r['no_invoice'] : '' }}{{ $i === 0 ? ' (terbaru)' : ' (diganti)' }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
    @unless ($terima)
        <form id="kirim" class="card" method="post" action="{{ route('berkas-supplier.store', $link->token) }}" enctype="multipart/form-data">
            @csrf
            <p class="muted" style="margin-top:0">PDF, JPG, atau PNG · maks. 10 MB per file · maks. {{ $maxFiles }} file. Setelah file masuk, pilih jenis tiap dokumen.</p>

            <div id="drop" class="drop" tabindex="0" role="button" aria-label="Pilih atau seret file">
                <div class="ikon">⬆</div>
                <b>Seret file ke sini</b>
                <span class="muted">atau klik untuk memilih dari perangkat Anda (bisa beberapa sekaligus)</span>
            </div>
            <input id="pilih" type="file" accept=".pdf,.jpg,.jpeg,.png" multiple hidden>

            <div class="cek" id="cek">
                @foreach ($jenis as $j)<span data-jenis="{{ $j->id }}">{{ $j->name }}{{ $j->is_mandatory ? ' · wajib' : '' }}</span>@endforeach
            </div>

            <div id="daftar"></div>
            <div id="kirimFile" hidden></div>

            <div style="margin-top:12px">
                <label class="muted" for="catatan">Catatan (opsional)</label>
                <textarea id="catatan" name="catatan" rows="2" maxlength="200">{{ old('catatan') }}</textarea>
            </div>

            @if (session('error'))<div class="err">{{ session('error') }}</div>@endif
            @if (isset($errors) && $errors->any())<div class="err">{{ $errors->first() }}</div>@endif
            <div id="galat" class="err" hidden></div>
            <button type="submit" class="primary">{{ $sudah ? 'Kirim Ulang Dokumen' : 'Kirim Dokumen' }}</button>
        </form>
    @endunless
</div>
@if (! $terima)
<script>
    const maks = {{ (int) $maxFiles }}, maksUkuran = 10 * 1024 * 1024;
    const jenis = @json($jenis->map(fn ($j) => ['id' => $j->id, 'name' => $j->name, 'wajib' => (bool) $j->is_mandatory])->values());
    const idLainnya = @json($idLainnya);
    const tebak = @json($tebak);
    const daftar = document.getElementById('daftar'), drop = document.getElementById('drop'), pilih = document.getElementById('pilih');
    const galat = document.getElementById('galat');
    /** @type {{file: File, jenis: string, ket: string}[]} */
    let berkas = [];

    const ukuran = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
    const esc = (t) => t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function tebakJenis(nama) {
        const n = nama.toLowerCase();
        for (const [id, pola] of Object.entries(tebak)) if (new RegExp(pola).test(n)) return id;
        return '';
    }
    function tambah(files) {
        tampilGalat('');
        for (const f of files) {
            if (berkas.length >= maks) { tampilGalat('Maksimal ' + maks + ' file.'); break; }
            if (!/\.(pdf|jpe?g|png)$/i.test(f.name)) { tampilGalat('"' + f.name + '" bukan PDF/JPG/PNG.'); continue; }
            if (f.size > maksUkuran) { tampilGalat('"' + f.name + '" lebih dari 10 MB.'); continue; }
            berkas.push({ file: f, jenis: tebakJenis(f.name), ket: '' });
        }
        gambar();
    }
    function gambar() {
        daftar.innerHTML = berkas.map((b, i) => {
            const ext = b.file.name.split('.').pop().toLowerCase();
            const opsi = jenis.map((j) => `<option value="${j.id}" ${String(j.id) === String(b.jenis) ? 'selected' : ''}>${esc(j.name)}</option>`).join('');
            const lain = String(b.jenis) === String(idLainnya)
                ? `<input type="text" maxlength="100" placeholder="Dokumen apa? mis. Surat Kuasa, Berita Acara" value="${esc(b.ket)}" data-ket="${i}">` : '';
            return `<div class="file">
                <div class="tipe ${ext === 'pdf' ? 'pdf' : 'img'}">${esc(ext.toUpperCase())}</div>
                <div class="nama"><div title="${esc(b.file.name)}">${esc(b.file.name)}</div><div class="muted">${ukuran(b.file.size)}</div></div>
                <select data-jenis="${i}"><option value="">— jenis dokumen —</option>${opsi}</select>
                <button type="button" class="x" title="Hapus file" data-hapus="${i}">×</button>
                ${lain}
            </div>`;
        }).join('');
        const ada = new Set(berkas.map((b) => String(b.jenis)));
        document.querySelectorAll('#cek span').forEach((s) => s.classList.toggle('ok2', ada.has(s.dataset.jenis)));
    }
    function tampilGalat(t) { galat.textContent = t; galat.hidden = !t; }

    drop.addEventListener('click', () => pilih.click());
    drop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pilih.click(); } });
    pilih.addEventListener('change', () => { tambah(pilih.files); pilih.value = ''; });
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('aktif'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('aktif'); }));
    drop.addEventListener('drop', (e) => tambah(e.dataTransfer.files));
    // File yang dilepas di luar kotak tidak membuka file itu di tab ini.
    window.addEventListener('dragover', (e) => e.preventDefault());
    window.addEventListener('drop', (e) => e.preventDefault());

    daftar.addEventListener('change', (e) => {
        const i = e.target.dataset.jenis;
        if (i !== undefined) { berkas[i].jenis = e.target.value; gambar(); }
    });
    daftar.addEventListener('input', (e) => { const i = e.target.dataset.ket; if (i !== undefined) berkas[i].ket = e.target.value; });
    daftar.addEventListener('click', (e) => { const i = e.target.dataset.hapus; if (i !== undefined) { berkas.splice(i, 1); gambar(); } });

    // Kirim: file dari daftar dipasang ke input tersembunyi (DataTransfer) lalu form dikirim biasa.
    document.querySelector('form').addEventListener('submit', (e) => {
        const noInv = document.getElementById('no_invoice');
        if (noInv && !noInv.value.trim()) { e.preventDefault(); noInv.focus(); return tampilGalat('Isi nomor invoice Anda.'); }
        if (!berkas.length) { e.preventDefault(); return tampilGalat('Unggah minimal satu file.'); }
        const tanpaJenis = berkas.find((b) => !b.jenis);
        if (tanpaJenis) { e.preventDefault(); return tampilGalat('Pilih jenis dokumen untuk "' + tanpaJenis.file.name + '".'); }
        const tanpaKet = berkas.find((b) => String(b.jenis) === String(idLainnya) && !b.ket.trim());
        if (tanpaKet) { e.preventDefault(); return tampilGalat('Tulis isi dokumen "Lainnya" untuk "' + tanpaKet.file.name + '".'); }
        const kurang = jenis.filter((j) => j.wajib && !berkas.some((b) => String(b.jenis) === String(j.id))).map((j) => j.name);
        if (kurang.length) { e.preventDefault(); return tampilGalat('Dokumen wajib belum ada: ' + kurang.join(', ') + '.'); }
        const wadah = document.getElementById('kirimFile');
        wadah.innerHTML = '';
        berkas.forEach((b, i) => {
            const dt = new DataTransfer(); dt.items.add(b.file);
            const inp = document.createElement('input'); inp.type = 'file'; inp.name = `files[${i}][file]`; inp.files = dt.files;
            const sel = document.createElement('input'); sel.type = 'hidden'; sel.name = `files[${i}][berkas_id]`; sel.value = b.jenis;
            const ket = document.createElement('input'); ket.type = 'hidden'; ket.name = `files[${i}][keterangan]`; ket.value = b.ket;
            wadah.append(inp, sel, ket);
        });
        e.submitter && (e.submitter.disabled = true, e.submitter.textContent = 'Mengirim…');
    });
</script>
@endif
</body>
</html>
