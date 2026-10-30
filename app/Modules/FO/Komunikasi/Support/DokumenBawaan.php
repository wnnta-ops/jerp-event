<?php

namespace App\Modules\FO\Komunikasi\Support;

/**
 * Teks BAWAAN SPR & SPRS — persis teks cetakan sebelum Template Dokumen ada. Dipakai bila
 * belum ada versi yang berlaku, dan sebagai isian awal saat membuat versi pertama.
 *
 * Kata kunci {nama_perusahaan} dll. diganti saat dicetak. Butir boleh memuat <b> <i> <u>.
 */
final class DokumenBawaan
{
    public const SPR = 'SPR';

    public const SPRS = 'SPRS';

    /** @return array<string,string> */
    public static function pilihan(): array
    {
        return [self::SPR => 'SPR (Surat Pemesanan Rumah)', self::SPRS => 'SPRS (Formulir Pemesanan Sementara)'];
    }

    /** @return array<string,string> */
    public static function kataKunci(): array
    {
        return [
            'nama_perusahaan' => 'Nama perusahaan',
            'nama_customer' => 'Nama customer',
            'no_kontrak' => 'Nomor kontrak',
            'unit' => 'Kode unit',
            'cluster' => 'Nama cluster',
            'tanggal' => 'Tanggal dokumen',
        ];
    }

    /** @return array<string,mixed> */
    public static function untuk(string $dokumen): array
    {
        return $dokumen === self::SPRS ? self::sprs() : self::spr();
    }

    /** @return array<string,mixed> */
    public static function spr(): array
    {
        $pt = '{nama_perusahaan}';

        return [
            'nama_perusahaan' => 'PT. MITRA USAHA PROPERTINDO',
            'paraf_perusahaan' => 'PT MUP',
            'kalimat_pembuka' => 'Dengan ini menyatakan telah memesan kepada '.$pt.', berkedudukan di Kecamatan Sidoarjo, Kabupaten Sidoarjo, untuk pembelian obyek yang berlokasi di Perumahan Java Residence, Kabupaten Sidoarjo, sebagai berikut :',
            'kalimat_syarat' => 'Untuk pemesanan tersebut di atas maka dengan ini pemesan menyetujui syarat dan ketentuan sebagai berikut:',
            'judul_pasal_bayar' => 'I. Harga Tanah dan Bangunan wajib dibayar oleh pemesan sesuai ketentuan sebagai berikut:',
            'pasal' => [
                ['judul' => 'II. Harga Pemesanan Sudah Termasuk :', 'isi' => '', 'halaman_baru' => true, 'butir' => [
                    'Sertipikat Hak Guna Bangunan (SHGB).',
                    'IMB (Ijin Mendirikan Bangunan) / PBG (Persetujuan Bangunan Gedung).',
                    'Pajak Pertambahan Nilai (PPN).',
                    'Pemasangan dan penyambungan instalasi jaringan air bersih.',
                    'Pemasangan dan penyambungan instalasi jaringan listrik PLN sesuai standart Developer.',
                    'Retribusi Penyediaan Lahan Makam Estate.',
                ]],
                ['judul' => 'III. Harga Pemesanan Belum Termasuk :', 'isi' => '', 'halaman_baru' => false, 'butir' => [
                    'Pajak Pertambahan Nilai Barang Mewah (PPNBM).',
                    'Biaya Administrasi (apabila ada).',
                    'Biaya Iuran Pemeliharaan Lingkungan (IPL) dan Biaya Utilitas.',
                    'Biaya Pengurusan KPR (*kecuali disepakati lain).',
                    'Semua Bea/Pajak, Biaya, Retribusi, Pungutan lainnya berdasarkan keputusan atau peraturan pemerintah yang berlaku.',
                ]],
                ['judul' => 'IV.', 'halaman_baru' => false, 'butir' => [], 'isi' => 'Dalam hal pemesan telah membayar Uang Tanda Jadi dan pemesan mengakhiri pemesanannya dengan alasan apapun, maka '.$pt.' dapat mengakhiri Surat Pemesanan ini sesuai butir XII di bawah, terkait dengan seluruh pembayaran yang telah dilakukan pemesan tidak dapat dituntut kembali atau ditarik dari '.$pt.'.'],
                ['judul' => 'V.', 'halaman_baru' => false, 'butir' => [], 'isi' => 'Untuk melaksanakan penandatanganan Akta Jual Beli (AJB) di hadapan Pejabat Pembuat Akta Tanah (PPAT) yang ditunjuk oleh '.$pt.', pemesan wajib terlebih dahulu membayar seluruh bea/pajak dan biaya yang belum termasuk dalam Harga Tanah dan Bangunan butir III di atas.'],
                ['judul' => 'VI.', 'halaman_baru' => false, 'butir' => [], 'isi' => 'Sebelum dilaksanakannya AJB di hadapan PPAT (untuk selanjutnya disebut AJB PPAT), apabila terjadi kenaikan tarif dan/atau pengenaan baru berdasarkan suatu perubahan atau peraturan baru yang dikeluarkan/diberlakukan oleh Pemerintah atas suatu pajak/bea dan biaya termasuk namun tidak terbatas pada PPN, BPHTB, atau kenaikan tarif NJOP, maka seluruhnya wajib ditanggung dan dibayar sepenuhnya oleh pemesan sebelum penandatanganan AJB PPAT, sejauh hal tersebut tidak disebabkan oleh '.$pt.'.'],
                ['judul' => 'VII.', 'halaman_baru' => false, 'butir' => [], 'isi' => 'Dalam hal pemesan belum membayar seluruh pajak/bea dan biaya sebagaimana butir V dan butir VI di atas sebelum dilaksanakannya penandatanganan AJB PPAT, maka '.$pt.' tidak wajib melaksanakan penandatanganan AJB PPAT, dan segala risiko serta akibatnya menjadi tanggungan pemesan sepenuhnya.'],
                ['judul' => 'VIII.', 'halaman_baru' => false, 'butir' => [], 'isi' => 'Apabila pemesan lalai dalam hal kurang atau terlambat melakukan suatu pembayaran berdasarkan Surat Pemesanan ini, maka pemesan dikenakan dan wajib membayar kepada '.$pt.' denda sebesar 1‰ (satu permil) per setiap hari keterlambatan dari jumlah terhutang sejak tanggal seharusnya dibayar sampai dilunasi seluruhnya, dengan keterlambatan maksimal 30 hari kerja dan maksimal denda 5% dari jumlah terhutang.'],
                ['judul' => 'IX.', 'halaman_baru' => false, 'butir' => [], 'isi' => 'Apabila pemesan lalai dalam hal kurang atau terlambat melakukan suatu pembayaran yang berlangsung maksimal 14 (empat belas) hari kalender dan/atau mundur sepihak karena alasan dan/atau kesalahan pemesan, maka '.$pt.' dapat mengakhiri Surat Pemesanan ini sesuai butir XII di bawah. Terhadap seluruh uang yang telah dibayar oleh pemesan (kecuali Uang Tanda Jadi) akan dikembalikan setelah dipotong biaya administrasi pengakhiran sebesar 30% (tiga puluh persen).'],
                ['judul' => 'X.', 'halaman_baru' => true, 'butir' => [], 'isi' => 'Semua pembayaran kepada '.$pt.' wajib dilakukan oleh pemesan kepada Bagian Keuangan di Kantor Pemasaran Mitra Usaha Propertindo, Kahuripan Nirwana Ruko Avenue Nomor 27-30, Sidoarjo – 61228, atau pembayaran melalui/transfer dengan mencantumkan nama pemesan, nomor Blok, maksud pembayaran, ditujukan ke rekening BCA 6155899909 atas nama PT. Mitra Usaha Propertindo. Seluruh pembayaran dianggap sah apabila telah diterima, efektif dan/atau dapat dicairkan, serta dibuktikan dengan dikeluarkannya kwitansi resmi.'],
                ['judul' => 'XI. PENGURUSAN FASILITAS KREDIT MELALUI BANK/LEMBAGA KEUANGAN/PEMBIAYAAN', 'isi' => '', 'halaman_baru' => false, 'butir' => [
                    'Pemesan wajib melengkapi data-data yang diperlukan oleh Bank/Lembaga Keuangan/Pembiayaan selambat-lambatnya 10 (sepuluh) hari kalender setelah tanggal pembayaran Tanda Jadi dan/atau 10 hari kalender sebelum pembayaran terakhir Uang Muka secara angsuran. Apabila lewat, pemesan dinyatakan lalai.',
                    'Untuk pemesan promo Free Uang Muka, wajib melengkapi data maksimal 10 hari kalender setelah pembayaran Uang Tanda Jadi.',
                    'Apabila pemesan tidak memenuhi undangan wawancara, atau sudah disetujui kredit namun belum akad kredit di hadapan Notaris dalam 14 hari kalender, maka pemesan lalai dan uang (kecuali UTJ) dikembalikan setelah dipotong 30%.',
                    'Apabila pemesan harus menambah Uang Muka, wajib dilunasi selambat-lambatnya 14 hari kalender setelah surat persetujuan kredit. Lewat tempo: dapat pengajuan KPR ulang 1 kali pada bank lain (14 hari), atau ubah cara bayar Cash Keras (14 hari, harga mengikuti SPR awal); jika lewat, dinyatakan batal dan uang (kecuali UTJ) dikembalikan dipotong 10%.',
                    'Apabila permohonan kredit ditolak (dibuktikan surat penolakan bank), '.$pt.' berhak mengakhiri SPR; Uang Muka dikembalikan dipotong 10%, dan Uang Tanda Jadi bersifat Refund.',
                    'Apabila pemesan melakukan pelanggaran salah satunya pemalsuan dan/atau rubah data, maka UTJ dianggap hangus dan pemesan menerima segala konsekuensi serta akibat hukumnya.',
                ]],
                ['judul' => 'XII.', 'halaman_baru' => false, 'butir' => [], 'isi' => 'Untuk pengakhiran Surat Pemesanan ini, '.$pt.' dan Pemesan sepakat melepaskan ketentuan Pasal 1265, 1266, 1267 KUH Perdata, dan pemesan memberikan kuasa sepenuhnya kepada '.$pt.' untuk menandatangani surat pengakhirannya, yang berlaku efektif sejak tanggal pengiriman surat pengakhiran tanpa perlu proses Pengadilan.'],
                ['judul' => 'XIII. KETENTUAN GANTI CARA BAYAR.', 'halaman_baru' => false, 'butir' => [], 'isi' => 'Apabila pemesan bermaksud mengubah cara bayar, wajib disampaikan tertulis kepada '.$pt.'; harga pembelian menjadi kewenangan '.$pt.', dan pemesan bertanggung jawab atas kewajiban perpajakan yang timbul.'],
                ['judul' => 'XIV. KETENTUAN PINDAH BLOK DAN NOMOR TANAH BESERTA BANGUNAN.', 'isi' => '', 'halaman_baru' => false, 'butir' => [
                    'Pemesan harus memberitahukan secara tertulis.',
                    'Pemindahan oleh '.$pt.' karena perubahan peruntukan tidak dikenakan biaya, dan akan diberitahukan terlebih dahulu.',
                    'Pemindahan atas keinginan pemesan diperbolehkan dengan ketentuan: mengajukan surat permohonan & disetujui; pembayaran lama (dikurangi PPN/PPh) diperhitungkan ke blok baru; harga lama dari saat pemesanan, harga baru dari saat pindah; menandatangani seluruh dokumen yang dipersyaratkan.',
                ]],
                ['judul' => 'XV. KETENTUAN PENGALIHAN HAK, KEWAJIBAN DAN TANGGUNG JAWAB SERTA GANTI NAMA.', 'isi' => '', 'halaman_baru' => true, 'butir' => [
                    'Pemesan mengajukan permohonan tertulis bersama pembeli baru (PIHAK KETIGA) menghadap '.$pt.'.',
                    'Apabila menggunakan fasilitas KPR, harus ada persetujuan tertulis dari Bank/Lembaga Keuangan/Pembiayaan.',
                    'Pemesan wajib membayar biaya administrasi pengalihan hak sebesar 2,5% dari Harga Pemesanan (dibebaskan untuk 1 kali pengalihan).',
                    'Ganti nama ke pihak keluarga (orang tua, istri/suami harta campur, anak kandung yang dapat dibuktikan) dikenakan biaya administrasi Rp 1.000.000,- per kejadian, maksimal 2 kali.',
                    'Pemesan dan/atau PIHAK KETIGA bertanggung jawab atas kewajiban perpajakan yang timbul.',
                    'Semua ketentuan SPR ini tetap berlaku terhadap pemesan dan/atau PIHAK KETIGA.',
                    'Menandatangani seluruh dokumen yang dipersyaratkan.',
                    'Proses pengalihan dilakukan dalam 14 hari kerja sejak dokumen dinyatakan lengkap.',
                ]],
                ['judul' => 'XVI. KETENTUAN ESTATE MANAJEMEN.', 'halaman_baru' => false, 'butir' => [], 'isi' => 'Pemesan sepakat patuh dan tertib terhadap ketentuan Estate Manajemen '.$pt.' demi kenyamanan dan keamanan kawasan; apabila tidak bersedia mengikuti tata tertib, pemesan bersedia menerima konsekuensi sesuai peraturan Estate Manajemen.'],
                ['judul' => 'XVII. FORCE MAJEURE.', 'halaman_baru' => false, 'butir' => [], 'isi' => $pt.' dan Pemesan setuju mengadakan perubahan/penambahan atas SPR apabila terjadi Force Majeure (gempa bumi, banjir, bencana alam, epidemi, pandemi, huru-hara, perang, keadaan politik, atau kelangkaan bahan bangunan yang mempengaruhi kegiatan usaha properti).'],
                ['judul' => 'XVII. KETENTUAN SERAH TERIMA.', 'isi' => '', 'halaman_baru' => true, 'butir' => [
                    '1 Lantai paling lambat 18 Bulan setelah pembayaran lunas (cash/InHouse) atau setelah akad kredit (KPR).',
                    '2 Lantai paling lambat 24 Bulan setelah pembayaran lunas (cash/InHouse) atau setelah akad kredit (KPR).',
                    'Keterlambatan serah terima dikenakan denda 0,5‰ per bulan dari Harga Pemesanan, maksimum 2 bulan keterlambatan.',
                    'Pemesan wajib mentaati seluruh aturan '.$pt.'/pengelola termasuk IPL, abodemen listrik/air, keamanan, kebersihan, PBB setelah serah terima (BAST).',
                    'Pemesan tidak diperbolehkan mengubah desain sebelum AJB & BAST; perubahan setelahnya wajib izin tertulis dan biaya menjadi tanggung jawab pemesan (khusus cash/Inhouse, selain perubahan façade).',
                    'Garansi bangunan dianggap hangus bila pemesan mengajukan desain ulang/perubahan desain.',
                ]],
                ['judul' => 'XVIII. PENYELESAIAN PERSELISIHAN.', 'isi' => '', 'halaman_baru' => false, 'butir' => [
                    'Jika timbul perselisihan dalam melaksanakan Surat Pemesanan ini, maka akan diselesaikan oleh '.$pt.' dan Pemesan secara musyawarah.',
                    'Apabila dalam jangka waktu 30 (tiga puluh) hari sejak sengketa atau beda pendapat tersebut penyelesaian secara musyawarah tidak tercapai, maka '.$pt.' dan Pemesan sepakat untuk menyelesaikannya melalui Pengadilan Negeri Sidoarjo.',
                ]],
            ],
            'kalimat_penutup' => 'Demikian Surat Pemesanan ini dibuat untuk dapat dilaksanakan sebagaimana mestinya.',
            'kota' => 'Sidoarjo',
            'penandatangan_nama' => 'Rachmat Suhendra',
            'penandatangan_jabatan' => 'Direktur',
        ];
    }

    /** @return array<string,mixed> */
    public static function sprs(): array
    {
        return [
            'nama_perusahaan' => 'PT. MITRA USAHA PROPERTINDO',
            'judul' => 'FORMULIR SURAT PEMESANAN RUMAH SEMENTARA',
            'syarat' => [
                'Untuk mendapatkan promo <i>free DP</i>, dokumen lengkap (siap proses KPR) diserahkan paling lambat 10 hari kalender sejak penandatangan surat ini, apabila melebihi waktu tersebut, maka Uang Tanda Jadi (UTJ) dialokasikan sebagai bagian <i>DP</i>.',
                'Uang Tanda Jadi (UTJ) dapat dikembalikan apabila hasil SLIK <i>COLL</i> 3-5 dan sebelum diterbitkannya SPR.',
                'Pembayaran wajib di transfer ke : BCA No. Rek 6155899909 an. PT. Mitra Usaha Propertindo.',
                'Formulir Pesanan Tanah &amp; Bangunan ini memiliki kekuatan hukum mengikat kedua belah pihak.',
            ],
            'kota' => 'Sidoarjo',
            'label_perusahaan' => 'PT. MUP',
        ];
    }

    /**
     * Teks aman untuk dicetak: kata kunci diganti (nilai di-escape), tag selain b/i/u/br dibuang, atributnya juga.
     */
    public static function teks(?string $teks, array $nilai): string
    {
        return EditorDokumen::aman(JenisPesan::isi($teks, $nilai, true));
    }
}
