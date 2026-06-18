<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Faktur Penjualan — {{ $fp->no_fp }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        @page { size: A4 portrait; margin: 0; }
        body { font-family: 'Segoe UI', Arial, Helvetica, sans-serif; color: #1e293b; font-size: 12px; line-height: 1.5; background: #fff; }
        .sheet { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 0 0 24mm; position: relative; }

        /* Header band */
        .band { background: linear-gradient(110deg, #0f766e 0%, #0ea5e9 60%, #6366f1 100%); color: #fff; padding: 26px 32px; display: flex; justify-content: space-between; align-items: flex-start; }
        .band .brand { display: flex; align-items: center; gap: 14px; }
        .band .brand .logo { width: 54px; height: 54px; background: #fff; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #ea7a1d; font-weight: 800; font-size: 30px; overflow: hidden; }
        .band .brand .logo img { max-width: 50px; max-height: 50px; }
        .band .brand .co { font-size: 16px; font-weight: 800; letter-spacing: .3px; line-height: 1.2; }
        .band .brand .co small { display: block; font-size: 10.5px; font-weight: 400; opacity: .85; letter-spacing: 2px; }
        .band .doc { text-align: right; }
        .band .doc .ttl { font-size: 24px; font-weight: 800; letter-spacing: 1px; }
        .band .doc .no { font-size: 12px; margin-top: 4px; opacity: .95; }
        .band .doc .badge { display: inline-block; margin-top: 8px; background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.4); padding: 3px 12px; border-radius: 999px; font-size: 10.5px; letter-spacing: .5px; }

        .body { padding: 26px 32px 0; }

        /* Parties */
        .parties { display: flex; gap: 20px; margin-bottom: 22px; }
        .party { flex: 1; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; }
        .party h4 { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: #0ea5e9; margin-bottom: 8px; font-weight: 700; }
        .party .nm { font-size: 14px; font-weight: 700; color: #0f172a; }
        .party .ln { font-size: 11.5px; color: #475569; margin-top: 2px; }
        .party .meta { margin-top: 8px; font-size: 11px; color: #64748b; }
        .party .meta b { color: #334155; font-weight: 600; }

        /* Section title */
        .sec { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px; color: #0ea5e9; font-weight: 700; margin: 4px 0 8px; }

        /* Unit card */
        .unit { border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; margin-bottom: 20px; }
        .unit .grid { display: flex; flex-wrap: wrap; }
        .unit .cell { width: 33.33%; padding: 6px 8px; }
        .unit .cell .k { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; }
        .unit .cell .v { font-size: 12.5px; font-weight: 600; color: #1e293b; }

        /* Amount table */
        table.amt { width: 100%; border-collapse: collapse; }
        table.amt th { background: #0f172a; color: #fff; text-align: left; padding: 9px 14px; font-size: 11px; letter-spacing: .5px; font-weight: 600; }
        table.amt th.r, table.amt td.r { text-align: right; }
        table.amt td { padding: 9px 14px; border-bottom: 1px solid #eef2f7; font-size: 12.5px; }
        table.amt tr.sub td { color: #64748b; }
        table.amt tr.total td { font-size: 15px; font-weight: 800; color: #0f766e; border-top: 2px solid #0f766e; border-bottom: none; background: #f0fdfa; }

        .ctx { display: flex; flex-wrap: wrap; gap: 6px 22px; margin-top: 12px; padding: 10px 14px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 8px; font-size: 11px; color: #64748b; }
        .ctx b { color: #334155; font-weight: 600; }
        .terbilang { margin-top: 14px; background: #f8fafc; border-left: 4px solid #0ea5e9; padding: 10px 14px; border-radius: 0 8px 8px 0; font-size: 12px; }
        .terbilang b { color: #0f172a; }
        .terbilang .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; }

        /* Footer */
        .foot { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 30px; }
        .foot .note { font-size: 10.5px; color: #94a3b8; max-width: 58%; line-height: 1.5; }
        .sign { text-align: center; width: 200px; }
        .sign .city { font-size: 11.5px; color: #475569; }
        .sign .role { font-size: 11px; color: #64748b; margin-top: 2px; }
        .sign .nm { margin-top: 54px; font-weight: 700; border-top: 1px solid #cbd5e1; padding-top: 4px; }

        .stripe { position: absolute; bottom: 0; left: 0; right: 0; height: 10px; background: linear-gradient(110deg, #0f766e, #0ea5e9 60%, #6366f1); }

        /* Watermark + stamp BATAL */
        .watermark { position: absolute; top: 42%; left: 50%; transform: translate(-50%, -50%) rotate(-28deg); font-size: 150px; font-weight: 900; letter-spacing: 14px; color: rgba(220, 38, 38, .12); white-space: nowrap; pointer-events: none; z-index: 5; }

        .actions { padding: 16px 32px; }
        .btn { display:inline-block; padding:9px 18px; background:#0ea5e9; color:#fff; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600; }
        @media print { .actions { display: none; } .sheet { margin: 0; } }
    </style>
</head>
<body>
@php
    $k = $fp->kontrak;
    $c = $k?->customer;
    $u = $k?->unit;
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $harga = (float) ($k?->harga_jual ?? 0);
    $diskon = (float) ($k?->diskon ?? 0);
    $total = (float) ($k?->total_kontrak ?? 0);
    $dpp = round($total / 1.11);
    $ppn = $total - $dpp;
    $alamat = $c ? ($c->address ?: (method_exists($c, 'alamatRingkas') ? $c->alamatRingkas() : '—')) : '—';
    $logo = function (string $file, string $fallback) {
        $path = public_path('images/logo/'.$file);
        if (is_file($path)) {
            $mime = @mime_content_type($path) ?: 'image/png';
            return '<img src="data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path)).'" alt="">';
        }
        return $fallback;
    };
    // Yang DITAGIHKAN ke customer: jadwal tagihan SELAIN UTJ/BF (sudah dibayar saat
    // booking), UANG MASUK/ADM (subsidi developer), dan GMC (gimmick free).
    $tagihan = $k?->tagihan ?? collect();
    $billed = $tagihan->reject(fn ($t) => in_array($t->tipe, ['BF', 'ADM', 'GMC'], true))
        ->sortBy('urutan')->values();
    $totalDitagih = (float) $billed->sum(fn ($t) => (float) $t->nilai);
    $utj = (float) $tagihan->where('tipe', 'BF')->sum('nilai');
    $subsidi = (float) $tagihan->where('tipe', 'ADM')->sum('nilai');
    $dppD = round($totalDitagih / 1.11);
    $ppnD = $totalDitagih - $dppD;
@endphp
    @php($batal = ($k && $k->status === 'BATAL') || $fp->status === 'BATAL')
    <div class="sheet">
        @if ($batal)
            <div class="watermark">BATAL</div>
        @endif
        <div class="band">
            <div class="brand">
                <div class="logo">{!! $logo('mup.png', 'M') !!}</div>
                <div class="co">PT. MITRA USAHA PROPERTINDO<small>JAVA RESIDENCE</small></div>
            </div>
            <div class="doc">
                <div class="ttl">FAKTUR PENJUALAN</div>
                <div class="no">No. {{ $fp->no_fp }}</div>
                <div class="no">Tanggal: {{ optional($fp->tgl_faktur)->format('d F Y') ?? '—' }}</div>
                <div class="badge">{{ $fp->status }}</div>
            </div>
        </div>

        <div class="body">
            <div class="parties">
                <div class="party">
                    <h4>Penjual</h4>
                    <div class="nm">PT. Mitra Usaha Propertindo</div>
                    <div class="ln">Kahuripan Nirwana Ruko Avenue No. 27–30, Sidoarjo 61228</div>
                    <div class="meta"><b>Telp</b> (031) 8000-0000 &nbsp;·&nbsp; <b>NPWP</b> 01.234.567.8-901.000</div>
                </div>
                <div class="party">
                    <h4>Pembeli</h4>
                    <div class="nm">{{ $c?->full_name ?: '—' }}</div>
                    <div class="ln">{{ $alamat }}</div>
                    <div class="meta">
                        <b>NIK</b> {{ $c?->nik ?: '—' }} &nbsp;·&nbsp; <b>NPWP</b> {{ $c?->npwp ?: '—' }}<br>
                        <b>Telp</b> {{ $c?->mobile ?: $c?->phone ?: '—' }} &nbsp;·&nbsp; <b>Email</b> {{ $c?->email ?: '—' }}
                    </div>
                </div>
            </div>

            <div class="sec">Objek / Unit yang Dibeli</div>
            <div class="unit">
                <div class="grid">
                    <div class="cell"><div class="k">No. Kontrak</div><div class="v">{{ $k?->no_kontrak ?: '—' }}</div></div>
                    <div class="cell"><div class="k">Unit / Blok</div><div class="v">{{ $u?->kode_unit ?: '—' }}</div></div>
                    <div class="cell"><div class="k">Tipe</div><div class="v">{{ $u?->unitType?->name ?: '—' }}</div></div>
                    <div class="cell"><div class="k">Lokasi / Cluster</div><div class="v">{{ $u?->lokasi?->nama ?: ($u?->cluster ?: '—') }}</div></div>
                    <div class="cell"><div class="k">Luas Tanah / Bangunan</div><div class="v">{{ ($u?->luas_tanah ?: '—') }} / {{ ($u?->luas_bangunan ?: '—') }} m²</div></div>
                    <div class="cell"><div class="k">Skema Bayar</div><div class="v">{{ $k?->skema?->name ?: $k?->cara_bayar ?: '—' }}</div></div>
                </div>
                @if ($u?->alamat)
                    <div class="cell" style="width:100%;"><div class="k">Alamat Unit</div><div class="v">{{ $u->alamat }}</div></div>
                @endif
            </div>

            <div class="sec">Rincian yang Ditagihkan</div>
            <table class="amt">
                <tr><th style="width:40px;">No</th><th>Keterangan</th><th style="width:130px;">Jatuh Tempo</th><th class="r" style="width:150px;">Jumlah</th></tr>
                @forelse ($billed as $i => $t)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $t->nama_tagihan }}</td>
                        <td>{{ optional($t->jatuh_tempo)->format('d M Y') ?? '—' }}</td>
                        <td class="r">{{ $rp($t->nilai) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;color:#94a3b8;">Tidak ada tagihan.</td></tr>
                @endforelse
                <tr class="total"><td colspan="3">TOTAL DITAGIHKAN</td><td class="r">{{ $rp($totalDitagih) }}</td></tr>
            </table>

            <div class="ctx">
                <span><b>Harga Unit</b> {{ $rp($harga) }}</span>
                <span><b>UTJ</b> (sudah dibayar) {{ $rp($utj) }}</span>
                @if ($subsidi > 0)<span><b>Subsidi developer</b> (free) {{ $rp($subsidi) }}</span>@endif
                <span>DPP {{ $rp($dppD) }} · PPN 11% {{ $rp($ppnD) }}</span>
            </div>

            <div class="terbilang">
                <div class="lbl">Terbilang (yang ditagihkan)</div>
                <b>{{ ucwords(\App\Support\Terbilang::rupiah($totalDitagih)) }}</b>
            </div>

            <div class="foot">
                <div class="note">
                    Faktur ini diterbitkan otomatis atas penjualan unit yang telah berstatus TERJUAL.
                    Dokumen sah tanpa tanda tangan basah; pembayaran mengikuti Skema Bayar pada kontrak {{ $k?->no_kontrak }}.
                </div>
                <div class="sign">
                    <div class="city">Sidoarjo, {{ optional($fp->tgl_faktur)->format('d F Y') }}</div>
                    <div class="role">PT. Mitra Usaha Propertindo</div>
                    <div class="nm">Bagian Keuangan</div>
                </div>
            </div>
        </div>

        <div class="stripe"></div>
    </div>

    <div class="actions">
        <a class="btn" href="javascript:window.print()">🖨 Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">⬇ Unduh PDF</a>
    </div>
</body>
</html>
