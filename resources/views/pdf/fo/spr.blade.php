<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>SPR — {{ $kontrak->no_kontrak }}</title>
    <style>
        * { box-sizing: border-box; }
        @page { size: A4 portrait; margin: 14mm 14mm 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 11px; margin: 0; padding: 26px 32px; line-height: 1.4; }
        .page { page-break-after: always; display: flex; flex-direction: column; min-height: 252mm; }
        .page:last-child { page-break-after: auto; }
        /* Kaki halaman: kotak paraf (PT MUP | PEMESAN) di kanan-bawah + nomor halaman. */
        .pagefoot { margin-top: auto; padding-top: 12px; }
        .paraf { border-collapse: collapse; margin-left: auto; width: 240px; }
        .paraf td { border: 1px solid #111; text-align: center; padding: 3px 6px; }
        .paraf .h td { font-size: 10px; font-weight: 700; }
        .paraf .p td { height: 42px; }
        .head { display: flex; align-items: center; justify-content: space-between; }
        .head .co { text-align: center; flex: 1; }
        .head .co .ttl { font-weight: 800; font-size: 15px; letter-spacing: .5px; }
        .head .co .no { font-size: 11px; }
        .brand { width: 130px; font-weight: 800; color: #ea7a1d; font-size: 16px; line-height: 1.05; text-align: right; }
        .logo { width: 130px; font-weight: 800; color: #ea7a1d; font-size: 22px; }
        table.kv { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.kv td { padding: 1.5px 4px; vertical-align: top; }
        table.kv td.k { width: 210px; }
        table.kv td.s { width: 8px; }
        h4 { margin: 14px 0 4px; font-size: 11px; }
        table.pay { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 10.5px; }
        table.pay th, table.pay td { border: 1px solid #555; padding: 3px 6px; }
        table.pay th { background: #eee; text-align: left; }
        table.pay td.r, table.pay th.r { text-align: right; }
        ol.terms { padding-left: 22px; margin: 6px 0; }
        ol.terms > li { margin-bottom: 6px; text-align: justify; }
        ol.alpha { list-style: lower-alpha; padding-left: 20px; margin: 4px 0; }
        ol.alpha li { margin-bottom: 3px; text-align: justify; }
        .clause-h { font-weight: 700; text-decoration: underline; }
        .sign { display: flex; justify-content: space-between; margin-top: 30px; text-align: center; }
        .sign .c { width: 32%; }
        .sign .nm { margin-top: 56px; font-weight: 700; }
        .foot { text-align: center; font-size: 9px; color: #555; margin-top: 6px; }
        .actions { margin-top: 18px; }
        @media print { .actions { display: none; } body { padding: 0; } }
        .btn { display:inline-block; padding:8px 16px; background:#2563eb; color:#fff; border-radius:8px; text-decoration:none; font-size:13px; }
    </style>
</head>
<body>
    @php
        $c = $kontrak->customer;
        $u = $kontrak->unit;
        $ag = $kontrak->agent;
        // Kantor agent ditelusuri lewat rantai atasan (agency_name kosong untuk semua agent).
        // Sales inhouse tidak punya kantor → '—' (menunggu keputusan tim marketing).
        $kantorAgent = $ag?->namaKantor();
        $kpr = $kontrak->cara_bayar === 'KPR';
        $alamatKtp = $c?->alamatRingkas() ?: '—';
        $unitDesc = $u?->alamat ?: trim(($u?->kode_unit ?: '').' - '.($u?->unitType?->name ?: '').' - LT '.\App\Support\Luas::rapi($u?->luas_tanah, '-').' - LB '.\App\Support\Luas::rapi($u?->luas_bangunan, '-'), ' -');
        $clusterKode = $u?->lokasi?->kode ?: ($u?->blok ?: '—');
        $lokasiNama = $u?->lokasi?->nama ?: '—';
        $harga = (float) $kontrak->harga_jual;
        // Versi ACCOUNTING (perpajakan): baris UANG MASUK (free DP + subsidi developer)
        // dilebur ke Diskon & dihilangkan dari jadwal — hanya kas riil yang tampil.
        // Controller cetak accounting mengirim override ($tagihan terfilter, $diskon,
        // $net). Cetak SPR biasa tanpa override → pakai nilai default kontrak.
        $accounting = $accounting ?? false;
        $tagihanSrc = $tagihan ?? $kontrak->tagihan;
        $diskonVal  = $diskon ?? (float) $kontrak->diskon;
        $netVal     = $net ?? (float) $kontrak->total_kontrak;
        // Jadwal pembayaran dari Jadwal Tagihan kontrak (integrasi Skema Bayar).
        // Fallback ke baris skema bila tagihan belum terbentuk.
        $payRows = [];
        foreach ($tagihanSrc ?? [] as $t) {
            // GMC (gimmick FREE) hanya internal/BO — tidak ditampilkan di jadwal pembayaran SPR.
            if (($t->tipe ?? '') === 'GMC') {
                continue;
            }
            $payRows[] = ['tipe' => $t->tipe, 'nama' => $t->nama_tagihan, 'jatuh' => $t->jatuh_tempo, 'amount' => (float) $t->nilai, 'paid' => (float) $t->pelunasan];
        }
        if (empty($payRows)) {
            foreach ($kontrak->skema?->lines ?? [] as $line) {
                $amt = $line->basis === 'NOMINAL' ? (float) $line->nominal : round($harga * (float) $line->persen / 100, 2);
                $jt = $kontrak->tgl_kontrak ? $kontrak->tgl_kontrak->copy()->addDays((int) $line->jatuh_tempo_hari) : null;
                $payRows[] = ['tipe' => $line->tipe, 'nama' => $line->nama_termin, 'jatuh' => $jt, 'amount' => $amt, 'paid' => 0];
            }
        }

        // Jadwal Tagihan sudah berstruktur final dari generator (mis. FREE DP: DP1 +
        // UANG MASUK; DP3X: DP + UANG MASUK), jadi ditampilkan apa adanya.
        // Gimmick yang diberikan → "sudah termasuk".
        $gimmickNames = ($kontrak->gimmicks ?? collect())->where('diberikan', true)->pluck('nama_barang')->filter()->all();
        $payTotal = array_sum(array_column($payRows, 'amount')) ?: $netVal;

        // Teks pasal: versi Template Dokumen yang berlaku pada TANGGAL KONTRAK (cluster + tahap
        // unit lebih dulu, lalu cluster, lalu semua cluster) → SPR lama tetap tercetak dengan teks saat ditandatangani.
        // $isiDokumen = pratinjau dari Pengaturan → Template Dokumen.
        $dok = array_replace(
            \App\Modules\FO\Komunikasi\Support\DokumenBawaan::spr(),
            (array) ($isiDokumen ?? \App\Modules\FO\Komunikasi\Models\TemplateDokumen::berlakuUntuk('SPR', $u?->lokasi_id, $kontrak->tgl_kontrak, $u?->tahap_id)?->isi ?? []),
        );
        $nilaiDok = [
            'nama_perusahaan' => $dok['nama_perusahaan'],
            'nama_customer' => (string) ($c?->full_name ?: ''),
            'no_kontrak' => (string) $kontrak->no_kontrak,
            'unit' => (string) ($u?->kode_unit ?: ''),
            'cluster' => (string) $lokasiNama,
            'tanggal' => (string) optional($kontrak->tgl_kontrak)->format('d M Y'),
        ];
        $tx = fn ($teks) => \App\Modules\FO\Komunikasi\Support\DokumenBawaan::teks($teks, $nilaiDok);
        // $editor = editor visual Template Dokumen: teks aturan jadi span yang bisa diedit
        // (kata kunci tidak diganti). Tanpa editor → keluaran cetak biasa.
        $editor = $editor ?? null;
        $ed = function (string $kunci, bool $polos = false) use ($dok, $tx, $editor) {
            $v = (string) data_get($dok, $kunci, '');
            if (! $editor) {
                return $polos ? e($v) : $tx($v);
            }

            return \App\Modules\FO\Komunikasi\Support\EditorDokumen::kolom($kunci, $v, $polos);
        };
        // Kelompokkan pasal per halaman: pasal bertanda "halaman baru" membuka halaman berikutnya.
        $halamanPasal = [];
        foreach ((array) ($dok['pasal'] ?? []) as $iPs => $ps) {
            $ps['_i'] = $iPs;
            if ($halamanPasal === [] || ! empty($ps['halaman_baru'])) {
                $halamanPasal[] = [];
            }
            $halamanPasal[array_key_last($halamanPasal)][] = $ps;
        }
        if ($halamanPasal === []) {
            $halamanPasal[] = [];
        }
        $totHal = 1 + count($halamanPasal);
    @endphp

    {{-- Kaki halaman dipakai berulang: kotak paraf di kanan-bawah + nomor halaman. --}}
    @php($pageFoot = function (int $hal) use ($totHal, $ed) {
        return '<div class="pagefoot"><table class="paraf">'
            .'<tr class="h"><td>'.$ed('paraf_perusahaan', true).'</td><td>PEMESAN</td></tr>'
            .'<tr class="p"><td></td><td></td></tr></table>'
            .'<div class="foot">'.$hal.' Dari Halaman '.$totHal.'</div></div>';
    })

    {{-- Logo kantor: ambil dari public/images/logo/<file>. Bila belum ada, pakai
         fallback (teks/inisial) supaya layout tetap aman. Di-embed base64 agar
         tampil baik di print HTML maupun PDF. --}}
    @php($logoTag = function (string $file, string $fallback, string $style) {
        $path = public_path('images/logo/'.$file);
        if (is_file($path)) {
            $mime = @mime_content_type($path) ?: 'image/png';
            return '<img src="data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path)).'" style="'.$style.'" alt="">';
        }
        return $fallback;
    })

    {{-- ════════════ HALAMAN 1 ════════════ --}}
    <div class="page">
        <div class="head">
            {{-- KIRI: logo MUP (perusahaan). --}}
            {!! $logoTag('MUP.png', '<div class="logo">M</div>', 'max-width:70px; max-height:64px;') !!}
            <div class="co">
                <div class="ttl">SURAT PEMESANAN RUMAH{{ ($kontrak->tahap ?? 'SPRS') === 'SPR' ? '' : ' SEMENTARA' }}</div>
                <div class="no">Nomor : {{ $kontrak->no_kontrak }}</div>
                <div class="no">Tanggal : {{ optional($kontrak->tgl_kontrak)->format('d M Y') }}</div>
            </div>
            {{-- KANAN ATAS: logo Java Residence (daun + wordmark). --}}
            {!! $logoTag('logo-cop.png', '<div class="brand">JAVA<br>RESIDENCE</div>', 'max-width:150px; max-height:60px; object-fit:contain;') !!}
        </div>

        <p style="margin-top:14px;">Yang Bertanda Tangan Di bawah Ini :</p>
        <table class="kv">
            <tr><td class="k">Nama</td><td class="s">:</td><td>{{ $c?->full_name ?: '—' }}</td></tr>
            <tr><td class="k">NIK</td><td class="s">:</td><td>{{ $c?->nik ?: '—' }}</td></tr>
            <tr><td class="k">NPWP</td><td class="s">:</td><td>{{ $c?->npwp ?: '—' }}</td></tr>
            <tr><td class="k">Alamat KTP</td><td class="s">:</td><td>{{ $alamatKtp }}</td></tr>
            <tr><td class="k">Alamat Korespondensi</td><td class="s">:</td><td>{{ $c?->mail_address ?: $alamatKtp }}</td></tr>
            <tr><td class="k">No. Handphone / No. Whatsapp</td><td class="s">:</td><td>{{ $c?->mobile ?: $c?->phone ?: '—' }}</td></tr>
            <tr><td class="k">Email</td><td class="s">:</td><td>{{ $c?->email ?: '—' }}</td></tr>
        </table>

        <p style="margin-top:10px; text-align:justify;">{!! $ed('kalimat_pembuka') !!}</p>
        <table class="kv">
            <tr><td class="k">1. Unit</td><td class="s">:</td><td>{{ $unitDesc }}</td></tr>
            <tr><td class="k">2. Cluster</td><td class="s">:</td><td>{{ $clusterKode }}</td></tr>
            <tr><td class="k">3. Lokasi</td><td class="s">:</td><td>{{ $lokasiNama }}</td></tr>
            <tr><td class="k">4. Luas Tanah</td><td class="s">:</td><td>{{ \App\Support\Luas::rapi($u?->luas_tanah) }}</td></tr>
            <tr><td class="k">5. Luas Bangunan</td><td class="s">:</td><td>{{ \App\Support\Luas::rapi($u?->luas_bangunan) }}</td></tr>
            <tr><td class="k">6. Peruntukkan</td><td class="s">:</td><td>RUMAH</td></tr>
            <tr><td class="k">7. Harga Include PPN</td><td class="s">:</td><td>Rp {{ number_format($harga, 0, ',', '.') }}</td></tr>
            <tr><td class="k">8. Diskon</td><td class="s">:</td><td>Rp {{ number_format($diskonVal, 0, ',', '.') }}</td></tr>
            <tr><td class="k">9. Harga Pemesanan (setelah Diskon)</td><td class="s">:</td><td>Rp {{ number_format($netVal, 0, ',', '.') }}</td></tr>
            <tr><td class="k">10. Cara Pembayaran</td><td class="s">:</td><td>{{ $kontrak->cara_bayar }}</td></tr>
            <tr><td class="k">11. Nama Sales</td><td class="s">:</td><td>{{ $ag?->name ?: '—' }}</td></tr>
            <tr><td class="k">12. Nama Agent Properti</td><td class="s">:</td><td>{{ $kantorAgent ?: '—' }}</td></tr>
        </table>

        <p style="margin-top:10px;">{!! $ed('kalimat_syarat') !!}</p>
        <h4>{!! $ed('judul_pasal_bayar') !!}</h4>
        <div style="font-weight:700;">{{ $kontrak->cara_bayar }}</div>
        <table class="pay">
            <tr><th style="width:30px;">No</th><th>Keterangan</th><th style="width:110px;">Tanggal</th><th class="r" style="width:130px;">Jumlah (Rp.)</th></tr>
            @forelse ($payRows as $i => $r)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $r['nama'] }}</td>
                    <td>{{ $r['jatuh'] ? $r['jatuh']->format('d M Y') : '—' }}</td>
                    <td class="r">{{ number_format($r['amount'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td>1</td><td>UANG TANDA JADI</td><td>{{ optional($kontrak->tgl_kontrak)->format('d M Y') }}</td><td class="r">{{ number_format((float) ($kontrak->reservasi->booking_fee ?? 0), 0, ',', '.') }}</td></tr>
            @endforelse
            <tr><td colspan="3" class="r" style="font-weight:700;">Total</td><td class="r" style="font-weight:700;">{{ number_format($payTotal, 0, ',', '.') }}</td></tr>
        </table>

        @if (count($gimmickNames))
            <p style="margin-top:8px; margin-bottom:2px;">Keterangan harga di atas, sudah termasuk :</p>
            <ul style="margin:0; padding-left:18px;">
                @foreach ($gimmickNames as $n)
                    <li>{{ $n }}</li>
                @endforeach
            </ul>
        @endif
        {!! $pageFoot(1) !!}
    </div>

    {{-- ════════════ HALAMAN 2 dst — pasal dari Template Dokumen ════════════ --}}
    @foreach ($halamanPasal as $h => $daftar)
        <div class="page">
            <ol class="terms" style="list-style:none; padding-left:0;">
                @foreach ($daftar as $ps)
                    @if ($editor)
                        @php($p = 'pasal.'.$ps['_i'])
                        <li class="ed-item">{!! \App\Modules\FO\Komunikasi\Support\EditorDokumen::kendali('pasal', $ps['_i'], [
                                ['naik', '↑', 'Geser pasal ke atas'], ['turun', '↓', 'Geser pasal ke bawah'],
                                ['halaman', empty($ps['halaman_baru']) ? 'Mulai halaman baru' : 'Gabung ke halaman sebelumnya', 'Pasal ini membuka halaman baru / ikut halaman sebelumnya'],
                                ['butir', '+ Butir', 'Tambah butir a, b, c …'], ['tambah', '+ Pasal', 'Tambah pasal di bawah ini'], ['hapus', 'Hapus', 'Hapus pasal ini'],
                            ]) !!}<span class="clause-h">{!! $ed($p.'.judul') !!}</span> {!! $ed($p.'.isi') !!}
                            @if (! empty($ps['butir']))
                                <ol class="alpha">
                                    @foreach ($ps['butir'] as $j => $b)
                                        <li class="ed-item">{!! \App\Modules\FO\Komunikasi\Support\EditorDokumen::kendali($p.'.butir', $j, [
                                            ['naik', '↑', 'Geser butir ke atas'], ['turun', '↓', 'Geser butir ke bawah'], ['tambah', '+', 'Tambah butir di bawah ini'], ['hapus', 'Hapus', 'Hapus butir ini'],
                                        ]) !!}{!! $ed($p.'.butir.'.$j) !!}</li>
                                    @endforeach
                                </ol>
                            @endif
                        </li>
                    @else
                    <li><span class="clause-h">{!! $tx($ps['judul'] ?? '') !!}</span>@if (filled($ps['isi'] ?? null)) {!! $tx($ps['isi']) !!}@endif
                        @if (! empty($ps['butir']))
                            <ol class="alpha">
                                @foreach ($ps['butir'] as $b)
                                    @if (filled($b))<li>{!! $tx($b) !!}</li>@endif
                                @endforeach
                            </ol>
                        @endif
                    </li>
                    @endif
                @endforeach
            </ol>
            @if ($editor && $daftar === [])
                <button type="button" class="ed-add" data-op="tambah" data-list="pasal" data-i="-1">+ Tambah pasal</button>
            @endif

            @if ($loop->last)
                <p style="margin-top:10px;">{!! $ed('kalimat_penutup') !!}</p>
                <p style="margin-top:14px;">{!! $ed('kota', true) !!}, {{ optional($kontrak->tgl_kontrak)->format('d M Y') }}</p>
                <div class="sign" style="margin-top:6px;">
                    <div class="c" style="font-weight:700;">Pemesan</div>
                    <div class="c"></div>
                    <div class="c" style="font-weight:700; white-space:nowrap;">{!! $ed('nama_perusahaan', true) !!}</div>
                </div>
                <div class="sign" style="margin-top:0;">
                    <div class="c"><div class="nm">{{ $c?->full_name ?: '' }}</div>Pemesan</div>
                    <div class="c"><div class="nm">{{ $ag?->name ?: '' }}@if ($kantorAgent && $kantorAgent !== $ag?->name) <br><span style="font-weight:400;font-size:9px;">{{ $kantorAgent }}</span>@endif</div>Sales</div>
                    <div class="c"><div class="nm">{!! $ed('penandatangan_nama', true) !!}</div>{!! $ed('penandatangan_jabatan', true) !!}</div>
                </div>
            @endif
            {!! $pageFoot($h + 2) !!}
        </div>
    @endforeach

    @if ($editor)
        @include('fo.template-dokumen.editor', ['editor' => $editor, 'isi' => $dok])
    @endif
    <div class="actions">
        <a class="btn" href="javascript:window.print()">Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">Unduh PDF</a>
    </div>
</body>
</html>
