<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsSpkDraft;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Set Up SPK — konfigurasi draft/template dokumen Cetak SPK, PER JENIS SPK
 * (Housing / Infrastruktur / Lainnya). Isi (kepala dokumen, mukadimah, para pihak,
 * pasal) dipakai Cetak SPK dengan mengganti placeholder {…}.
 *
 * Default bawaan disalin dari draft asli MUP: "SPK 076 - Draft SPK Housing" dan
 * "SPK 083 - Draft SPK Infra" (16 pasal; hanya judul pekerjaan, mukadimah, dan
 * lingkup Pasal 1 yang berbeda antar-jenis).
 */
class SpkDraftController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $jenis = self::normalizeJenis($request->input('jenis'));
        $draft = self::findDraft($jenis);

        return Inertia::render('BusinessSupport/Contractor/SpkDraft', [
            'jenis'        => $jenis,
            'jenisOpts'    => BsWorkOrder::JENIS,
            'draft'        => $draft ? $draft->only(BsSpkDraft::CONTENT_FIELDS) : self::defaultDraft($jenis),
            'isDefault'    => $draft === null,
            'placeholders' => self::PLACEHOLDERS,
            'tables'       => self::TABLES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'jenis'          => ['required', 'string', 'in:'.implode(',', array_keys(BsWorkOrder::JENIS))],
            'judul'          => ['required', 'string', 'max:120'],
            'subjudul'       => ['nullable', 'string', 'max:60'],
            'proyek_line'    => ['nullable', 'string', 'max:150'],
            'judul_pekerjaan' => ['nullable', 'string', 'max:200'],
            'tempat'         => ['nullable', 'string', 'max:80'],
            'pembukaan'      => ['nullable', 'string', 'max:1000'],
            'mukadimah'      => ['nullable', 'string', 'max:2000'],
            'pihak1_teks'    => ['nullable', 'string', 'max:2000'],
            'pihak2_teks'    => ['nullable', 'string', 'max:2000'],
            'pasals'         => ['required', 'array', 'min:1'],
            'pasals.*.title' => ['required', 'string', 'max:150'],
            'pasals.*.body'  => ['required', 'string', 'max:8000'],
            // Tabel yang disisipkan tepat di bawah isi pasal saat Cetak SPK (kosong = tanpa tabel).
            'pasals.*.table' => ['nullable', 'in:rincian,termin,unit'],
        ]);

        BsSpkDraft::updateOrCreate(
            ['tenant_id' => tenant_id(), 'jenis' => $v['jenis']],
            array_merge($v, ['updated_by' => $request->user()->id]),
        );

        return back()->with('success', 'Draft SPK '.BsWorkOrder::JENIS[$v['jenis']].' disimpan. Cetak SPK akan memakai template ini.');
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $jenis = self::normalizeJenis($request->input('jenis'));
        BsSpkDraft::query()->where('jenis', $jenis)->delete();

        return back()->with('success', 'Draft SPK '.BsWorkOrder::JENIS[$jenis].' dikembalikan ke default bawaan.');
    }

    /**
     * Template yang dipakai sebuah SPK: draft jenis-nya → draft lama (jenis NULL,
     * berlaku semua jenis) → default bawaan.
     */
    public static function findDraft(string $jenis): ?BsSpkDraft
    {
        return BsSpkDraft::query()->where('jenis', $jenis)->first()
            ?? BsSpkDraft::query()->whereNull('jenis')->first();
    }

    private static function normalizeJenis(mixed $jenis): string
    {
        $jenis = is_string($jenis) ? $jenis : '';

        return isset(BsWorkOrder::JENIS[$jenis]) ? $jenis : 'bangunan';
    }

    /** Placeholder yang tersedia (dijelaskan di UI). */
    public const PLACEHOLDERS = [
        '{pekerjaan}'        => 'Judul/uraian pekerjaan SPK',
        '{jenis}'            => 'Jenis pekerjaan',
        '{proyek}'           => 'Nama proyek',
        '{cluster}'          => 'Cluster SPK',
        '{tahap}'            => 'Tahap SPK',
        '{no_spk}'           => 'Nomor SPK',
        '{kontraktor}'       => 'Nama kontraktor (PIHAK KEDUA)',
        '{perusahaan}'       => 'Nama perusahaan (PIHAK PERTAMA)',
        '{nilai_rp}'         => 'Nilai kontrak (Rp)',
        '{nilai_terbilang}'  => 'Nilai kontrak (terbilang)',
        '{ppn_note}'         => 'Keterangan PPN / Non-PKP',
        '{ppn}'              => 'Persentase PPN',
        '{durasi}'           => 'Durasi (hari kalender)',
        '{durasi_terbilang}' => 'Durasi (terbilang)',
        '{periode_note}'     => 'Keterangan periode mulai–selesai',
        '{mulai}'            => 'Tanggal mulai',
        '{selesai}'          => 'Tanggal selesai',
        '{dp}'               => 'Persentase uang muka',
        '{retensi}'          => 'Persentase retensi',
        '{profit}'           => 'Persentase profit',
        '{tanggal}'          => 'Tanggal SPK (lengkap)',
        '{kota}'             => 'Kota/kedudukan perusahaan',
    ];

    /** Pilihan tabel yang bisa disisipkan di bawah sebuah pasal (dijelaskan di UI Set Up SPK). */
    public const TABLES = [
        'rincian' => 'Tabel Rincian Harga (BoQ + Sub Total 1 / Profit / PPN / Total Harga)',
        'unit'    => 'Tabel Rincian per Unit Rumah',
        'termin'  => 'Tabel Jadwal Pembayaran (Termin)',
    ];

    /**
     * Default bawaan = transkrip draft SPK asli MUP, dengan bagian yang berubah
     * diganti placeholder. Hanya judul pekerjaan, mukadimah, dan Pasal 1 yang
     * berbeda antara Housing dan Infrastruktur.
     *
     * @return array<string,mixed>
     */
    public static function defaultDraft(string $jenis = 'bangunan'): array
    {
        $infra  = $jenis === 'sarana_prasarana';
        $obyek  = $infra ? 'Pembangunan Infrastruktur' : 'Pembangunan Rumah';
        $obyekU = mb_strtoupper($obyek);

        return [
            'judul'           => 'SURAT PERJANJIAN KERJA',
            'subjudul'        => '(SPK)',
            'proyek_line'     => 'PROYEK {proyek}',
            'judul_pekerjaan' => $obyekU.' “{proyek} {cluster} {tahap}”',
            'tempat'          => '',
            'pembukaan'       => 'Pada hari ini, {tanggal}, kami yang bertanda-tangan dibawah ini:',
            'mukadimah'       => 'Bahwa Pihak Pertama selaku Developer Perumahan {proyek} memberikan Pekerjaan '.$obyek.' “{proyek} {cluster} {tahap}” atas lahan Perumahan Pihak Pertama kepada Pihak Kedua. Pihak Pertama dan Pihak Kedua bertindak bersama-sama selanjutnya disebut sebagai “PARA PIHAK”. Dan Para Pihak menyatakan dengan ini mengikatkan diri satu dengan yang lain dalam Surat Perjanjian Kerja (SPK) ini dengan ketentuan-ketentuan dan syarat-syarat sebagai berikut:',
            'pihak1_teks'     => 'Dalam hal ini bertindak sesuai jabatannya sebagai Direktur {perusahaan} berdasarkan Akta Perubahan terakhir Perseroan Terbatas, dan telah mendapat persetujuan dari Menteri Hukum dan Hak Asasi Manusia. Selanjutnya dalam perjanjian ini disebut sebagai PIHAK PERTAMA.',
            'pihak2_teks'     => 'Dalam hal ini bertindak sesuai jabatannya sebagai Direktur {kontraktor} berdasarkan Akta Pendirian yang dibuat dihadapan Notaris, dan selanjutnya dalam perjanjian ini disebut sebagai PIHAK KEDUA.',
            'pasals'          => self::defaultPasals($infra, $obyek),
        ];
    }

    /** @return array<int, array<string, string>> */
    private static function defaultPasals(bool $infra, string $obyek): array
    {
        $lingkup = $infra
            ? "1. Pekerjaan Persiapan: Pengukuran; Fasilitas dan Pengendalian Sementara; Pembersihan Awal dan Akhir; Mobilisasi dan Demobilisasi.\n"
                ."2. Pekerjaan Tanah dan Pondasi.\n"
                ."3. Pekerjaan Struktur.\n"
                ."4. Pekerjaan Bata dan Finishing Dinding.\n"
                ."5. Pekerjaan Elektrikal: Sistem Distribusi Daya; Sistem Penerangan."
            : "1. Pekerjaan Persiapan: Pengukuran; Fasilitas dan Pengendalian Sementara; Pembersihan Awal dan Akhir; Mobilisasi dan Demobilisasi.\n"
                ."2. Pekerjaan Tanah dan Pondasi.\n"
                ."3. Pekerjaan Struktur.\n"
                ."4. Pekerjaan Bata dan Finishing Dinding.\n"
                ."5. Pekerjaan Pintu dan Jendela termasuk Aksesoris.\n"
                ."6. Pekerjaan Konstruksi Atap dan Penutup Atap.\n"
                ."7. Pekerjaan Finishing Lantai.\n"
                ."8. Pekerjaan Plafond.\n"
                ."9. Pekerjaan Cat.\n"
                ."10. Pekerjaan Plumbing: Sistem Distribusi Air Bersih; Sistem Distribusi Air Buangan; Perlengkapan Plumbing.\n"
                ."11. Pekerjaan Elektrikal: Sistem Distribusi Daya; Sistem Penerangan.\n"
                ."12. Pekerjaan Halaman / Ruang Luar: Carport, Pagar, Tanggulan; Pengurugan/Peninggian elevasi halaman depan/samping/belakang; Pembersihan/Perapihan/General Cleaning Bangunan, Halaman & Saluran.";

        return [
            ['title' => 'Pasal 1 — Lingkup Pekerjaan', 'table' => '', 'body' =>
                'Lingkup pekerjaan yang diserahkan Pihak Pertama dan dilaksanakan Pihak Kedua terdiri dari paket Pekerjaan '.$obyek.' “{proyek} {cluster} {tahap}” yang berada di Perumahan {proyek}, yang kegiatan utamanya yaitu menjadi kontraktor untuk Suplai Material & Jasa Borong dengan rincian pekerjaan sebagai berikut:'."\n\n".$lingkup],

            ['title' => 'Pasal 2 — Dasar Pelaksanaan Pekerjaan', 'table' => '', 'body' =>
                "(1) Dasar pelaksanaan pekerjaan yang tercantum dalam Pasal 1 harus dilaksanakan oleh Pihak Kedua dengan memperhatikan ketentuan-ketentuan yang merupakan bagian tidak terpisahkan dari Perjanjian Kerja, yaitu:\n"
                ."a. Spesifikasi teknis dan Spesifikasi umum, Gambar Kerja, Volume Pekerjaan dan Surat Penawaran Harga dari Pihak Kedua lengkap dengan semua bagian-bagiannya, sejauh tidak bertentangan dengan Surat Perjanjian Kerja ini;\n"
                ."b. Surat Perintah Mulai Pekerjaan (SPMK) berikut Berita Acara Kesepakatan klarifikasi/Negosiasi;\n"
                ."c. Susunan organisasi proyek Pihak Kedua;\n"
                ."d. Jadwal Pekerjaan (time schedule) / Kurva-S;\n"
                ."e. Pedoman hubungan kerja antara Pihak Kedua dengan Pihak Pertama, kecuali ditentukan lain dalam Surat Perjanjian Kerja ini;\n"
                ."f. Petunjuk-petunjuk tertulis dari Pihak Pertama yang sesuai dengan tujuan dan maksud dari Perjanjian Kerja ini;\n"
                ."g. Catatan-catatan dalam Laporan Harian / Buku Harian ataupun Memo Lapangan;\n"
                ."h. Adendum yang timbul berupa pekerjaan tambah ataupun pekerjaan kurang dan amandemen Perjanjian Kerja ini;\n"
                ."i. Semua pelaksanaan dan surat menyurat resmi.\n\n"
                .'(2) Apabila terjadi ketidaksesuaian antar dokumen teknis di atas maka yang menjadi acuan atau dasar adalah dokumen teknis dengan urutan hierarki tertinggi berdasarkan Pasal 2 ayat (1) perjanjian ini.'],

            ['title' => 'Pasal 3 — Penelitian Pihak Kedua', 'table' => '', 'body' =>
                "(1) Pihak Kedua dengan ini menyatakan dengan tegas bahwa pekerjaan sebagaimana tersebut dalam Pasal 1 Surat Perjanjian Kerja ini diterima dengan telah mengadakan penelitian dan pemeriksaan sendiri secara seksama mencakup:\n"
                ."a. Lahan pekerjaan beserta pekerjaan yang mendahuluinya;\n"
                ."b. Kualitas dan kuantitas baik dari bahan-bahan maupun tenaga ahli dan buruh yang diperlukan untuk pekerjaan;\n"
                ."c. Standar minimal hasil pekerjaan yang harus dicapai;\n"
                ."d. Ketentuan dan peraturan-peraturan yang berlaku;\n"
                ."e. Kondisi umum dan kondisi setempat;\n"
                ."f. Hal-hal lain yang telah diperhitungkan oleh Pihak Kedua dengan matang.\n\n"
                ."(2) Pihak Kedua akan melaksanakan pekerjaan yang diberikan oleh Pihak Pertama dengan penuh tanggung jawab dan memperhatikan segala ketentuan dalam Pasal 3 ayat (1) dan peraturan perundang-undangan yang berlaku.\n\n"
                .'(3) Apabila terjadi kesalahan atau kegagalan atau dampak negatif lain dalam penelitian yang dilakukan Pihak Kedua, yang menyebabkan terhambatnya proses pekerjaan yang dilakukan Pihak Kedua, Pihak Kedua melepaskan haknya untuk mengajukan tuntutan apapun kepada Pihak Pertama.'],

            ['title' => 'Pasal 4 — Nilai Kontrak', 'table' => 'rincian', 'body' =>
                '(1) Nilai Kontrak untuk menyelesaikan pekerjaan yang tercantum dalam Pasal 1 Surat Perjanjian Kerja ini merupakan harga tetap dan pasti bagi pekerjaan sebagaimana diperinci dalam Perincian Harga Borongan terlampir dan merupakan bagian yang tidak terpisahkan dari Surat Perjanjian Kerja ini adalah sebesar {nilai_rp}'."\n"
                .'Terbilang: ({nilai_terbilang})'."\n"
                .'Sudah termasuk keuntungan/jasa, PPh & Pajak Pertambahan Nilai (PPN) {ppn}%, berdasarkan harga terkoreksi setelah klarifikasi dan negosiasi antara Pihak Pertama dengan Pihak Kedua, dengan rincian sebagai berikut:'],

            ['title' => 'Pasal 4 (lanjutan) — Perubahan Nilai Kontrak', 'table' => '', 'body' =>
                "(2) Nilai Kontrak yang tersebut dalam Pasal 4 ayat (1) dapat berubah apabila:\n"
                ."a. Dalam pelaksanaan Perjanjian ini dikemudian hari ternyata terdapat perubahan berupa penambahan atau pengurangan pekerjaan;\n"
                ."b. Penambahan atau pengurangan harga dimaksud akan dibuat berdasarkan Harga Satuan Pekerjaan dari penawaran Pihak Kedua yang telah diverifikasi menjadi bagian dari dokumen Surat Perjanjian Kerja ini;\n"
                ."c. Pengurangan atau penambahan Nilai Kontrak tidak boleh lebih dari 5% (lima perseratus) dari Nilai Kontrak yang sudah disepakati dalam Pasal 4 ayat (1), kecuali:\n"
                ."   - Pengurangan oleh Pihak Pertama yang disebabkan oleh kesalahan Pihak Kedua dalam menjalankan kewajibannya;\n"
                ."   - Pengurangan atau penambahan yang disebabkan atas kepentingan Pihak Pertama sehubungan dengan kebutuhan penambahan atau hal lain yang tidak tercantum dalam Daftar/Volume Pekerjaan dan akibat keadaan memaksa berdasarkan kesepakatan bersama;\n"
                ."   - Pengurangan atau penambahan volume pelaksanaan di lapangan berdasarkan lingkup pekerjaan dalam Surat Perjanjian Kerja ini.\n\n"
                .'(3) Semua pajak, bea dan pungutan negara lainnya sehubungan dengan pelaksanaan Perjanjian ini menjadi beban dan tanggung jawab Pihak Kedua.'],

            ['title' => 'Pasal 5 — Mekanisme Pembayaran', 'table' => 'termin', 'body' =>
                "Pembayaran Nilai Kontrak tersebut pada Pasal 4 Perjanjian ini dilaksanakan dengan cara angsuran dan memindahbukukan ke Rekening Bank Pihak Kedua, atau cara lain yang disetujui oleh kedua belah pihak, setelah diterimanya Surat Permintaan Pembayaran dari Pihak Kedua dan telah disetujui oleh Pihak Pertama yang kemudian dinyatakan dengan diterbitkannya Berita Acara Pembayaran (BAP) dengan ketentuan:\n"
                ."(1) Pembayaran pertama berupa uang muka sebesar {dp}% dari Nilai Kontrak;\n"
                ."(2) Pembayaran dilakukan sesuai progres setiap akhir bulan sampai dengan diterbitkannya Berita Acara Serah Terima Pertama (BAST I), dibayarkan sesuai progress lapangan dikurangi proporsional pengembalian uang muka (bila ada uang muka) dan proporsional retensi yang besarnya {retensi}% dari progres;\n"
                ."(3) Pembayaran terakhir berupa retensi sebesar {retensi}% dari Nilai Kontrak dibayarkan setelah masa pemeliharaan yaitu 3 (tiga) bulan setelah pekerjaan 100% dan diterbitkannya Berita Acara Serah Terima Kedua (BAST II) yang diajukan oleh Pihak Kedua dan disetujui oleh Pihak Pertama;\n"
                .'(4) Pembayaran dari Pihak Pertama kepada Pihak Kedua akan dilaksanakan selambat-lambatnya dalam waktu 30 (tiga puluh) hari kalender sejak Pihak Pertama menerima Surat Tagihan dari Pihak Kedua yang dilengkapi dengan Sertifikat Pembayaran, Invoice dan Faktur Pajak serta dokumen penunjang lainnya, seperti Laporan Progres dan Berita Acara Pemeriksaan Kemajuan Pekerjaan (BAPKP), foto-foto kemajuan pekerjaan, fotokopi SPK dan bukti setor pembayaran pajak sebelumnya serta lampiran-lampiran lain jika diminta oleh Pihak Pertama.'],

            ['title' => 'Pasal 6 — Jangka Waktu Pelaksanaan dan Masa Pemeliharaan', 'table' => '', 'body' =>
                "(1) Pihak Kedua wajib menyelesaikan pekerjaan sebagaimana tercantum dalam Pasal 1 Surat Perjanjian Kerja ini dengan jangka waktu pelaksanaan selama {durasi} ({durasi_terbilang}) hari kalender, {periode_note}.\n"
                ."(2) Untuk menentukan kemajuan pekerjaan, Pihak Kedua wajib membuat Laporan Kemajuan Pekerjaan sesuai format ketentuan Pihak Pertama yang digunakan sebagai dasar pembayaran angsuran sebagaimana tercantum dalam Pasal 5.\n"
                ."(3) Segera setelah semua pekerjaan diselesaikan, dibuat Berita Acara Serah Terima Pertama (BAST I) yang ditandatangani oleh Pihak Kedua dan disetujui oleh Pihak Pertama.\n"
                ."(4) Jangka waktu masa pemeliharaan adalah selama 3 (tiga) bulan terhitung sejak Berita Acara Serah Terima Pertama (BAST I).\n"
                .'(5) Apabila selama masa pemeliharaan terjadi kerusakan atau kekurangan lainnya berdasarkan kesepakatan kerja, maka Pihak Kedua wajib memperbaiki sesuai perjanjian kerja yang telah disepakati dan Pihak Pertama dibebaskan atas segala biaya yang timbul; apabila ada penambahan biaya menjadi tanggung jawab Pihak Kedua.'],

            ['title' => 'Pasal 7 — Perbaikan dan/atau Penggantian', 'table' => '', 'body' =>
                "(1) Apabila Pihak Kedua alpa dan/atau lalai untuk menyelesaikan dan menyerahkan pekerjaan dalam batas waktu yang telah ditentukan, Pihak Pertama tanpa mengurangi ketentuan-ketentuan lain dalam Perjanjian ini berhak untuk menguasai atau menyuruh menguasai seluruh pekerjaan, dengan ketentuan Pihak Kedua tidak dibebaskan dari tanggung jawab berdasarkan Surat Perjanjian Kerja ini.\n"
                ."(2) Kealpaan dan/atau kelalaian yang dimaksud pada ayat (1) adalah lewatnya waktu dan/atau fakta perbuatan Pihak Kedua yang tidak melaksanakan kewajiban sesuai ketentuan Surat Perjanjian Kerja ini.\n"
                .'(3) Berdasarkan kealpaan dan/atau kelalaian dimaksud, Pihak Pertama berhak mengerjakan atau menunjuk Pihak Ketiga tanpa perlu persetujuan Pihak Kedua untuk mengerjakan perbaikan dan/atau penggantian sebagian atau seluruh pekerjaan Pihak Kedua yang ditolak oleh Pihak Pertama atas beban dan biaya Pihak Kedua.'],

            ['title' => 'Pasal 8 — Pengawasan Pekerjaan', 'table' => '', 'body' =>
                "(1) Pihak Pertama menunjuk Tim Proyek sebagai wakil yang berkuasa penuh dalam pengelolaan pelaksanaan pekerjaan.\n"
                ."(2) Pihak Kedua dalam melaksanakan pekerjaan berdasarkan Perjanjian ini akan menerima dan mematuhi pengarahan, petunjuk dan keputusan dari Tim Proyek.\n"
                .'(3) Pihak Kedua bekerja sama dengan pemborong/kontraktor lain berdasarkan perjanjiannya dan Pihak Pertama yang berkaitan dengan pelaksanaan pekerjaan ini, dan satu sama lain akan dikoordinasikan oleh Tim Proyek.'],

            ['title' => 'Pasal 9 — Pembatalan/Pemutusan Perjanjian', 'table' => '', 'body' =>
                "(1) Pihak Pertama berhak membatalkan dan/atau memutuskan Perjanjian Kerja ini apabila Pihak Kedua:\n"
                ."a. Dalam proses pengerjaannya melampaui jangka waktu yang ditentukan dalam Pasal 6 ayat (1);\n"
                ."b. 20% (dua puluh persen) terlambat dari rencana kemajuan pekerjaan sebagaimana disepakati, tanpa alasan yang jelas dan dapat dipertanggungjawabkan;\n"
                ."c. Merusak hubungan kerja yang baik;\n"
                ."d. Melakukan pelanggaran terhadap peraturan perundang-undangan yang berlaku;\n"
                ."e. Bertentangan dengan instruksi-instruksi Pihak Pertama.\n\n"
                ."(2) Pembatalan dan/atau pemutusan perjanjian tersebut akan disampaikan oleh Pihak Pertama kepada Pihak Kedua secara tertulis dengan memuat alasan-alasannya, sifat dan tanggal berlakunya pengakhiran pekerjaan, serta hak dan kewajiban kedua pihak, dalam tenggang waktu 7 (tujuh) hari kalender setelah tanggal teguran tertulis terakhir disampaikan.\n\n"
                ."(3) Dalam hal demikian, Pihak Kedua menanggung semua biaya yang timbul dan wajib menyerahkan kepada Pihak Pertama semua dokumen, gambar, data dan keterangan lain yang berhubungan dengan pelaksanaan pekerjaan berdasarkan Surat Perjanjian Kerja ini.\n\n"
                .'(4) Pihak Pertama berhak mengambil alih sebagian dan/atau seluruh pekerjaan dalam Kontrak dan menyelesaikannya sendiri atau menunjuk Pihak Ketiga. Bila biaya yang dikeluarkan Pihak Pertama melebihi jumlah yang disebutkan dalam Kontrak, akan dibebankan kepada Pihak Kedua.'],

            ['title' => 'Pasal 10 — Sanksi & Denda', 'table' => '', 'body' =>
                "(1) Pihak Kedua akan dikenakan denda sebesar 1‰ (satu permil) dari Nilai Kontrak untuk setiap hari keterlambatan dari tanggal penyelesaian seluruh pekerjaan kecuali demobilisasi sebagaimana tersebut dalam Pasal 6, dengan denda setinggi-tingginya sebesar 5% (lima persen) dari Nilai Kontrak.\n"
                ."(2) Pihak Pertama berhak memberikan sanksi berupa Surat Teguran atas pekerjaan yang dianggap tidak sesuai dengan kesepakatan kerja dalam perjanjian ini. Apabila teguran tersebut tidak mendapat respon yang baik maka Pihak Pertama berhak membatalkan sepihak perjanjian kerja sama ini dan Pihak Kedua membebaskan Pihak Pertama atas tuntutan hukum apapun.\n"
                .'(3) Ketentuan mengenai kewajiban Pihak Kedua untuk membayar denda tidak mengurangi hak Pihak Pertama untuk melakukan tindakan seperti diatur dalam Pasal 8 dan/atau mengakhiri Perjanjian menurut Pasal 9 Surat Perjanjian Kerja ini.'],

            ['title' => 'Pasal 11 — Sarana dan Tenaga Kerja', 'table' => '', 'body' =>
                "(1) Pihak Kedua wajib menyediakan peralatan yang dibutuhkan untuk melakukan pekerjaan sekurang-kurangnya sebagaimana dicantumkan dalam penawarannya dan sarana kerja lainnya termasuk bengkel, gudang dan kantor di lokasi pekerjaan.\n"
                ."(2) Pihak Kedua wajib menyediakan tenaga kerja yang diperlukan untuk melaksanakan pekerjaan berdasarkan Perjanjian Kerja ini dengan ketentuan:\n"
                ."a. Pihak Kedua harus mengikuti ketentuan peraturan perundang-undangan yang berlaku, wajib mengatur dan menjaga keamanan kerja serta mencegah kecelakaan dan bahaya yang mungkin terjadi pada pekerja; jika terjadi kecelakaan, Pihak Kedua wajib memberikan pertolongan kepada penderita dan membayar segala biaya yang diperlukan;\n"
                .'b. Pihak Kedua wajib menyediakan tempat tinggal yang layak untuk pekerja yang berada di luar daerah, kecuali atas persetujuan tertulis dari Pihak Pertama.'],

            ['title' => 'Pasal 12 — Keadaan Memaksa', 'table' => '', 'body' =>
                "(1) Yang dapat dianggap sebagai Keadaan Memaksa (Force Majeure) adalah peristiwa di luar kemampuan Para Pihak yang dapat mempengaruhi pelaksanaan kesepakatan dan jalannya pelaksanaan pekerjaan, yaitu:\n"
                ."a. Bencana alam seperti gempa bumi, angin topan, banjir, epidemi, dan lain-lain;\n"
                ."b. Perang, revolusi, makar, huru-hara, pemberontakan, kerusuhan dan kekacauan, kebakaran;\n"
                ."c. Peraturan Pemerintah di bidang moneter yang pelaksanaannya sesuai dengan keputusan pemerintah.\n\n"
                ."(2) Apabila terjadi force majeure maka:\n"
                ."a. Pihak yang berada dalam keadaan memaksa wajib segera memberitahukan kepada pihak lain secara tertulis, menjelaskan sebab dan akibatnya serta langkah-langkah yang perlu diambil untuk mengatasinya;\n"
                ."b. Apabila dalam waktu 7 (tujuh) hari sejak terjadinya keadaan memaksa Pihak Pertama tidak membuat pernyataan sebagaimana dimaksud, maka Pihak Kedua berhak mengajukan keadaan memaksa kepada Pihak Pertama untuk mendapatkan persetujuan tertulis;\n"
                .'c. Jika dalam waktu 3 x 24 (tiga kali dua puluh empat) jam sejak diterimanya pemberitahuan Pihak Kedua, Pihak Pertama tidak memberikan jawaban, maka Pihak Pertama dianggap menyetujui terjadinya keadaan memaksa tersebut.'],

            ['title' => 'Pasal 13 — Penyelesaian Perselisihan', 'table' => '', 'body' =>
                "(1) Apabila terjadi perselisihan antara kedua pihak mengenai pelaksanaan Surat Perjanjian Kerja ini, pertama-tama akan diselesaikan secara musyawarah.\n"
                ."(2) Apabila musyawarah tidak membawa penyelesaian, kedua belah pihak akan meminta agar perselisihan diselesaikan oleh Dewan Arbitrase yang terdiri dari seorang anggota yang ditunjuk oleh Pihak Pertama, seorang anggota yang ditunjuk oleh Pihak Kedua, dan anggota ketiga sebagai ketua yang ditunjuk oleh kedua anggota tadi, atau Badan Arbitrase Nasional Indonesia (BANI) sesuai Peraturan/Prosedur BANI. Biaya yang timbul atas penyelesaian tersebut menjadi tanggung jawab Pihak Kedua.\n"
                ."(3) Bila dengan cara musyawarah atau arbitrase perselisihan tidak dapat diselesaikan, maka kedua belah pihak akan membuat persetujuan dan meminta agar sengketa diselesaikan oleh Pengadilan Negeri {kota}.\n"
                .'(4) Selama proses penyelesaian perselisihan berlangsung, Pihak Kedua tidak dapat mempergunakannya sebagai alasan untuk menunda pelaksanaan pekerjaan sesuai jadwal yang telah ditetapkan.'],

            ['title' => 'Pasal 14 — Ketentuan Hukum', 'table' => '', 'body' =>
                'Untuk pelaksanaan Perjanjian ini kedua pihak memilih tempat kedudukan hukum (domisili) di kantor Pengadilan Negeri {kota}.'],

            ['title' => 'Pasal 15 — Lain-lain', 'table' => '', 'body' =>
                "(1) Pihak Pertama wajib segera mempertimbangkan setiap persetujuan dan/atau izin yang disampaikan tertulis oleh Pihak Kedua dalam waktu 14 (empat belas) hari kalender setelah diterimanya permohonan tersebut; jika jangka waktu tersebut terlampaui tanpa adanya keputusan, maka dianggap telah disetujui oleh Pihak Pertama.\n"
                ."(2) Dokumen Kontrak dibuat untuk saling menjelaskan satu sama lain, dan jika terjadi pertentangan antara Surat Perjanjian Kerja ini dengan dokumen lain maka yang berlaku adalah Surat Perjanjian Kerja ini.\n"
                .'(3) Perubahan atas Perjanjian ini hanya sah dan mengikat bila dibuat tertulis dan ditandatangani oleh kedua pihak dalam bentuk Addendum yang akan menjadi bagian tidak terpisahkan dari Surat Perjanjian Kerja ini.'],

            ['title' => 'Pasal 16 — Penutup', 'table' => '', 'body' =>
                "(1) Surat Perjanjian Kerja ini dinyatakan sah dan mengikat kedua pihak dan berlaku sejak ditandatangani sebagaimana telah disepakati bersama sampai dengan hak dan kewajiban kedua pihak selesai dilaksanakan.\n"
                .'(2) Perjanjian ini dibuat dalam rangkap 2 (dua) bermeterai cukup yang semuanya mempunyai kekuatan hukum yang sama, untuk dipatuhi dan dilaksanakan dengan itikad baik.'],
        ];
    }
}
