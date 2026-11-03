{{-- Halaman publik link berkas supplier (tanpa login). Dipanggil BerkasSupplierController::show. --}}
@php
    $wajib = $jenis->where('is_mandatory', true);
    $terima = $link->status !== \App\Domain\BusinessSupport\Models\BsApBerkasLink::MENUNGGU;
    $oldFiles = old('files', $wajib->map(fn ($j) => ['berkas_id' => $j->id])->values()->all() ?: [['berkas_id' => '']]);
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
    dl { display: grid; grid-template-columns: 140px 1fr; gap: 6px 12px; margin: 0; } dt { color: var(--muted); } dd { margin: 0; font-weight: 600; }
    .row { display: grid; grid-template-columns: 1fr 1.3fr auto; gap: 8px; align-items: center; padding: 8px 0; border-top: 1px solid var(--line); }
    select, input[type=file], textarea { width: 100%; font: inherit; color: inherit; background: transparent; border: 1px solid var(--line); border-radius: 8px; padding: 7px 8px; }
    button { font: inherit; cursor: pointer; border-radius: 8px; border: 1px solid var(--line); background: transparent; color: inherit; padding: 8px 12px; }
    .primary { background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 600; width: 100%; padding: 11px; margin-top: 14px; }
    .x { border: 0; color: var(--muted); font-size: 18px; padding: 4px 8px; }
    .err { color: var(--danger); background: color-mix(in srgb, var(--danger) 10%, transparent); border-radius: 8px; padding: 8px 10px; margin-top: 12px; }
    .ok { color: var(--ok); font-size: 16px; font-weight: 700; }
    .wajib { color: var(--danger); }
    @media (max-width: 520px) { .row { grid-template-columns: 1fr auto; } .row select { grid-column: 1 / -1; } dl { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
    <div class="muted">{{ $perusahaan }} · Java Core System</div>
    <h1>Unggah Dokumen Tagihan</h1>

    <div class="card">
        <dl>
            <dt>No. Invoice</dt><dd>{{ $link->invoice_number }}</dd>
            <dt>Supplier</dt><dd>{{ $link->supplier?->name ?? '—' }}</dd>
            @if (! empty($link->data['supplier_invoice_no']))<dt>No. Tagihan Anda</dt><dd>{{ $link->data['supplier_invoice_no'] }}</dd>@endif
            @if (! empty($link->data['description']))<dt>Keterangan</dt><dd>{{ $link->data['description'] }}</dd>@endif
        </dl>
    </div>

    @if ($terima)
        <div class="card">
            <div class="ok">✓ Dokumen sudah kami terima</div>
            <p class="muted">Terima kasih. Dokumen untuk {{ $link->invoice_number }} diterima {{ $link->filled_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}. Hubungi tim AP kami bila ada dokumen yang perlu diganti.</p>
        </div>
    @else
        <form class="card" method="post" action="{{ route('berkas-supplier.store', $link->token) }}" enctype="multipart/form-data">
            @csrf
            <p class="muted" style="margin-top:0">PDF, JPG, atau PNG · maks. 10 MB per file · maks. {{ $maxFiles }} file.
                Wajib: @foreach ($wajib as $j)<b>{{ $j->name }}</b>@if (! $loop->last), @endif @endforeach.</p>

            <div id="rows">
                @foreach ($oldFiles as $i => $f)
                    <div class="row">
                        <input type="file" name="files[{{ $i }}][file]" accept=".pdf,.jpg,.jpeg,.png" required>
                        <select name="files[{{ $i }}][berkas_id]" required>
                            <option value="">— jenis dokumen —</option>
                            @foreach ($jenis as $j)<option value="{{ $j->id }}" @selected((string) ($f['berkas_id'] ?? '') === (string) $j->id)>{{ $j->name }}{{ $j->is_mandatory ? ' (wajib)' : '' }}</option>@endforeach
                        </select>
                        <button type="button" class="x" title="Hapus baris" onclick="hapus(this)">×</button>
                    </div>
                @endforeach
            </div>
            <button type="button" onclick="tambah()" style="margin-top:8px">+ Tambah file</button>

            <div style="margin-top:12px">
                <label class="muted" for="catatan">Catatan (opsional)</label>
                <textarea id="catatan" name="catatan" rows="2" maxlength="200">{{ old('catatan') }}</textarea>
            </div>

            @if (session('error'))<div class="err">{{ session('error') }}</div>@endif
            @if (isset($errors) && $errors->any())<div class="err">{{ $errors->first() }}</div>@endif
            <button type="submit" class="primary">Kirim Dokumen</button>
        </form>
    @endif
</div>
<script>
    const maks = {{ (int) $maxFiles }};
    let idx = {{ count($oldFiles) }};
    function tambah() {
        const rows = document.getElementById('rows');
        if (rows.children.length >= maks) return alert('Maksimal ' + maks + ' file.');
        const tpl = rows.children[0].cloneNode(true);
        tpl.querySelector('input').name = 'files[' + idx + '][file]';
        tpl.querySelector('input').value = '';
        tpl.querySelector('select').name = 'files[' + idx + '][berkas_id]';
        tpl.querySelector('select').value = '';
        idx++;
        rows.appendChild(tpl);
    }
    function hapus(btn) {
        const rows = document.getElementById('rows');
        if (rows.children.length > 1) btn.closest('.row').remove();
    }
</script>
</body>
</html>
