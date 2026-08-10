<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BankLoan\Models\BankPartner;
use Illuminate\Database\Seeder;

/**
 * Format SI bawaan tenant untuk bank yang punya surat sendiri:
 *  - BNI → "SI BNI.docx"     : 6 surat (Lunas Uang Muka, Pemberitahuan Pencairan Dana KPR,
 *                              Claim Fee KPR, Keterangan Proses Pembangunan, Keterangan
 *                              Legalitas Sertifikat & IMB, Surat Keterangan pajak PPJB)
 *  - MDR → "SI MANDIRI.docx" : 6 surat (Lunas Uang Muka, Claim Fee KPR, Keterangan Proses
 *                              Pembangunan, Keterangan Legalitas Sertifikat & IMB, Laporan
 *                              Penilaian Progress, Pemberitahuan Pencairan Dana)
 *
 * Isi surat disalin dari berkas .docx yang diberikan, dengan bagian yang berubah per-nasabah
 * diganti placeholder ({{nama}}, {{unit}}, …) sehingga Cetak SI mengisinya otomatis.
 *
 * MENIMPA si_template bank ybs setiap dijalankan — memang itu tujuannya (menanam format resmi).
 * Operator tetap bisa menyunting/mengosongkannya lewat Set Up Kategori Bank → tab Format SI.
 */
class BankSiTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['BNI' => $this->bni(), 'MDR' => $this->mandiri()] as $code => $html) {
            $bank = BankPartner::withoutGlobalScopes()->whereRaw('UPPER(code) = ?', [$code])->first();
            if (! $bank) {
                $this->command?->warn("BankSiTemplateSeeder: bank {$code} belum ada — dilewati.");

                continue;
            }
            $bank->update(['si_template' => trim($html)]);
            $this->command?->info("Format SI {$code} ditanam pada bank {$bank->name}.");
        }
    }

    /** Kop surat + blok tanda tangan dipakai berulang oleh tiap surat. */
    private function kop(): string
    {
        return <<<'HTML'
  <div class="kop">
    <div class="co">{{perusahaan}}</div>
    <div class="ad">{{alamat_perusahaan}}</div>
  </div>
HTML;
    }

    private function ttd(bool $withCo = true): string
    {
        $co = $withCo ? '<br>{{perusahaan}}' : '';

        return <<<HTML
  <div class="sign">Hormat Kami,{$co}
    <div class="nm">{{penandatangan}}</div>
    {{jabatan}}
  </div>
HTML;
    }

    /** SI BNI — enam surat sesuai "SI BNI.docx", masing-masing satu halaman. */
    private function bni(): string
    {
        $kepada = <<<'HTML'
  <div class="to">Kepada,<br>
    <b>PT. Bank Negara Indonesia (BNI)</b><br>
    <b>Cabang Graha Pangeran</b><br>
    <b>Jl. Ahmad Yani No.286, Surabaya</b>
  </div>
HTML;
        $identitas = <<<'HTML'
  <div class="kv"><span class="k">NAMA</span><span class="c">:</span><span class="v"><b>{{nama}}</b></span></div>
  <div class="kv"><span class="k">ALAMAT RUMAH</span><span class="c">:</span><span class="v"><b>{{unit}}</b></span></div>
  <div class="kv"><span class="k">TIPE</span><span class="c">:</span><span class="v"><b>{{tipe}}</b></span></div>
  <div class="kv"><span class="k">DESA</span><span class="c">:</span><span class="v"><b>TERUNGKULON</b></span></div>
  <div class="kv"><span class="k">KECAMATAN</span><span class="c">:</span><span class="v"><b>KRIAN</b></span></div>
  <div class="kv"><span class="k">KABUPATEN</span><span class="c">:</span><span class="v"><b>SIDOARJO</b></span></div>
HTML;
        $pembuka = <<<'HTML'
  <p>Yang bertanda tangan dibawah ini :</p>
  <div class="kv"><span class="k">Nama</span><span class="c">:</span><span class="v">{{penandatangan}}</span></div>
  <div class="kv"><span class="k">Jabatan</span><span class="c">:</span><span class="v">{{jabatan}} {{perusahaan}}</span></div>
  <p>Dalam hal ini bertindak berdasarkan jabatannya selaku pihak berwenang dari {{perusahaan}}, berkedudukan di Sidoarjo, dengan ini menerangkan sebagai berikut:</p>
HTML;

        $kop = $this->kop();
        $ttd = $this->ttd();
        $ttdNoCo = $this->ttd(false);

        return $kop."\n".<<<HTML

<!-- 1. Pemberitahuan Lunas Uang Muka -->
<div class="letter">
  <div class="right">Sidoarjo, {{tanggal}}</div>
  <div class="hdr">
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/SI/OUT-BNI.GP/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Pemberitahuan Lunas Uang Muka</span></div>
    <div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">-</span></div>
  </div>
{$kepada}
  <p>Dengan hormat,</p>
  <p>Sehubungan dengan permohonan kredit perumahan {{perumahan}}, kami selaku developer menerangkan bahwa debitur atas nama :</p>
  <div class="kv"><span class="k">NAMA</span><span class="c">:</span><span class="v"><b>{{nama}}</b></span></div>
  <div class="kv"><span class="k">UNIT</span><span class="c">:</span><span class="v"><b>{{unit}}</b></span></div>
  <div class="kv"><span class="k">TIPE</span><span class="c">:</span><span class="v"><b>{{tipe}}</b></span></div>
  <p>Telah melakukan Pelunasan Uang Muka sebesar <b>{{uang_muka}} ({{uang_muka_terbilang}}).</b></p>
  <p>Demikian pemberitahuan ini di buat dengan sebenar benarnya, atas perhatian dan kerjasamanya kami ucapkan terimakasih.</p>
{$ttd}
  <div style="margin-top:56px"><b>{{nama}}</b></div>
</div>

<!-- 2. Pemberitahuan Pencairan Dana KPR — termin 30/20/40/10, transfer 90%/10% -->
<div class="letter">
{$kop}
  <div class="hdr">
    <div class="kv"><span class="k">Tanggal</span><span class="c">:</span><span class="v">{{tanggal}}</span></div>
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/SI/OUT-BNI.GP/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Pemberitahuan Pencairan Dana KPR</span></div>
  </div>
{$kepada}
  <p>Sehubungan dengan telah dilaksanakannya akad kredit pada tanggal <b>({{tgl_akad}})</b> atas nama:</p>
  <div class="kv"><span class="k">Nama</span><span class="c">:</span><span class="v"><b>{{nama}}</b></span></div>
  <div class="kv"><span class="k">Alamat Rumah</span><span class="c">:</span><span class="v"><b>{{unit}} - {{tipe}}</b></span></div>
  <div class="kv"><span class="k">KPR</span><span class="c">:</span><span class="v"><b>{{plafon}}</b></span></div>
  <p>Yang berlokasi di {{perumahan}} Desa Terung kulon Kecamatan Krian Kabupaten Sidoarjo Provinsi Jawa Timur.
     Adapun sertifikat atas tanah dan bangunan tersebut telah diserahkan oleh {{perusahaan}} dengan Notaris {{notaris}}.
     Maka atas realisasi akad kredit diatas, bersama ini kami mohon kepada Bank BNI Cabang Graha Pangeran Surabaya untuk dapat
     mencairkan dana tersebut ke Rekening kami, adapun rinciannya sebagai berikut :</p>
  <table class="tbl">
    <thead><tr><th>No</th><th>Pencairan</th><th>%</th><th>Nominal pencairan</th><th>KPR</th><th>Keterangan</th></tr></thead>
    <tbody>
      <tr><td>1</td><td>Tanah Matang</td><td>30%</td><td class="r">{{plafon_30}}</td><td class="r">{{plafon}}</td><td>Giro Umum</td></tr>
      <tr><td>2</td><td>Pondasi</td><td>20%</td><td class="r">{{plafon_20}}</td><td></td><td>Giro Umum</td></tr>
      <tr><td>3</td><td>Topping Off</td><td>40%</td><td class="r">{{plafon_40}}</td><td></td><td>Giro Umum</td></tr>
      <tr><td>4</td><td>BAST &amp; Sertifikat IMB</td><td>10%</td><td class="r">{{plafon_10}}</td><td></td><td>Giro Escrow</td></tr>
      <tr><td colspan="3"><b>Total</b></td><td class="r"><b>{{plafon}}</b></td><td></td><td></td></tr>
    </tbody>
  </table>
  <p>Atas pencairan dana tersebut mohon di transfer ke rekening Bank BNI kami:</p>
  <div class="kv"><span class="k">Atas nama</span><span class="c">:</span><span class="v"><b>{{perusahaan}}</b></span></div>
  <div class="kv"><span class="k">No. Rekening</span><span class="c">:</span><span class="v"><b>141 337 5985 (Giro Operasional)</b></span></div>
  <div class="kv"><span class="k">Sebesar 90%</span><span class="c">:</span><span class="v"><b>{{plafon_90}}</b></span></div>
  <div class="kv"><span class="k">No. Rekening</span><span class="c">:</span><span class="v"><b>188 688 8512 (Giro Escrow)</b></span></div>
  <div class="kv"><span class="k">Sebesar 10%</span><span class="c">:</span><span class="v"><b>{{plafon_10}}</b></span></div>
  <p>Demikian permohon ini, atas bantuan dan kerjasamanya kami ucapkan terimaksih.</p>
{$ttd}
</div>

<!-- 3. Claim Fee KPR -->
<div class="letter">
{$kop}
  <div class="hdr">
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/OUT-BNI.GP/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Perihal</span><span class="c">:</span><span class="v">Claim Fee KPR</span></div>
    <div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">-</span></div>
  </div>
{$kepada}
  <p>Dengan hormat,</p>
  <p>Sehubungan dengan telah dilaksanakan proses kredit KPR konsumen atas nama <b>{{nama}}</b> Unit <b>{{unit}} - {{tipe}}</b> pada tanggal <b>({{tgl_akad}})</b> sebesar <b>{{plafon}}</b></p>
  <p>Maka dengan ini mohon agar Insentif/Fee KPR dapat dicairkan ke:</p>
  <div class="kv"><span class="k">Nama</span><span class="c">:</span><span class="v"><b>RACHMAT SUHENDRA</b></span></div>
  <div class="kv"><span class="k">No. Rek</span><span class="c">:</span><span class="v"><b>8820233332 (BNI)</b></span></div>
  <p>Demikian permohonan ini kami sampaikan, atas bantuan dan kerjasamanya kami ucapkan terimakasih.</p>
{$ttd}
</div>

<!-- 4. Keterangan Proses Pembangunan Rumah -->
<div class="letter">
{$kop}
  <div class="hdr">
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/MUP/OUT-BNI.SDA/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Keterangan Proses Pembangunan Rumah</span></div>
    <div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">-</span></div>
  </div>
{$pembuka}
{$identitas}
  <p>Bahwa untuk pembangunan rumah tersebut masih dalam proses dan akan diselesaikan dalam jangka waktu 18 (delapan belas) bulan sejak surat keterangan ini dibuat.</p>
  <p>Demikian Surat Keterangan ini dibuat untuk dipergunakan sebagaimana perlunya.</p>
  <div class="right">Sidoarjo, {{tanggal}}</div>
{$ttdNoCo}
  <div style="margin-top:56px"><b>{{nama}}</b><br>{{unit}}</div>
</div>

<!-- 5. Keterangan Proses Penyelesaian Legalitas Sertifikat & IMB -->
<div class="letter">
{$kop}
  <div class="hdr">
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/MUP/OUT-BNI.GP/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Keterangan Proses Penyelesaian Legalitas Sertifikat &amp; IMB</span></div>
    <div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">-</span></div>
  </div>
{$pembuka}
{$identitas}
  <p>Bahwa untuk sertifikat pecahan rumah tersebut masih dalam proses, yang akan dilakukan pemecahan sertifikat induk pada notaris rekanan BNI yang tidak terdapat blacklist dari BNI dan IMB akan kami serahkan kepada pihak Bank BNI dalam jangka waktu 18 (delapan belas) bulan sejak surat keterangan ini dibuat.</p>
  <p>Demikian Surat Keterangan ini dibuat untuk dipergunakan sebagaimana perlunya.</p>
  <div class="right">Sidoarjo, {{tanggal}}</div>
{$ttdNoCo}
</div>

<!-- 6. Surat Keterangan — tanggung jawab pajak PPJB -->
<div class="letter">
{$kop}
  <div class="title">SURAT KETERANGAN</div>
  <p>Yang bertandatangan di bawah ini :</p>
  <div class="kv"><span class="k">Nama</span><span class="c">:</span><span class="v"><b>RACHMAT SUHENDRA</b></span></div>
  <div class="kv"><span class="k">No. KTP</span><span class="c">:</span><span class="v"><b>3515162210870001</b></span></div>
  <div class="kv"><span class="k">Alamat</span><span class="c">:</span><span class="v">Tebel Barat RT005/001 Desa Tebel Gedangan Sidoarjo</span></div>
  <div class="kv"><span class="k">Jabatan</span><span class="c">:</span><span class="v">{{jabatan}} {{perusahaan}}</span></div>
  <p>Dengan ini menyatakan biaya biaya pajak yang timbul pada saat penandatangan PPJB atas rumah yang berada di perumahan
     <b>{{unit}} {{tipe}}</b>, yang berlokasi Kelurahan Terungkulon, Kecamatan Krian Sidoarjo, menjadi tanggung jawab {{perusahaan}}.
     Apabila ada pajak &ndash; pajak yang timbul lebih besar dari biaya yang telah disepakati di awal baik pajak penjual (PPH)
     maupun pajak pembeli (BPHTB), maka selisih atau kekurangan biaya tersebut akan menjadi tanggung jawab {{perusahaan}}</p>
  <p>Demikian surat pernyataan ini kami buat, Atas perhartian dan kerjasama yang baik disampaikan terima kasih.</p>
  <div class="right">Sidoarjo, {{tanggal}}</div>
{$ttdNoCo}
</div>
HTML;
    }

    /** SI MANDIRI — enam surat, masing-masing satu halaman. */
    private function mandiri(): string
    {
        $kepada = <<<'HTML'
  <div class="to">Kepada,<br>
    <b>PT. Bank Mandiri</b><br>
    <b>KC. Gentengkali Surabaya</b><br>
    <b>Jl. Gentengkali No. 93 &ndash; 95, Surabaya</b>
  </div>
HTML;
        $identitas = <<<'HTML'
  <div class="kv"><span class="k">NAMA</span><span class="c">:</span><span class="v"><b>{{nama}}</b></span></div>
  <div class="kv"><span class="k">ALAMAT RUMAH</span><span class="c">:</span><span class="v"><b>{{unit}}</b></span></div>
  <div class="kv"><span class="k">TIPE</span><span class="c">:</span><span class="v"><b>{{tipe}}</b></span></div>
  <div class="kv"><span class="k">DESA</span><span class="c">:</span><span class="v"><b>TERUNGKULON</b></span></div>
  <div class="kv"><span class="k">KECAMATAN</span><span class="c">:</span><span class="v"><b>KRIAN</b></span></div>
  <div class="kv"><span class="k">KABUPATEN</span><span class="c">:</span><span class="v"><b>SIDOARJO</b></span></div>
HTML;
        $pembuka = <<<'HTML'
  <p>Yang bertanda tangan dibawah ini :</p>
  <div class="kv"><span class="k">Nama</span><span class="c">:</span><span class="v">{{penandatangan}}</span></div>
  <div class="kv"><span class="k">Jabatan</span><span class="c">:</span><span class="v">{{jabatan}} {{perusahaan}}</span></div>
  <p>Dalam hal ini bertindak berdasarkan jabatannya selaku pihak berwenang dari {{perusahaan}}, berkedudukan di Sidoarjo, dengan ini menerangkan sebagai berikut:</p>
HTML;

        $kop = $this->kop();
        $ttd = $this->ttd();
        $ttdNoCo = $this->ttd(false);

        return $kop."\n".<<<HTML

<!-- 1. Pemberitahuan Lunas Uang Muka -->
<div class="letter">
  <div class="right">Sidoarjo, {{tanggal}}</div>
  <div class="hdr">
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/SI/OUT-MNDR/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Pemberitahuan Lunas Uang Muka</span></div>
    <div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">-</span></div>
  </div>
{$kepada}
  <p>Dengan hormat,</p>
  <p>Sehubungan dengan permohonan kredit perumahan {{perumahan}}, kami selaku developer menerangkan bahwa debitur atas nama :</p>
  <div class="kv"><span class="k">NAMA</span><span class="c">:</span><span class="v"><b>{{nama}}</b></span></div>
  <div class="kv"><span class="k">UNIT</span><span class="c">:</span><span class="v"><b>{{unit}}</b></span></div>
  <div class="kv"><span class="k">TIPE</span><span class="c">:</span><span class="v"><b>{{tipe}}</b></span></div>
  <p>Telah melakukan Pelunasan Uang Muka sebesar <b>{{uang_muka}} ({{uang_muka_terbilang}}).</b></p>
  <p>Demikian pemberitahuan ini di buat dengan sebenar benarnya, atas perhatian dan kerjasamanya kami ucapkan terimakasih.</p>
{$ttd}
  <div style="margin-top:56px"><b>{{nama}}</b></div>
</div>

<!-- 2. Claim Fee KPR -->
<div class="letter">
{$kop}
  <div class="right">Sidoarjo, {{tanggal}}</div>
  <div class="hdr">
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/SI/OUT-MNDR/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Perihal</span><span class="c">:</span><span class="v">Claim Fee KPR</span></div>
    <div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">-</span></div>
  </div>
{$kepada}
  <p>Dengan hormat,</p>
  <p>Sehubungan dengan telah dilaksanakan proses kredit KPR konsumen atas nama <b>{{nama}}</b> Unit <b>{{unit}} - {{tipe}}</b> pada tanggal <b>({{tgl_akad}})</b> sebesar <b>{{plafon}}</b></p>
  <p>Maka dengan ini mohon agar Insentif/Fee KPR dapat dicairkan ke:</p>
  <div class="kv"><span class="k">Nama</span><span class="c">:</span><span class="v"><b>RACHMAT SUHENDRA</b></span></div>
  <div class="kv"><span class="k">No. Rek</span><span class="c">:</span><span class="v"><b>14-000-1131815-2 (MANDIRI)</b></span></div>
  <p>Demikian permohonan ini kami sampaikan, atas bantuan dan kerjasamanya kami ucapkan terimakasih.</p>
{$ttd}
</div>

<!-- 3. Keterangan Proses Pembangunan Rumah -->
<div class="letter">
{$kop}
  <div class="hdr">
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/SI/OUT-MNDR/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Keterangan Proses Pembangunan Rumah</span></div>
    <div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">1</span></div>
  </div>
{$pembuka}
{$identitas}
  <p>Bahwa untuk pembangunan rumah tersebut masih dalam proses dan akan diselesaikan dalam jangka waktu 12 (dua belas) bulan sejak surat keterangan ini dibuat.</p>
  <p>Demikian Surat Keterangan ini dibuat untuk dipergunakan sebagaimana perlunya.</p>
  <div class="right">Sidoarjo, {{tanggal}}</div>
{$ttdNoCo}
  <div style="margin-top:56px"><b>{{nama}}</b></div>
</div>

<!-- 4. Keterangan Proses Penyelesaian Legalitas Sertifikat dan IMB -->
<div class="letter">
{$kop}
  <div class="hdr">
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/SI/OUT-MNDR/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Keterangan Proses Penyelesaian Legalitas Sertifikat dan IMB</span></div>
    <div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">-</span></div>
  </div>
{$pembuka}
{$identitas}
  <p>Bahwa untuk sertifikat pecahan dan IMB rumah tersebut masih dalam proses dan akan kami serahkan kepada pihak Bank Mandiri dalam jangka waktu 12 (dua belas) bulan sejak surat keterangan ini dibuat.</p>
  <p>Demikian Surat Keterangan ini dibuat untuk dipergunakan sebagaimana perlunya.</p>
  <div class="right">Sidoarjo, {{tanggal}}</div>
{$ttdNoCo}
</div>

<!-- 5. Laporan Penilaian Progress Pembangunan -->
<div class="letter">
{$kop}
  <div class="hdr">
    <div class="kv"><span class="k">Tanggal</span><span class="c">:</span><span class="v">{{tanggal}}</span></div>
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/SI/OUT-MNDR/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Laporan Penilaian Progress Pembangunan</span></div>
  </div>
{$kepada}
  <div class="kv"><span class="k">Nama Penilai</span><span class="c">:</span><span class="v"><b>{{perusahaan}}</b></span></div>
  <div class="kv"><span class="k">Nama Debitur</span><span class="c">:</span><span class="v"><b>{{nama}}</b></span></div>
  <div class="kv"><span class="k">Alamat Agunan</span><span class="c">:</span><span class="v"><b>{{unit}} - {{tipe}}</b></span></div>
  <div class="kv"><span class="k">Tanggal Penilaian</span><span class="c">:</span><span class="v"><b>{{tanggal}}</b></span></div>
  <p>Adapun laporan penilaian progress pembangunan sebagai berikut :</p>
  <table class="pajak">
    <tr><th>No</th><th>Pencairan</th><th>Selesai</th><th>Belum Selesai</th></tr>
    <tr><td>1</td><td>Tanah Matang</td><td></td><td></td></tr>
    <tr><td>2</td><td>Pondasi</td><td></td><td></td></tr>
    <tr><td>3</td><td>Topping Off</td><td></td><td></td></tr>
    <tr><td>4</td><td>Bangunan Selesai</td><td></td><td></td></tr>
  </table>
  <p style="font-size:10px">V centang sesuai progress</p>
  <div class="right">Sidoarjo, {{tanggal}}</div>
{$ttd}
</div>

<!-- 6. Pemberitahuan Pencairan Dana KPR -->
<div class="letter">
{$kop}
  <div class="hdr">
    <div class="kv"><span class="k">Tanggal</span><span class="c">:</span><span class="v">{{tanggal}}</span></div>
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">...../FIN/SI/OUT-MNDR/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Pemberitahuan Pencairan Dana KPR</span></div>
  </div>
{$kepada}
  <p>Sehubungan dengan telah dilaksanakannya akad kredit pada tanggal <b>({{tgl_akad}})</b> atas nama:</p>
  <div class="kv"><span class="k">Nama</span><span class="c">:</span><span class="v"><b>{{nama}}</b></span></div>
  <div class="kv"><span class="k">Alamat Rumah</span><span class="c">:</span><span class="v"><b>{{unit}} - {{tipe}}</b></span></div>
  <div class="kv"><span class="k">KPR</span><span class="c">:</span><span class="v"><b>{{plafon}}</b></span></div>
  <p>Yang berlokasi di Java Residence Krian Desa Terung kulon Kecamatan Krian Kabupaten Sidoarjo Provinsi Jawa Timur. Adapun sertifikat atas tanah dan bangunan tersebut telah diserahkan oleh {{perusahaan}} dengan <b>Notaris {{notaris}}</b>. Maka atas realisasi akad kredit diatas, bersama ini kami mohon kepada PT. Bank Mandiri Kantor Cabang Gentengkali Surabaya untuk dapat mencairkan dana tersebut ke Rekening kami, adapun rinciannya sebagai berikut :</p>
  <p>Atas pencairan dana tersebut mohon di transfer ke rekening Bank Mandiri kami:</p>
  <div class="kv"><span class="k">Atas nama</span><span class="c">:</span><span class="v"><b>{{perusahaan}}</b></span></div>
  <div class="kv"><span class="k">No. Rekening</span><span class="c">:</span><span class="v"><b>141-00-9988886-9 (Giro Operasional)</b></span></div>
  <div class="kv"><span class="k">Sebesar 50%</span><span class="c">:</span><span class="v"><b>{{setengah_plafon}}</b></span></div>
  <div class="kv"><span class="k">No. Rekening</span><span class="c">:</span><span class="v"><b>141-00-8858868-6 (Giro Escrow)</b></span></div>
  <div class="kv"><span class="k">Sebesar 50%</span><span class="c">:</span><span class="v"><b>{{setengah_plafon}}</b></span></div>
  <p>Demikian permohonan ini, atas bantuan dan kerjasamanya kami ucapkan terimakasih.</p>
{$ttd}
</div>
HTML;
    }
}
