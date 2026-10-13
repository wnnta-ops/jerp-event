<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Print;

use App\Domain\BusinessSupport\Models\BsCashAdvance;
use App\Domain\BusinessSupport\Models\BsEmployee;

/**
 * Cetak PENGAJUAN bon sementara, mengikuti dua formulir eProperty yang dipakai selama ini.
 *
 * Ambangnya nominal Rp 1.000.000:
 *   · di BAWAH  → satu halaman "KAS BON";
 *   · MULAI dari 1 juta → dua halaman: "BUKTI PERMOHONAN PEMBAYARAN" + "MEMO INTERNAL".
 *
 * Ambang ini MENGACU ke konstanta rantai persetujuan bon (BsCashAdvance::APPROVAL_THRESHOLD),
 * jadi formulir yang tercetak selalu sejalan dengan siapa yang tanda tangan — bukan dua
 * aturan berbeda untuk satu dokumen yang sama.
 *
 * Nominal TEPAT Rp 1.000.000 memakai formulir BESAR dan 9 jenjang persetujuan.
 */
final class PengajuanKasbonPrinter
{
    /**
     * Nominal mulai dari angka ini memakai BPP + Memo Internal.
     *
     * MENGACU ke ambang rantai persetujuan, bukan angka tersendiri: formulir yang tercetak
     * harus selalu sejalan dengan siapa yang tanda tangan. Dua konstanta terpisah pernah
     * membuat keduanya berbeda pendapat soal nominal TEPAT 1 juta — formulirnya besar
     * (9 kotak tanda tangan) sementara rantai persetujuannya hanya meminta 7 orang.
     */
    public const AMBANG_BESAR = BsCashAdvance::APPROVAL_THRESHOLD;

    public function render(BsCashAdvance $advance): string
    {
        return (float) $advance->amount >= self::AMBANG_BESAR
            ? $this->besar($advance)
            : $this->kecil($advance);
    }

    // ── Bahan bersama ────────────────────────────────────────────────────────────

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** "Rp. 16.500.000,00" — gaya KAS BON. */
    private function rpKoma(float $n): string
    {
        return 'Rp. '.number_format($n, 2, ',', '.');
    }

    /**
     * Angka TOTAL — kini format Indonesia "Rp. 16.500.000,00", sama dengan baris rinciannya.
     * (Dulu "Rp. 16,500,000.00" mengikuti formulir eProperty, sehingga satu kertas memuat dua
     * bentuk angka sekaligus.)
     */
    private function rpTitik(float $n): string
    {
        return $this->rpKoma($n);
    }

    /** Rekening penerima diambil dari data karyawan yang namanya sama dengan pemegang bon. */
    private function penerima(BsCashAdvance $advance): array
    {
        $nama = trim((string) $advance->holder);
        $emp  = $nama !== '' ? BsEmployee::whereRaw('LOWER(name) = ?', [mb_strtolower($nama)])->first() : null;

        return [
            'nama' => $nama !== '' ? $nama : '—',
            'bank' => $emp?->bank_name ?: '—',
            'rek'  => $emp?->bank_account ?: '—',
        ];
    }

    private function dept(BsCashAdvance $advance): string
    {
        // costCenter, BUKAN department: master Departemen sudah dicabut dan relasi `department`
        // tidak pernah ada di BsCashAdvance. Eloquent memulangkan null untuk relasi yang tidak
        // ada tanpa melempar apa pun, sehingga baris Departemen pada formulir cetak SELALU
        // berbunyi "—" — diam, dan tidak terbaca sebagai kesalahan.
        return (string) ($advance->costCenter?->name ?: '—');
    }

    private function pengaju(BsCashAdvance $advance): string
    {
        return (string) (\App\Models\User::find($advance->created_by)?->name ?: $advance->holder ?: '—');
    }

    private function gaya(): string
    {
        return '*{box-sizing:border-box}'
            .'body{font-family:"Segoe UI",Arial,Helvetica,sans-serif;font-size:12px;color:#000;margin:0}'
            .'table{border-collapse:collapse;width:100%}'
            .'td,th{border:1px solid #000;padding:4px 6px;vertical-align:top}'
            .'th{font-weight:600;text-align:center}'
            .'.r{text-align:right}.c{text-align:center}.nb,.nb td{border:0}'
            .'.judul{text-align:center;font-weight:700;font-size:14px;margin:0 0 10px}'
            .'.co{font-weight:700;font-size:16px}.alamat{font-size:11px}'
            .'.kotak{display:inline-block;width:11px;height:11px;border:1px solid #000;margin-right:5px;vertical-align:-1px}'
            .'.ttd{margin-top:26px;width:100%}.ttd td{border:0;text-align:center;font-size:11px;vertical-align:top}'
            .'.ttd .ruang{height:62px}'
            .'.kop td{border:0;vertical-align:middle;padding:0 0 0 8px}'
            .'.kop td:first-child{padding-left:0}'
            .'.logo{width:56px;height:56px;object-fit:contain;display:block}'
            .'hr.garis{border:0;border-top:1.5px solid #000;margin:6px 0 10px}'
            .'.cap{font-size:10px;text-align:right;color:#000}'
            .'.hal{page-break-after:always}'
            .'@page{size:A4 portrait;margin:12mm}'
            .'@media print{.noprint{display:none}}';
    }

    private function bungkus(string $judulTab, string $isi): string
    {
        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$this->e($judulTab).'</title>'
            .'<style>'.$this->gaya().'</style></head><body>'.$isi
            .'<button class="noprint" onclick="window.print()" style="margin-top:12px;padding:6px 14px">Cetak</button>'
            .'<script>window.onload=function(){setTimeout(function(){window.print()},300)}</script>'
            .'</body></html>';
    }

    /**
     * Kop berlogo — dipakai KETIGA halaman (KAS BON, BPP, Memo Internal).
     *
     * Dijadikan satu tempat supaya logo, nama, dan alamat tidak bisa berbeda antar halaman
     * dalam satu berkas cetak yang sama.
     *
     * Berkasnya mup-logo.jpeg, BUKAN mup-letterhead.jpeg. Yang kedua adalah satu lembar kop
     * A4 utuh 2482×3511; memakainya sebagai logo membuat kopnya tampil sebagai serpihan
     * oranye setinggi beberapa piksel — persis kekeliruan yang sudah pernah terjadi pada
     * cetak Purchase Order.
     *
     * @param  string  $kanan  isi sudut kanan kop (judul besar / keterangan cetak)
     */
    private function kop(string $kanan = ''): string
    {
        $co     = (string) config('business_support.company.name', 'PT Mitra Usaha Propertindo');
        $alamat = (string) config('business_support.company.address', 'Sidoarjo, Jawa Timur');

        return '<table class="nb kop"><tr>'
            .'<td style="width:64px"><img class="logo" src="/images/mup-logo.jpeg" alt="'.$this->e($co).'"></td>'
            .'<td><div class="co">'.$this->e($co).'</div><div class="alamat">'.$this->e($alamat).'</div></td>'
            .'<td style="text-align:right;vertical-align:top">'.$kanan.'</td>'
            .'</tr></table><hr class="garis">';
    }

    // ── Formulir KECIL: KAS BON (di bawah Rp 1 juta) ─────────────────────────────

    private function kecil(BsCashAdvance $advance): string
    {
        $p       = $this->penerima($advance);
        $nominal = (float) $advance->amount;

        // Tanda tangan persis formulir KAS BON eProperty: lima judul, tujuh kotak.
        // Diverifikasi = Manager Accounting + Manager Finance, Disetujui = General Manager +
        // Direktur; Dibayar & Diterima dibiarkan tanpa jabatan — diisi kasir dan penerimanya.
        // (Dulu satu judul satu jabatan, sehingga jabatannya bergeser: Manager Finance di
        // "Disetujui", General Manager di "Dibayar", Direktur di "Diterima".)
        $judulTtd = [['Yang Mengajukan', 1], ['Diverifikasi', 2], ['Disetujui', 2], ['Dibayar', 1], ['Diterima', 1]];
        $jabatanTtd = ['SPV/Manager Dept.', 'Manager Accounting', 'Manager Finance', 'General Manager', 'Direktur', '', ''];
        $selTtd = '';
        foreach ($judulTtd as [$judul, $span]) {
            $selTtd .= '<td colspan="'.$span.'">'.$this->e($judul).'</td>';
        }
        $selTtd .= '</tr><tr><td colspan="'.count($jabatanTtd).'" class="ruang"></td></tr><tr>';
        foreach ($jabatanTtd as $jabatan) {
            $selTtd .= '<td>'.$this->e($jabatan).'</td>';
        }

        $isi = $this->kop('<div style="font-size:20px;font-weight:700">KAS BON</div>')

            .'<table class="nb" style="margin:14px 0 8px"><tr>'
            .'<td style="width:55%"><table class="nb">'
            .'<tr><td style="width:70px">Tanggal</td><td>: '.$this->e(optional($advance->advance_date)->format('d-m-Y')).'</td></tr>'
            .'<tr><td>Nomor</td><td>: '.$this->e($advance->advance_number).'</td></tr>'
            .'<tr><td>Bagian</td><td>: '.$this->e($this->dept($advance)).'</td></tr>'
            .'</table></td>'
            .'<td><div><span class="kotak"></span>Project</div>'
            .'<div style="margin-top:4px"><span class="kotak"></span>Non Project</div></td>'
            .'</tr></table>'

            .'<table><thead><tr><th style="width:40px">No.</th><th>Uraian</th><th style="width:170px">Jumlah</th></tr></thead>'
            .'<tbody><tr><td class="c">1.</td><td style="height:96px">'.$this->e($advance->purpose).'</td>'
            .'<td class="r">'.$this->rpKoma($nominal).'</td></tr></tbody></table>'

            .'<table class="nb" style="margin-top:10px"><tr>'
            .'<td style="width:55%"><div style="font-weight:600;margin-bottom:3px">Dibayarkan kepada</div>'
            .'<table class="nb">'
            .'<tr><td style="width:90px">Nama</td><td>: '.$this->e($p['nama']).'</td></tr>'
            .'<tr><td>Bank</td><td>: '.$this->e($p['bank']).'</td></tr>'
            .'<tr><td>No. Rekening</td><td>: '.$this->e($p['rek']).'</td></tr>'
            .'</table></td>'
            .'<td style="vertical-align:bottom"><table><tr><th style="width:110px">Total</th>'
            .'<td class="r">'.$this->rpKoma($nominal).'</td></tr></table></td>'
            .'</tr></table>'

            .'<div style="margin-top:8px">Catatan :</div>'
            .'<div style="border:1px solid #000;height:48px;margin-top:3px"></div>'

            .'<table class="ttd"><tr>'.$selTtd.'</tr></table>';

        return $this->bungkus($advance->advance_number.' — KAS BON', $isi);
    }

    // ── Formulir BESAR: BPP + Memo Internal (mulai Rp 1 juta) ────────────────────

    private function besar(BsCashAdvance $advance): string
    {
        return $this->bungkus(
            $advance->advance_number.' — Bukti Permohonan Pembayaran',
            $this->halamanBpp($advance).$this->halamanMemo($advance),
        );
    }

    private function halamanBpp(BsCashAdvance $advance): string
    {
        $p       = $this->penerima($advance);
        $nominal = (float) $advance->amount;
        $akun    = $advance->cashAccount?->code ?: '—';
        $terbilang = strtoupper(trim(preg_replace('/\s+/', ' ', terbilang((int) round($nominal))))).' RUPIAH';

        // Lima judul, tujuh kotak tanda tangan: Verifikasi & Persetujuan masing-masing dua orang,
        // sama dengan rantai persetujuan bon di aplikasi ini.
        $ttdAtas = [
            ['Diajukan Oleh,', 1], ['Di Verifikasi Oleh,', 2], ['Di Setujui Oleh,', 2],
            ['Di Bayarkan Oleh,', 1], ['Di Terima Oleh,', 1],
        ];
        $barisAtas = '';
        foreach ($ttdAtas as [$judul, $span]) {
            $barisAtas .= '<td colspan="'.$span.'">'.$this->e($judul).'</td>';
        }
        $peran = ['SPV/Manager Dept.', 'Manager Accounting', 'Manager Finance', 'General Manager',
            'Direktur', 'General Cashier', 'Penerima'];
        $barisBawah = '';
        foreach ($peran as $r) {
            $barisBawah .= '<td>'.$this->e($r).'</td>';
        }

        $pilihan = fn (string $t) => '<div style="margin-bottom:3px"><span class="kotak"></span>'.$this->e($t).'</div>';

        return '<div class="hal">'
            .$this->kop('<div class="cap">Created By Java Core System ('.$this->e(now()->format('d-m-Y H.i.s')).')</div>')
            .'<div class="judul">BUKTI PERMOHONAN PEMBAYARAN</div>'

            .'<table class="nb" style="margin-bottom:8px"><tr>'
            .'<td style="width:55%">Diajukan Tanggal : '.$this->e(optional($advance->advance_date)->format('d-m-Y')).'</td>'
            .'<td>Departemen : '.$this->e($this->dept($advance)).'</td>'
            .'</tr></table>'

            .'<div style="margin-bottom:6px">Dibayarkan Kepada : <b>'.$this->e($p['nama']).'</b></div>'

            .'<table style="margin-bottom:8px"><tr>'
            .'<td style="width:25%"><b>Dibayar Via :</b>'.$pilihan('KAS').$pilihan('Bank ('.$p['bank'].')')
            .'<div style="margin-top:4px">No. Rekening :</div><div>'.$this->e($p['rek']).'</div></td>'
            .'<td style="width:25%"><b>Type Pengeluaran :</b>'.$pilihan('Pekerjaan').$pilihan('Material').'</td>'
            .'<td style="width:25%"><b>Cara Pembayaran Bank :</b>'.$pilihan('Transfer (BG)').$pilihan('Non Transfer (Check)').'</td>'
            .'<td><b>Lampiran :</b>'.$pilihan('Penunjukan Langsung').$pilihan('Tender').$pilihan('Non Pembelian').'</td>'
            .'</tr></table>'

            .'<table><thead><tr><th style="width:140px">No. Account</th><th>Uraian Pembayaran</th>'
            .'<th style="width:170px">Jumlah</th></tr></thead>'
            .'<tbody><tr><td>'.$this->e($akun).'</td><td style="height:90px">'.$this->e($advance->purpose).'</td>'
            .'<td class="r">'.$this->rpKoma($nominal).'</td></tr>'
            .'<tr><td colspan="2" class="r"><b>Total</b></td><td class="r"><b>'.$this->rpTitik($nominal).'</b></td></tr>'
            .'</tbody></table>'

            .'<table class="nb" style="margin-top:6px">'
            .'<tr><td style="width:120px">No. Cek / BG</td><td>: -</td></tr>'
            .'<tr><td>No. Voucher</td><td>: '.$this->e($advance->advance_number).'</td></tr>'
            .'<tr><td>Terbilang</td><td>: '.$this->e($terbilang).'</td></tr></table>'

            .'<table class="ttd"><tr>'.$barisAtas.'</tr>'
            .'<tr><td colspan="7" class="ruang"></td></tr>'
            .'<tr>'.$barisBawah.'</tr></table>'
            .'</div>';
    }

    private function halamanMemo(BsCashAdvance $advance): string
    {
        $p         = $this->penerima($advance);
        $nominal   = (float) $advance->amount;
        $terbilang = strtoupper(trim(preg_replace('/\s+/', ' ', terbilang((int) round($nominal))))).' RUPIAH';
        // "Dari" & "Hormat Kami" = MANAGER cost center bon ini (kolom Manager di master Cost Center —
        // SPV ke atas per departemen); jatuh ke pembuat pengajuan bila kolomnya masih kosong.
        $pengaju   = \App\Domain\BusinessSupport\Services\CostCenterPengguna::managerDari($advance->costCenter)
            ?? $this->pengaju($advance);
        $keperluan = (string) $advance->purpose;

        return '<div>'
            .$this->kop('<div class="cap">Created By Java Core System ('.$this->e(now()->format('d-m-Y H.i.s')).')</div>')
            .'<div class="judul" style="margin-bottom:2px">MEMO INTERNAL</div>'
            .'<div style="text-align:center;margin-bottom:12px">No : '.$this->e($advance->advance_number).'</div>'

            .'<table class="nb" style="margin-bottom:10px">'
            .'<tr><td style="width:110px">Kepada Yth</td><td>: '.$this->e($this->dept($advance)).'</td></tr>'
            .'<tr><td>Tembusan</td><td>: -</td></tr>'
            .'<tr><td>Dari</td><td>: '.$this->e($pengaju).'</td></tr>'
            .'<tr><td>Hari / Tanggal</td><td>: '.$this->e(optional($advance->advance_date)->format('d-m-Y')).'</td></tr>'
            .'<tr><td>Perihal</td><td>: '.$this->e($keperluan).'</td></tr></table>'

            .'<div style="margin-bottom:6px">Dengan hormat,</div>'
            .'<div style="text-align:justify;margin-bottom:6px">Sehubungan dengan keperluan '.$this->e($keperluan)
            .'. Maka kami mengajukan permohonan pembayaran sebesar '.$this->e($this->rpTitik($nominal))
            .' ( '.$this->e($terbilang).' ).</div>'
            .'<div style="margin-bottom:6px">Dengan rincian sebagai berikut :</div>'

            .'<table><thead><tr><th style="width:34px">No.</th><th>Nama Barang</th><th style="width:52px">SAT.</th>'
            .'<th style="width:52px">QTY</th><th style="width:120px">HARGA SAT. (Rp)</th>'
            .'<th style="width:120px">JML HARGA</th></tr></thead>'
            .'<tbody><tr><td class="c">1</td><td style="height:70px">'.$this->e($keperluan).'</td>'
            .'<td class="c">-</td><td class="c">-</td>'
            .'<td class="r">'.$this->rpKoma($nominal).'</td><td class="r">'.$this->rpKoma($nominal).'</td></tr>'
            .'<tr><td colspan="5" class="r"><b>TOTAL</b></td><td class="r"><b>'.$this->rpTitik($nominal).'</b></td></tr>'
            .'</tbody></table>'

            .'<div style="margin-top:10px">Pembayaran dilakukan melalui transfer Rekening :</div>'
            .'<table class="nb" style="margin-bottom:8px">'
            .'<tr><td style="width:110px">No. Rekening</td><td>: '.$this->e($p['rek']).'</td></tr>'
            .'<tr><td>Bank</td><td>: '.$this->e($p['bank']).'</td></tr>'
            .'<tr><td>Atas Nama</td><td>: '.$this->e($p['nama']).'</td></tr></table>'

            .'<div style="margin-bottom:6px">Demikian ini kami sampaikan, atas perhatiannya kami ucapkan terima kasih.</div>'

            .'<table class="ttd"><tr><td>Hormat Kami,</td><td colspan="2">Menyetujui,</td></tr>'
            .'<tr><td class="ruang"></td><td class="ruang"></td><td class="ruang"></td></tr>'
            // Penyetuju pertama = NAMA, bukan jabatan — sama dengan Memo Internal Invoice Hutang
            // dan formulir eProperty. Kunci config ini tidak pernah diisi, sehingga dulu yang
            // tercetak adalah cadangannya: tulisan "Manager Finance".
            .'<tr><td>'.$this->e($pengaju).'</td><td>'.$this->e((string) config('business_support.company.signer_manager', 'Rizki Setyawardana'))
            .'</td><td>'.$this->e((string) config('business_support.company.signer_name', 'Direktur')).'</td></tr></table>'

            .'<table class="ttd" style="margin-top:10px"><tr><td colspan="2">Mengetahui,</td></tr>'
            .'<tr><td class="ruang"></td><td class="ruang"></td></tr>'
            .'<tr><td>'.$this->e((string) config('business_support.company.signer_komisaris1', 'M. Winarto'))
            .'</td><td>'.$this->e((string) config('business_support.company.signer_komisaris2', 'Boy Arifin')).'</td></tr></table>'
            .'</div>';
    }
}
