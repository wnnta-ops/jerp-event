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
        $kpr = $kontrak->cara_bayar === 'KPR';
        $alamatKtp = $c?->alamatRingkas() ?: '—';
        $unitDesc = $u?->alamat ?: trim(($u?->kode_unit ?: '').' - '.($u?->unitType?->name ?: '').' - LT '.($u?->luas_tanah ?: '-').' - LB '.($u?->luas_bangunan ?: '-'), ' -');
        $clusterKode = $u?->lokasi?->kode ?: ($u?->blok ?: '—');
        $lokasiNama = $u?->lokasi?->nama ?: '—';
        $harga = (float) $kontrak->harga_jual;
        // Jadwal pembayaran dari Jadwal Tagihan kontrak (integrasi Skema Bayar).
        // Fallback ke baris skema bila tagihan belum terbentuk.
        $payRows = [];
        foreach ($kontrak->tagihan ?? [] as $t) {
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
        $payTotal = array_sum(array_column($payRows, 'amount')) ?: (float) $kontrak->total_kontrak;
        $totHal = 5;
    @endphp

    {{-- Kaki halaman dipakai berulang: kotak paraf di kanan-bawah + nomor halaman. --}}
    @php($pageFoot = function (int $hal) use ($totHal) {
        return '<div class="pagefoot"><table class="paraf">'
            .'<tr class="h"><td>PT MUP</td><td>PEMESAN</td></tr>'
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
            {!! $logoTag('mup.png', '<div class="logo">M</div>', 'max-width:120px; max-height:64px;') !!}
            <div class="co">
                <div class="ttl">SURAT PEMESANAN RUMAH{{ ($kontrak->tahap ?? 'SPRS') === 'SPR' ? '' : ' SEMENTARA' }}</div>
                <div class="no">Nomor : {{ $kontrak->no_kontrak }}</div>
                <div class="no">Tanggal : {{ optional($kontrak->tgl_kontrak)->format('d M Y') }}</div>
            </div>
            {!! $logoTag('java-residence.png', '<div class="brand">JAVA<br>RESIDENCE</div>', 'max-width:140px; max-height:64px;') !!}
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

        <p style="margin-top:10px; text-align:justify;">Dengan ini menyatakan telah memesan kepada PT. MITRA USAHA PROPERTINDO, berkedudukan di Kecamatan Sidoarjo, Kabupaten Sidoarjo, untuk pembelian obyek yang berlokasi di Perumahan Java Residence, Kabupaten Sidoarjo, sebagai berikut :</p>
        <table class="kv">
            <tr><td class="k">1. Unit</td><td class="s">:</td><td>{{ $unitDesc }}</td></tr>
            <tr><td class="k">2. Cluster</td><td class="s">:</td><td>{{ $clusterKode }}</td></tr>
            <tr><td class="k">3. Lokasi</td><td class="s">:</td><td>{{ $lokasiNama }}</td></tr>
            <tr><td class="k">4. Luas Tanah</td><td class="s">:</td><td>{{ $u?->luas_tanah ?: '—' }}</td></tr>
            <tr><td class="k">5. Luas Bangunan</td><td class="s">:</td><td>{{ $u?->luas_bangunan ?: '—' }}</td></tr>
            <tr><td class="k">6. Peruntukkan</td><td class="s">:</td><td>RUMAH</td></tr>
            <tr><td class="k">7. Harga Include PPN</td><td class="s">:</td><td>Rp {{ number_format($harga, 0, ',', '.') }}</td></tr>
            <tr><td class="k">8. Diskon</td><td class="s">:</td><td>Rp {{ number_format((float) $kontrak->diskon, 0, ',', '.') }}</td></tr>
            <tr><td class="k">9. Harga Pemesanan (setelah Diskon)</td><td class="s">:</td><td>Rp {{ number_format((float) $kontrak->total_kontrak, 0, ',', '.') }}</td></tr>
            <tr><td class="k">10. Cara Pembayaran</td><td class="s">:</td><td>{{ $kontrak->cara_bayar }}</td></tr>
            <tr><td class="k">11. Nama Sales</td><td class="s">:</td><td>{{ $ag?->name ?: '—' }}</td></tr>
            <tr><td class="k">12. Nama Agent Properti</td><td class="s">:</td><td>{{ $ag?->agency_name ?: '—' }}</td></tr>
        </table>

        <p style="margin-top:10px;">Untuk pemesanan tersebut di atas maka dengan ini pemesan menyetujui syarat dan ketentuan sebagai berikut:</p>
        <h4>I. Harga Tanah dan Bangunan wajib dibayar oleh pemesan sesuai ketentuan sebagai berikut:</h4>
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

    {{-- ════════════ HALAMAN 2 ════════════ --}}
    <div class="page">
        <ol class="terms" start="2" style="list-style:none; padding-left:0;">
            <li><span class="clause-h">II. Harga Pemesanan Sudah Termasuk :</span>
                <ol class="alpha">
                    <li>Sertipikat Hak Guna Bangunan (SHGB).</li>
                    <li>IMB (Ijin Mendirikan Bangunan) / PBG (Persetujuan Bangunan Gedung).</li>
                    <li>Pajak Pertambahan Nilai (PPN).</li>
                    <li>Pemasangan dan penyambungan instalasi jaringan air bersih.</li>
                    <li>Pemasangan dan penyambungan instalasi jaringan listrik PLN sesuai standart Developer.</li>
                    <li>Retribusi Penyediaan Lahan Makam Estate.</li>
                </ol>
            </li>
            <li><span class="clause-h">III. Harga Pemesanan Belum Termasuk :</span>
                <ol class="alpha">
                    <li>Pajak Pertambahan Nilai Barang Mewah (PPNBM).</li>
                    <li>Biaya Administrasi (apabila ada).</li>
                    <li>Biaya Iuran Pemeliharaan Lingkungan (IPL) dan Biaya Utilitas.</li>
                    <li>Biaya Pengurusan KPR (*kecuali disepakati lain).</li>
                    <li>Semua Bea/Pajak, Biaya, Retribusi, Pungutan lainnya berdasarkan keputusan atau peraturan pemerintah yang berlaku.</li>
                </ol>
            </li>
            <li><span class="clause-h">IV.</span> Dalam hal pemesan telah membayar Uang Tanda Jadi dan pemesan mengakhiri pemesanannya dengan alasan apapun, maka PT. MITRA USAHA PROPERTINDO dapat mengakhiri Surat Pemesanan ini sesuai butir XII di bawah, terkait dengan seluruh pembayaran yang telah dilakukan pemesan tidak dapat dituntut kembali atau ditarik dari PT. MITRA USAHA PROPERTINDO.</li>
            <li><span class="clause-h">V.</span> Untuk melaksanakan penandatanganan Akta Jual Beli (AJB) di hadapan Pejabat Pembuat Akta Tanah (PPAT) yang ditunjuk oleh PT. MITRA USAHA PROPERTINDO, pemesan wajib terlebih dahulu membayar seluruh bea/pajak dan biaya yang belum termasuk dalam Harga Tanah dan Bangunan butir III di atas.</li>
            <li><span class="clause-h">VI.</span> Sebelum dilaksanakannya AJB di hadapan PPAT (untuk selanjutnya disebut AJB PPAT), apabila terjadi kenaikan tarif dan/atau pengenaan baru berdasarkan suatu perubahan atau peraturan baru yang dikeluarkan/diberlakukan oleh Pemerintah atas suatu pajak/bea dan biaya termasuk namun tidak terbatas pada PPN, BPHTB, atau kenaikan tarif NJOP, maka seluruhnya wajib ditanggung dan dibayar sepenuhnya oleh pemesan sebelum penandatanganan AJB PPAT, sejauh hal tersebut tidak disebabkan oleh PT. MITRA USAHA PROPERTINDO.</li>
            <li><span class="clause-h">VII.</span> Dalam hal pemesan belum membayar seluruh pajak/bea dan biaya sebagaimana butir V dan butir VI di atas sebelum dilaksanakannya penandatanganan AJB PPAT, maka PT. MITRA USAHA PROPERTINDO tidak wajib melaksanakan penandatanganan AJB PPAT, dan segala risiko serta akibatnya menjadi tanggungan pemesan sepenuhnya.</li>
            <li><span class="clause-h">VIII.</span> Apabila pemesan lalai dalam hal kurang atau terlambat melakukan suatu pembayaran berdasarkan Surat Pemesanan ini, maka pemesan dikenakan dan wajib membayar kepada PT. MITRA USAHA PROPERTINDO denda sebesar 1‰ (satu permil) per setiap hari keterlambatan dari jumlah terhutang sejak tanggal seharusnya dibayar sampai dilunasi seluruhnya, dengan keterlambatan maksimal 30 hari kerja dan maksimal denda 5% dari jumlah terhutang.</li>
            <li><span class="clause-h">IX.</span> Apabila pemesan lalai dalam hal kurang atau terlambat melakukan suatu pembayaran yang berlangsung maksimal 14 (empat belas) hari kalender dan/atau mundur sepihak karena alasan dan/atau kesalahan pemesan, maka PT. MITRA USAHA PROPERTINDO dapat mengakhiri Surat Pemesanan ini sesuai butir XII di bawah. Terhadap seluruh uang yang telah dibayar oleh pemesan (kecuali Uang Tanda Jadi) akan dikembalikan setelah dipotong biaya administrasi pengakhiran sebesar 30% (tiga puluh persen).</li>
        </ol>
        {!! $pageFoot(2) !!}
    </div>

    {{-- ════════════ HALAMAN 3 ════════════ --}}
    <div class="page">
        <ol class="terms" style="list-style:none; padding-left:0;">
            <li><span class="clause-h">X.</span> Semua pembayaran kepada PT. MITRA USAHA PROPERTINDO wajib dilakukan oleh pemesan kepada Bagian Keuangan di Kantor Pemasaran Mitra Usaha Propertindo, Kahuripan Nirwana Ruko Avenue Nomor 27-30, Sidoarjo – 61228, atau pembayaran melalui/transfer dengan mencantumkan nama pemesan, nomor Blok, maksud pembayaran, ditujukan ke rekening BCA 6155899909 atas nama PT. Mitra Usaha Propertindo. Seluruh pembayaran dianggap sah apabila telah diterima, efektif dan/atau dapat dicairkan, serta dibuktikan dengan dikeluarkannya kwitansi resmi.</li>
            <li><span class="clause-h">XI. PENGURUSAN FASILITAS KREDIT MELALUI BANK/LEMBAGA KEUANGAN/PEMBIAYAAN</span>
                <ol class="alpha">
                    <li>Pemesan wajib melengkapi data-data yang diperlukan oleh Bank/Lembaga Keuangan/Pembiayaan selambat-lambatnya 10 (sepuluh) hari kalender setelah tanggal pembayaran Tanda Jadi dan/atau 10 hari kalender sebelum pembayaran terakhir Uang Muka secara angsuran. Apabila lewat, pemesan dinyatakan lalai.</li>
                    <li>Untuk pemesan promo Free Uang Muka, wajib melengkapi data maksimal 10 hari kalender setelah pembayaran Uang Tanda Jadi.</li>
                    <li>Apabila pemesan tidak memenuhi undangan wawancara, atau sudah disetujui kredit namun belum akad kredit di hadapan Notaris dalam 14 hari kalender, maka pemesan lalai dan uang (kecuali UTJ) dikembalikan setelah dipotong 30%.</li>
                    <li>Apabila pemesan harus menambah Uang Muka, wajib dilunasi selambat-lambatnya 14 hari kalender setelah surat persetujuan kredit. Lewat tempo: dapat pengajuan KPR ulang 1 kali pada bank lain (14 hari), atau ubah cara bayar Cash Keras (14 hari, harga mengikuti SPR awal); jika lewat, dinyatakan batal dan uang (kecuali UTJ) dikembalikan dipotong 10%.</li>
                    <li>Apabila permohonan kredit ditolak (dibuktikan surat penolakan bank), PT. MITRA USAHA PROPERTINDO berhak mengakhiri SPR; Uang Muka dikembalikan dipotong 10%, dan Uang Tanda Jadi bersifat Refund.</li>
                    <li>Apabila pemesan melakukan pelanggaran salah satunya pemalsuan dan/atau rubah data, maka UTJ dianggap hangus dan pemesan menerima segala konsekuensi serta akibat hukumnya.</li>
                </ol>
            </li>
            <li><span class="clause-h">XII.</span> Untuk pengakhiran Surat Pemesanan ini, PT. MITRA USAHA PROPERTINDO dan Pemesan sepakat melepaskan ketentuan Pasal 1265, 1266, 1267 KUH Perdata, dan pemesan memberikan kuasa sepenuhnya kepada PT. MITRA USAHA PROPERTINDO untuk menandatangani surat pengakhirannya, yang berlaku efektif sejak tanggal pengiriman surat pengakhiran tanpa perlu proses Pengadilan.</li>
            <li><span class="clause-h">XIII. KETENTUAN GANTI CARA BAYAR.</span> Apabila pemesan bermaksud mengubah cara bayar, wajib disampaikan tertulis kepada PT. MITRA USAHA PROPERTINDO; harga pembelian menjadi kewenangan PT. MITRA USAHA PROPERTINDO, dan pemesan bertanggung jawab atas kewajiban perpajakan yang timbul.</li>
            <li><span class="clause-h">XIV. KETENTUAN PINDAH BLOK DAN NOMOR TANAH BESERTA BANGUNAN.</span>
                <ol class="alpha">
                    <li>Pemesan harus memberitahukan secara tertulis.</li>
                    <li>Pemindahan oleh PT. MITRA USAHA PROPERTINDO karena perubahan peruntukan tidak dikenakan biaya, dan akan diberitahukan terlebih dahulu.</li>
                    <li>Pemindahan atas keinginan pemesan diperbolehkan dengan ketentuan: mengajukan surat permohonan & disetujui; pembayaran lama (dikurangi PPN/PPh) diperhitungkan ke blok baru; harga lama dari saat pemesanan, harga baru dari saat pindah; menandatangani seluruh dokumen yang dipersyaratkan.</li>
                </ol>
            </li>
        </ol>
        {!! $pageFoot(3) !!}
    </div>

    {{-- ════════════ HALAMAN 4 ════════════ --}}
    <div class="page">
        <ol class="terms" style="list-style:none; padding-left:0;">
            <li><span class="clause-h">XV. KETENTUAN PENGALIHAN HAK, KEWAJIBAN DAN TANGGUNG JAWAB SERTA GANTI NAMA.</span>
                <ol class="alpha">
                    <li>Pemesan mengajukan permohonan tertulis bersama pembeli baru (PIHAK KETIGA) menghadap PT. MITRA USAHA PROPERTINDO.</li>
                    <li>Apabila menggunakan fasilitas KPR, harus ada persetujuan tertulis dari Bank/Lembaga Keuangan/Pembiayaan.</li>
                    <li>Pemesan wajib membayar biaya administrasi pengalihan hak sebesar 2,5% dari Harga Pemesanan (dibebaskan untuk 1 kali pengalihan).</li>
                    <li>Ganti nama ke pihak keluarga (orang tua, istri/suami harta campur, anak kandung yang dapat dibuktikan) dikenakan biaya administrasi Rp 1.000.000,- per kejadian, maksimal 2 kali.</li>
                    <li>Pemesan dan/atau PIHAK KETIGA bertanggung jawab atas kewajiban perpajakan yang timbul.</li>
                    <li>Semua ketentuan SPR ini tetap berlaku terhadap pemesan dan/atau PIHAK KETIGA.</li>
                    <li>Menandatangani seluruh dokumen yang dipersyaratkan.</li>
                    <li>Proses pengalihan dilakukan dalam 14 hari kerja sejak dokumen dinyatakan lengkap.</li>
                </ol>
            </li>
            <li><span class="clause-h">XVI. KETENTUAN ESTATE MANAJEMEN.</span> Pemesan sepakat patuh dan tertib terhadap ketentuan Estate Manajemen PT. MITRA USAHA PROPERTINDO demi kenyamanan dan keamanan kawasan; apabila tidak bersedia mengikuti tata tertib, pemesan bersedia menerima konsekuensi sesuai peraturan Estate Manajemen.</li>
            <li><span class="clause-h">XVII. FORCE MAJEURE.</span> PT. MITRA USAHA PROPERTINDO dan Pemesan setuju mengadakan perubahan/penambahan atas SPR apabila terjadi Force Majeure (gempa bumi, banjir, bencana alam, epidemi, pandemi, huru-hara, perang, keadaan politik, atau kelangkaan bahan bangunan yang mempengaruhi kegiatan usaha properti).</li>
        </ol>
        {!! $pageFoot(4) !!}
    </div>

    {{-- ════════════ HALAMAN 5 ════════════ --}}
    <div class="page">
        <ol class="terms" style="list-style:none; padding-left:0;">
            <li><span class="clause-h">XVII. KETENTUAN SERAH TERIMA.</span>
                <ol class="alpha">
                    <li>1 Lantai paling lambat 18 Bulan setelah pembayaran lunas (cash/InHouse) atau setelah akad kredit (KPR).</li>
                    <li>2 Lantai paling lambat 24 Bulan setelah pembayaran lunas (cash/InHouse) atau setelah akad kredit (KPR).</li>
                    <li>Keterlambatan serah terima dikenakan denda 0,5‰ per bulan dari Harga Pemesanan, maksimum 2 bulan keterlambatan.</li>
                    <li>Pemesan wajib mentaati seluruh aturan PT. MITRA USAHA PROPERTINDO/pengelola termasuk IPL, abodemen listrik/air, keamanan, kebersihan, PBB setelah serah terima (BAST).</li>
                    <li>Pemesan tidak diperbolehkan mengubah desain sebelum AJB & BAST; perubahan setelahnya wajib izin tertulis dan biaya menjadi tanggung jawab pemesan (khusus cash/Inhouse, selain perubahan façade).</li>
                    <li>Garansi bangunan dianggap hangus bila pemesan mengajukan desain ulang/perubahan desain.</li>
                </ol>
            </li>
            <li><span class="clause-h">XVIII. PENYELESAIAN PERSELISIHAN.</span>
                <ol class="alpha">
                    <li>Jika timbul perselisihan dalam melaksanakan Surat Pemesanan ini, maka akan diselesaikan oleh PT. MITRA USAHA PROPERTINDO dan Pemesan secara musyawarah.</li>
                    <li>Apabila dalam jangka waktu 30 (tiga puluh) hari sejak sengketa atau beda pendapat tersebut penyelesaian secara musyawarah tidak tercapai, maka PT. MITRA USAHA PROPERTINDO dan Pemesan sepakat untuk menyelesaikannya melalui Pengadilan Negeri Sidoarjo.</li>
                </ol>
            </li>
        </ol>

        <p style="margin-top:10px;">Demikian Surat Pemesanan ini dibuat untuk dapat dilaksanakan sebagaimana mestinya.</p>
        <p style="margin-top:14px;">Sidoarjo, {{ optional($kontrak->tgl_kontrak)->format('d M Y') }}</p>
        <div class="sign" style="margin-top:6px;">
            <div class="c" style="font-weight:700;">Pemesan</div>
            <div class="c"></div>
            <div class="c" style="font-weight:700; white-space:nowrap;">PT. MITRA USAHA PROPERTINDO</div>
        </div>
        <div class="sign" style="margin-top:0;">
            <div class="c"><div class="nm">{{ $c?->full_name ?: '' }}</div>Pemesan</div>
            <div class="c"><div class="nm">{{ $ag?->name ?: '' }}@if ($ag?->agency_name) <br><span style="font-weight:400;font-size:9px;">{{ $ag->agency_name }}</span>@endif</div>Sales</div>
            <div class="c"><div class="nm">Rachmat Suhendra</div>Direktur</div>
        </div>
        {!! $pageFoot(5) !!}
    </div>

    <div class="actions">
        <a class="btn" href="javascript:window.print()">Cetak</a>
        <a class="btn" style="background:#475569" href="?pdf=1">Unduh PDF</a>
    </div>
</body>
</html>
