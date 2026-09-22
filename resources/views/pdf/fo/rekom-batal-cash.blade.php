{{--
    Memo Rekom Batal Cash — 2 halaman: Memo Internal + lampiran daftar SPR.

    Bentuknya mengikuti memo rekom batal modul KPR di Back Office (Pages/BankLoan/Database/
    RekomBatal.vue) supaya dokumen yang sampai ke admin sales tetap satu rupa, TETAPI teksnya
    cash: di sana kalimatnya "tidak dapat diproses lebih lanjut dengan cara bayar KPR", yang
    salah bila SPR-nya memang tidak pernah ber-KPR.

    Semua teks dapat disunting sebelum dicetak, sama seperti memo KPR. Itu bukan kelonggaran:
    nomor memo, tujuan, dan keterangan per baris baru ditentukan saat dokumen dibuat, dan
    mengarangnya di server berarti angka yang salah ikut tercetak tanpa ada yang memeriksa.

    Kop = SATU gambar latar A4 penuh (mup-letterhead.jpeg) — di lampiran aslinya logo dan
    bilah oranye memang satu gambar, bukan dua objek. Di-embed base64 karena Browsershot
    (?pdf=1) tidak selalu mengambil berkas lewat URL.
--}}
@php
    $lh = public_path('images/mup-letterhead.jpeg');
    $kop = is_file($lh)
        ? 'data:'.(@mime_content_type($lh) ?: 'image/jpeg').';base64,'.base64_encode((string) file_get_contents($lh))
        : null;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Memo Rekom Batal Cash — Rekomendasi Pembatalan SPR Cash</title>
    <style>
        :root { --ink: #1f2937; --orange: #E8791E; }
        body { margin: 0; background: #e5e7eb; }
        .rekom { color: var(--ink); font-family: "Segoe UI", Arial, sans-serif; }
        .rekom .page-wrap { background: #e5e7eb; padding: 20px 12px; }
        .rekom .sheet {
            width: 210mm; min-height: 297mm; background: #fff; margin: 0 auto 20px;
            padding: 16mm 15mm 26mm; position: relative;
            box-shadow: 0 1px 8px rgba(0, 0, 0, .18); font-size: 12px;
        }

        /* Toolbar (tidak ikut tercetak) */
        .rekom .toolbar {
            position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 14px;
            padding: 10px 16px; background: #fff; border-bottom: 1px solid #e5e7eb; flex-wrap: wrap;
        }
        .rekom .tb-back { font-size: 13px; color: #6b7280; text-decoration: none; }
        .rekom .tb-back:hover { color: #111827; }
        .rekom .tb-hint { flex: 1; min-width: 240px; font-size: 12px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 6px 10px; }
        .rekom .tb-print { background: var(--orange); color: #fff; border: 0; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .rekom .tb-print:hover { background: #cf6910; }

        /* Field editable — di layar bergaris tipis, saat cetak polos seperti teks */
        .rekom .f { font: inherit; color: inherit; border: 0; background: transparent; padding: 0 1px; margin: 0; width: 100%; outline: none; }
        .rekom .f.c, .rekom .f.center { text-align: center; }
        .rekom .f.nm { font-weight: bold; }
        .rekom textarea.f { resize: none; overflow: hidden; line-height: 1.35; display: block; }
        @media screen {
            .rekom .f { border-bottom: 1px dashed #cbd5e1; background: #f8fafc; border-radius: 2px; }
            .rekom .f:focus { background: #eff6ff; border-bottom-color: #3b82f6; }
            .rekom .memo-meta .f { border-bottom: 0; }
        }

        /* Halaman 1 — Memo Internal */
        .rekom .memo-sheet {
            padding-top: 30mm;
            @if ($kop) background-image: url('{{ $kop }}'); @endif
            background-repeat: no-repeat; background-position: center top; background-size: 100% 100%;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .rekom .memo-title { text-align: center; font-size: 17px; font-weight: bold; letter-spacing: 1px; text-decoration: underline; margin: 4px 0 14px; }
        .rekom .memo-no { font-size: 12px; margin-bottom: 8px; text-align: center; }
        .rekom .memo-no .no-inp { display: inline-block; width: 260px; text-align: center; }
        .rekom .memo-meta { border-collapse: collapse; width: 100%; margin: 4px 0 16px; }
        .rekom .memo-meta td { border: 1px solid #111; padding: 4px 8px; vertical-align: middle; font-size: 12px; }
        .rekom .memo-meta .lbl { width: 130px; }
        .rekom .salam { margin: 4px 0 8px; }
        .rekom .body { text-align: justify; line-height: 1.6; margin: 0 0 12px; }
        .rekom .signs { display: flex; justify-content: space-between; margin-top: 30px; padding: 0 6mm; }
        .rekom .sign { width: 42%; text-align: center; font-size: 12px; }
        .rekom .sign-gap { height: 62px; }

        /* Halaman 2 — lampiran */
        .rekom .list-head { text-align: center; margin-bottom: 10px; }
        .rekom .list-title { font-weight: bold; font-size: 13px; }
        .rekom .list-title .head-inp { display: inline-block; width: auto; min-width: 380px; }
        .rekom .list-date { font-size: 11px; margin-top: 2px; }
        .rekom .list-date .dt { display: inline-block; width: 180px; }
        .rekom .list-table { border-collapse: collapse; width: 100%; font-size: 9px; table-layout: fixed; }
        .rekom .list-table th, .rekom .list-table td { border: 1px solid #111; padding: 2px 3px; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
        .rekom .list-table th { background: #f3f4f6; text-align: center; font-size: 8.5px; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .rekom .list-table td.c { text-align: center; }
        .rekom .list-table td.r { text-align: right; }

        @page { size: A4; margin: 0; }
        @media print {
            body { background: #fff; }
            .rekom .noprint { display: none !important; }
            .rekom .page-wrap { background: #fff; padding: 0; }
            .rekom .sheet { box-shadow: none; margin: 0; width: auto; min-height: auto; }
            .rekom .memo-sheet { page-break-after: always; width: 210mm; height: 297mm; }
            .rekom .list-sheet { page-break-before: always; }
            .rekom .f { border: 0 !important; background: transparent !important; }
            .rekom .list-table tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="rekom">
    <div class="toolbar noprint">
        {{-- Jalan pulang. Memo ini dibuka di tab yang sama (window.open dari respons Livewire
             diblokir peramban tanpa pesan), jadi tanpa tautan ini operator harus menekan
             tombol back peramban dan kehilangan pilihan barisnya. --}}
        <a class="tb-back" href="{{ url('/fo/rekom-batal-cash') }}">&larr; Kembali ke Rekom Batal Cash</a>
        <div class="tb-hint">
            Rekom batal SUDAH tercatat — tombol &ldquo;Ajukan Pembatalan&rdquo; pada Pembatalan Kontrak kini terbuka untuk
            {{ $jumlah }} SPR di bawah. Lengkapi kolom yang masih kosong &amp; sesuaikan header memo, lalu klik <b>Cetak</b>.
            Semua teks di halaman ini dapat diedit.
        </div>
        <button type="button" class="tb-print" onclick="window.print()">Cetak</button>
    </div>

    <div class="page-wrap">
        {{-- ══════════ HALAMAN 1 — MEMO INTERNAL ══════════ --}}
        <section class="sheet memo-sheet">
            <h1 class="memo-title">MEMO INTERNAL</h1>

            <div class="memo-no">No : <input class="f no-inp" value="{{ $memo['no'] }}"></div>

            <table class="memo-meta">
                <tbody>
                    <tr><td class="lbl">Kepada Yth</td><td>: <input class="f" value="{{ $memo['kepada'] }}"></td></tr>
                    <tr><td class="lbl">Tembusan</td><td>: <input class="f" value="{{ $memo['tembusan'] }}"></td></tr>
                    <tr><td class="lbl">Dari</td><td>: <input class="f" value="{{ $memo['dari'] }}"></td></tr>
                    <tr><td class="lbl">Hari / Tanggal</td><td>: <input class="f" value="{{ $memo['tanggal'] }}"></td></tr>
                    <tr><td class="lbl">Perihal</td><td>: <input class="f" value="{{ $memo['perihal'] }}"></td></tr>
                </tbody>
            </table>

            <p class="salam">Dengan hormat,</p>
            <p class="body">
                Berdasarkan informasi dan hasil analisa yang kami lakukan berikut kami informasikan bahwa
                <b>{{ $jumlah }} ({{ $jumlahKata }})</b> user terlampir dengan cara bayar cash tidak dapat diproses
                lebih lanjut, kami merekomendasikan agar Departemen Marketing dapat memproses pembatalan SPR
                tersebut lebih lanjut sesuai ketentuan yang berlaku.
            </p>
            <p class="body">
                Demikian informasi ini kami sampaikan, atas perhatian dan kerjasamanya kami ucapkan terima kasih.
            </p>

            <div class="signs">
                <div class="sign">
                    <input class="f center" value="Hormat kami,">
                    <div class="sign-gap"></div>
                    <input class="f center nm" value="{{ $settings->sp_signer_name ?? '' }}">
                    <input class="f center" value="{{ $settings->sp_signer_title ?? '' }}">
                </div>
                <div class="sign">
                    <input class="f center" value="Mengetahui,">
                    <div class="sign-gap"></div>
                    {{-- Sengaja KOSONG: Front Office hanya menyimpan satu identitas penandatangan
                         (CollectionSettings.sp_signer_*). Mengisinya dengan nama yang sama akan
                         mencetak dokumen yang ditandatangani orang yang sama dua kali. --}}
                    <input class="f center nm" value="">
                    <input class="f center" value="">
                </div>
            </div>
        </section>

        {{-- ══════════ HALAMAN 2 — LIST / LAMPIRAN ══════════ --}}
        <section class="sheet list-sheet">
            <div class="list-head">
                <div class="list-title">
                    <input class="f center head-inp" value="{{ $listHeading }}"> ({{ $jumlah }} User)
                </div>
                <div class="list-date">Tanggal <input class="f dt" value="{{ $listDate }}"></div>
            </div>

            <table class="list-table">
                <colgroup>
                    <col style="width:3%"><col style="width:11%"><col style="width:6%"><col style="width:7%">
                    <col style="width:8%"><col style="width:9%"><col style="width:13%"><col style="width:10%">
                    <col style="width:8%"><col style="width:10%"><col style="width:6%"><col style="width:9%">
                </colgroup>
                <thead>
                    <tr>
                        <th>NO</th><th>NO. KONTRAK / SPR</th><th>TAHAP</th><th>CLUSTER</th><th>THP</th><th>TIPE</th>
                        <th>NAMA CUSTOMER</th><th>NAMA SALES</th><th>UNIT</th><th>NILAI KONTRAK</th>
                        <th>STATUS</th><th>KETERANGAN</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $i => $r)
                        <tr>
                            <td class="c">{{ $i + 1 }}</td>
                            <td><input class="f" value="{{ $r->no_kontrak }}"></td>
                            <td><input class="f c" value="{{ $r->tahap }}"></td>
                            <td><input class="f" value="{{ $r->unit?->cluster ?: ($r->unit?->lokasi?->nama ?? '') }}"></td>
                            <td><input class="f c" value="{{ $r->unit?->tahapMaster?->nama ?? '' }}"></td>
                            <td><input class="f" value="{{ $r->unit?->unitType?->name ?? '' }}"></td>
                            <td><input class="f" value="{{ $r->customer?->full_name ?? '' }}"></td>
                            <td><input class="f" value="{{ $r->agent?->name ?? '' }}"></td>
                            <td><input class="f" value="{{ $r->unit?->kode_unit ?? '' }}"></td>
                            <td><input class="f r" value="{{ number_format((float) $r->total_kontrak, 0, ',', '.') }}"></td>
                            <td><input class="f c" value="BATAL"></td>
                            <td><input class="f" value="{{ \App\Modules\BO\IproBridge\Services\RekomBatalCashService::label($r->cara_bayar, $r->skema?->name) }}"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    </div>
</div>
</body>
</html>
