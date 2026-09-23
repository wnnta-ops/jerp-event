<?php

namespace App\Modules\FO\MarketingSales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\BO\IproBridge\Services\RekomBatalCashService;
use App\Modules\FO\Collection\Models\SpLetter;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Fp;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Models\SprBankPrint;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\CustomerQrService;
use App\Settings\CollectionSettings;
use App\Support\Terbilang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Browsershot\Browsershot;

/**
 * Cetak dokumen closing: SPR (Surat Pemesanan Rumah), Kwitansi (dari BKM),
 * dan Jadwal Pembayaran (dari baris skema). HTML printable; ?pdf=1 → Browsershot.
 */
class ClosingPrintController extends Controller
{
    public function spr(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks', 'tagihan']);
        $nup = NupRegistration::query()->where('reservasi_id', $kontrak->reservasi_id)->orderByDesc('id')->first();

        return $this->render('pdf.fo.spr', ['kontrak' => $kontrak, 'nup' => $nup], 'SPR-'.$kontrak->no_kontrak);
    }

    /**
     * Cetak SPR ACCOUNTING (untuk perpajakan). Sama dengan SPR, tetapi:
     *  - DP yang di-free-kan + subsidi DP (baris "UANG MASUK") dilebur ke DISKON
     *    (rumus: Diskon = diskon kontrak + Free DP + Subsidi).
     *  - Baris UANG MASUK dihilangkan dari jadwal → hanya kas riil (Booking Fee,
     *    DP nyata, Pencairan KPR). Total jadwal = Harga − Diskon (tetap konsisten).
     */
    public function sprAccounting(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks', 'tagihan']);
        $nup = NupRegistration::query()->where('reservasi_id', $kontrak->reservasi_id)->orderByDesc('id')->first();

        $tagihan = $kontrak->tagihan ?? collect();
        $uangMasukTotal = (float) $tagihan->where('nama_tagihan', 'UANG MASUK')->sum('nilai');
        $tagihanAcc = $tagihan->reject(fn ($t) => $t->nama_tagihan === 'UANG MASUK')->values();

        return $this->render('pdf.fo.spr', [
            'kontrak' => $kontrak,
            'nup' => $nup,
            'accounting' => true,
            'tagihan' => $tagihanAcc,
            'diskon' => (float) $kontrak->diskon + $uangMasukTotal,
            'net' => (float) $kontrak->total_kontrak - $uangMasukTotal,
        ], 'SPR-ACC-'.$kontrak->no_kontrak);
    }

    /**
     * SPRS (Surat Pemesanan Rumah Sementara) — dari NupRegistration. Dipakai utk
     * dilihat/diunduh customer (link dikirim via WA) & dilampirkan di email saat
     * verifikasi data di-APPROVE. Bila sudah ada kontrak (reservasi naik kontrak),
     * data unit/harga/pembayaran ikut ditarik dari kontrak.
     */
    public function sprs(NupRegistration $registration): mixed
    {
        return $this->render('pdf.fo.sprs', self::sprsData($registration),
            self::namaBerkasSprs($registration));
    }

    /**
     * Data lengkap untuk view SPRS — SATU sumber untuk semua jalur cetak/kirim.
     *
     * WAJIB dipakai siapa pun yang me-render 'pdf.fo.sprs'. Sebelumnya lampiran
     * email membangun sendiri dengan hanya ['reg' => $reg], sehingga $qrSvg, $tts,
     * dan $kontrak tak pernah terisi: PDF di email keluar TANPA halaman QR dan
     * tanpa data kontrak/kwitansi, sementara PDF yang sama di WA (lewat controller)
     * lengkap. Dijadikan static+public agar tak ada lagi jalur yang menyusun
     * sendiri dan diam-diam kehilangan data.
     */
    public static function sprsData(NupRegistration $registration): array
    {
        $registration->load(['customer', 'agent', 'event']);
        $kontrak = $registration->reservasi_id
            ? Kontrak::with(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks'])
                ->where('reservasi_id', $registration->reservasi_id)->orderByDesc('id')->first()
            : null;

        return self::sprsViewData($registration, $kontrak);
    }

    /**
     * KWITANSI UTJ — dokumen SATU HALAMAN, terbit hanya setelah pembayaran Xendit
     * berhasil. Berbeda dari kwitansi BKM (yang butuh BKM/kontrak) dan dari halaman
     * kwitansi di SPRS: pada tahap ini kontrak belum ada, jadi datanya murni dari
     * pendaftaran NUP + hasil pembayaran Xendit.
     */
    public function kwitansiUtj(NupRegistration $registration): mixed
    {
        return $this->render('pdf.fo.kwitansi-utj', self::kwitansiUtjData($registration),
            self::namaBerkasKwitansiUtj($registration));
    }

    /** Data view kwitansi UTJ — satu sumber untuk cetak, WA, dan email. */
    public static function kwitansiUtjData(NupRegistration $registration): array
    {
        $registration->load(['customer', 'event', 'projectPref']);

        return ['reg' => $registration];
    }

    /** Nama berkas kwitansi UTJ: KWITANSI-<external id Xendit>, tanpa karakter jalur. */
    public static function namaBerkasKwitansiUtj(NupRegistration $reg): string
    {
        $nomor = (string) ($reg->xendit_external_id ?: ($reg->no_nup ?: $reg->id));

        return 'KWITANSI-'.trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $nomor), '-');
    }

    /** Token path kwitansi UTJ — alasan sama seperti sprsPdfHash() di bawah. */
    public static function kwitansiUtjPdfHash(int $registrationId): string
    {
        return hash_hmac('sha256', 'kwitansi-utj-pdf:'.$registrationId, (string) config('app.key'));
    }

    /** PDF kwitansi UTJ via path-token (tanpa query string) — header dokumen WA. */
    public function kwitansiUtjPdf(NupRegistration $registration, string $hash): mixed
    {
        abort_unless(hash_equals(self::kwitansiUtjPdfHash($registration->id), $hash), 403);
        request()->merge(['pdf' => 1]);

        return $this->kwitansiUtj($registration);
    }

    /**
     * Token path untuk URL PDF SPRS TANPA query string. Qontak/Meta menolak URL
     * dokumen WA yang mengandung '?','&','=' — signed URL biasa tak bisa dipakai.
     * HMAC dgn APP_KEY → sekuat signature bawaan, tapi aman di path.
     */
    public static function sprsPdfHash(int $registrationId): string
    {
        return hash_hmac('sha256', 'sprs-pdf:'.$registrationId, (string) config('app.key'));
    }

    /** PDF SPRS via path-token (tanpa query string) — dipakai header dokumen WA. */
    /**
     * SPR Bank — cetakan untuk berkas pengajuan ke bank.
     *
     * HALAMAN CETAK SAJA. Tidak menulis apa pun ke database, tidak menyentuh SPRS
     * yang sudah ada, dan SENGAJA tidak memakai NumeratorService: memanggilnya akan
     * memakai satu nomor urut sungguhan padahal ini sekadar cetakan yang boleh
     * diulang berkali-kali. Nomor surat disusun dari urutan NUP dan bisa disunting
     * langsung di halaman sebelum dicetak.
     */
    public function sprBank(NupRegistration $registration): mixed
    {
        $romawi = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
        $kini = now();
        // Mengikuti No NUP, bukan kolom `urutan`: keduanya bisa berbeda, dan yang
        // dipegang customer serta tercetak di berkas lain adalah No NUP.
        // "NUP-00001" → 1 → "001".
        $angka = (int) preg_replace('/\D+/', '', (string) ($registration->no_nup ?: ''));
        $urut = str_pad((string) ($angka ?: $registration->id), 3, '0', STR_PAD_LEFT);

        $noSurat = $urut.'/SPR/NUP/'.$romawi[(int) $kini->format('n')].'/'.$kini->format('Y');

        $catatan = SprBankPrint::where('nup_registration_id', $registration->id)->first();
        // Dua-duanya baca-saja; bedanya `cetak` langsung membuka dialog cetak.
        $cetakLangsung = request()->boolean('cetak');
        $hanyaLihat = $cetakLangsung || request()->boolean('preview');

        $view = view('pdf.fo.spr-bank', self::sprsData($registration) + [
            'noSurat' => $catatan?->no_surat ?: $noSurat,
            // Preview menampilkan lembar yang BENAR-BENAR dicetak, bukan formulir yang
            // dibangun ulang dari data: sebagian kolomnya diisi tangan di layar dan
            // tidak tersimpan di tabel mana pun.
            'isiTersimpan' => $catatan?->isi_html,
            'hanyaLihat' => $hanyaLihat,
            'cetakLangsung' => $cetakLangsung,
            'skripCetak' => self::SKRIP_CETAK,
            // Bertanda tangan: lihat alasannya di definisi rutenya.
            'urlSimpan' => URL::signedRoute('fo.cetak.spr-bank.simpan', ['registration' => $registration->id]),
        ]);

        if (! $hanyaLihat) {
            return $view;
        }

        // HANYA halaman pratinjau yang menampilkan HTML kiriman peramban, dan hanya
        // di sinilah CSP ini dipasang. `script-src 'none'` membuat sisa suntikan
        // apa pun tidak bisa dieksekusi — penyaring regex di bersihkanHtml() gampang
        // ditembus (mutasi HTML, atribut terpisah baris, entitas), jadi ia tidak
        // boleh menjadi satu-satunya pertahanan. Halaman sunting sengaja TIDAK
        // pernah merender isi tersimpan, sehingga skripnya sendiri tetap boleh jalan.
        return response($view->render(), 200, [
            'Content-Security-Policy' => implode('; ', [
                "default-src 'none'",
                "img-src 'self' data:",
                "style-src 'unsafe-inline'",   // seluruh gaya cetak inline di halaman
                "font-src data:",
                // Bukan 'none' saat cetak langsung, melainkan sidik jari SATU skrip
                // yang kita tulis sendiri. Skrip suntikan tidak akan cocok sidiknya,
                // jadi tetap tak bisa jalan — sementara dialog cetak tetap terbuka.
                $cetakLangsung
                    ? "script-src 'sha256-".base64_encode(hash('sha256', self::SKRIP_CETAK, true))."'"
                    : "script-src 'none'",
                "base-uri 'none'",
                "form-action 'none'",
            ]),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Simpan isi halaman SPR Bank saat tombol Cetak ditekan, lalu catat cetakannya.
     *
     * Dipanggil dari halaman itu sendiri sesaat sebelum dialog cetak terbuka.
     * Pencatatan dipindah ke sini — bukan saat halaman dibuka — supaya "sudah
     * dicetak" berarti seseorang benar-benar menekan Cetak, bukan sekadar membuka
     * lalu menutupnya kembali.
     */
    public function simpanSprBank(Request $request, NupRegistration $registration): JsonResponse
    {
        // Penjaga yang sama dengan tombol "Cetak SPR Bank" di Verifikasi Data.
        // Tanpa ini, tanda tangan yang sah untuk satu pendaftaran tetap bisa dipakai
        // menyimpan lembar bagi pendaftaran yang belum layak diserahkan ke bank.
        abort_unless($registration->verif_status === 'APPROVED', 403);

        $data = $request->validate(['isi' => ['required', 'string', 'max:2000000']]);
        $kini = now();

        $catatan = SprBankPrint::firstOrNew(['nup_registration_id' => $registration->id]);
        $catatan->no_surat = $catatan->no_surat ?: (string) $request->input('no_surat', '-');
        $catatan->isi_html = self::bersihkanHtml($data['isi']);
        $catatan->jumlah_cetak = ($catatan->exists ? $catatan->jumlah_cetak : 0) + 1;
        $catatan->pertama_cetak_at ??= $kini;
        $catatan->terakhir_cetak_at = $kini;
        $catatan->terakhir_cetak_by = Auth::id();
        $catatan->save();

        return response()->json(['ok' => true, 'jumlah_cetak' => $catatan->jumlah_cetak]);
    }

    /**
     * Buang unsur yang bisa mengeksekusi kode dari HTML kiriman halaman.
     *
     * Isinya datang dari peramban dan ditampilkan kembali kepada petugas lain, jadi
     * tanpa ini satu orang bisa menitipkan skrip yang berjalan di sesi rekannya.
     * Ini penyaring kasar — cukup untuk menutup jalur yang jelas pada perkakas
     * internal — bukan pembersih HTML yang lengkap.
     */
    /**
     * Skrip pembuka dialog cetak pada mode "cetak langsung". Isinya harus PERSIS
     * sama dengan yang dirender Blade — CSP mengizinkannya lewat sidik jari
     * SHA-256, jadi satu spasi berbeda saja membuatnya diblokir.
     */
    private const SKRIP_CETAK = 'window.print();';

    private static function bersihkanHtml(string $html): string
    {
        // 1) Buang elemen yang bisa mengeksekusi atau memuat sesuatu, beserta isinya.
        $html = preg_replace('#<\s*(script|iframe|object|embed|link|meta)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', (string) $html);
        // 2) Buang bentuk tunggalnya (tanpa penutup).
        $html = preg_replace('#<\s*(script|iframe|object|embed|link|meta)\b[^>]*/?>#i', '', (string) $html);
        // 3) Buang penangan kejadian: onclick, onerror, onload, dan sejenisnya.
        $html = preg_replace('#\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', (string) $html);
        // 4) Buang tautan berskema javascript:.
        $html = preg_replace('#\s(?:href|src)\s*=\s*(?:"javascript:[^"]*"|\'javascript:[^\']*\')#i', '', (string) $html);

        return (string) $html;
    }

    public function sprsPdf(NupRegistration $registration, string $hash): mixed
    {
        abort_unless(hash_equals(self::sprsPdfHash($registration->id), $hash), 403);

        // Dilayani dari berkas jadi, BUKAN dirender saat diminta. Render Chromium
        // memakan ~4,6 detik tiap permintaan, sementara pengunduh media WhatsApp
        // menyerah di sekitar 5 detik — itulah "Media upload error" yang muncul
        // kadang-kadang di log Qontak. Content-Length juga wajib: sebagian
        // pengunduh menolak media yang panjangnya tak disebutkan.
        $isi = self::pdfSprsTersimpan($registration);

        return new Response($isi, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($isi),
            'Content-Disposition' => 'inline; filename="'.self::namaBerkasSprs($registration).'.pdf"',
        ]);
    }

    /**
     * Isi PDF SPRS, dirender sekali lalu disimpan.
     *
     * Kunci berkasnya memuat `updated_at` registrasi, jadi suntingan SPRS
     * (mis. ubah email/telepon) otomatis menghasilkan berkas baru — tidak ada
     * cache basi yang harus dihapus manual.
     */
    public static function pdfSprsTersimpan(NupRegistration $registration): string
    {
        $jalur = 'sprs-cache/'.$registration->id.'-'
            .(optional($registration->updated_at)->timestamp ?: 0).'.pdf';

        $disk = Storage::disk('local');
        if ($disk->exists($jalur)) {
            return (string) $disk->get($jalur);
        }

        $html = view('pdf.fo.sprs', self::sprsData($registration))->render();
        $pdf = Browsershot::html($html)->noSandbox()
            ->addChromiumArguments(['disable-dev-shm-usage'])->format('A4')->pdf();

        $disk->put($jalur, $pdf);

        return $pdf;
    }

    /**
     * Cetak SPRS dari Kontrak (tahap SPRS) — dipakai tombol "Cetak SPRS" di daftar SPRS.
     * Cari NupRegistration terkait via reservasi; bila tak ada (SPRS dibuat manual),
     * bentuk objek registrasi sementara dari kontrak agar template tetap lengkap.
     */
    public function sprsFromKontrak(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks']);
        $reg = NupRegistration::query()->where('reservasi_id', $kontrak->reservasi_id)->orderByDesc('id')->first();
        if ($reg) {
            $reg->load(['customer', 'agent', 'event']);
        } else {
            $reg = $this->regDariKontrak($kontrak);
        }

        return $this->render('pdf.fo.sprs', self::sprsViewData($reg, $kontrak), 'SPRS-'.$kontrak->no_kontrak);
    }

    /**
     * @param  array<string,mixed>  ...
     * @return array<string,mixed>
     */
    /**
     * Nama berkas SPRS: "SPRS-00013" — kata "NUP" dibuang supaya tidak jadi
     * "SPRS-NUP-00013". Nomor aslinya di database tetap utuh.
     */
    public static function namaBerkasSprs(NupRegistration $reg): string
    {
        $nomor = trim(preg_replace('/\bNUP-?\s*/i', '', (string) ($reg->no_nup ?: $reg->id)));

        return 'SPRS-'.($nomor !== '' ? $nomor : $reg->id);
    }

    private static function sprsViewData(NupRegistration $reg, ?Kontrak $kontrak): array
    {
        $tts = $kontrak
            ? Tts::query()->where('kontrak_id', $kontrak->id)
                ->where('jenis_pembayaran', 'BOOKING')->orderBy('id')->first()
            : null;

        // QR Customer ikut tercetak di SPRS → customer cukup menunjukkan lembar ini
        // saat Aktivasi NUP & Pilih Unit (tak perlu kartu terpisah). Isi QR = KODE
        // customer, bukan data pribadi. ensureCode() menerbitkan kode bila kosong.
        $qrSvg = null;
        $custCode = null;
        if ($cust = $reg->customer) {
            $qr = app(CustomerQrService::class);
            $custCode = $qr->ensureCode($cust);          // untuk dibaca manusia
            $qrSvg = $qr->svg($qr->payload($cust), 200); // isi QR: "CUST:<kode>"
        }

        return ['reg' => $reg, 'kontrak' => $kontrak, 'tts' => $tts, 'qrSvg' => $qrSvg, 'custCode' => $custCode];
    }

    /** Objek NupRegistration sementara (tak disimpan) dari Kontrak untuk render SPRS. */
    private function regDariKontrak(Kontrak $kontrak): NupRegistration
    {
        $reg = new NupRegistration;
        $reg->customer_id = $kontrak->customer_id;
        $reg->nama_pendaftar = $kontrak->customer?->full_name;
        $reg->no_nup = $kontrak->no_kontrak;
        $reg->nik = $kontrak->customer?->nik;
        $reg->phone = $kontrak->customer?->mobile;
        $reg->tipe_nup = $kontrak->unit?->unitType?->name ?: 'RUMAH';
        $reg->cara_bayar = $kontrak->cara_bayar;
        $reg->agent_id = $kontrak->agent_id;
        $reg->ref_sales_name = $kontrak->ref_sales_name;
        $reg->ref_sales_phone = $kontrak->ref_sales_phone;
        $reg->keterangan = $kontrak->keterangan;
        $reg->setRelation('customer', $kontrak->customer);
        $reg->setRelation('agent', $kontrak->agent);

        return $reg;
    }

    public function kwitansi(Bkm $bkm): mixed
    {
        $bkm->load(['customer', 'kontrak.unit', 'tts']);

        return $this->render('pdf.fo.kwitansi', [
            'bkm' => $bkm,
            'terbilang' => Terbilang::rupiah((float) $bkm->amount),
        ], 'KWITANSI-'.$bkm->no_bkm);
    }

    public function jadwal(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit', 'skema.lines']);
        $harga = (float) $kontrak->harga_jual;

        $rows = [];
        foreach ($kontrak->skema?->lines ?? [] as $line) {
            $amount = $line->basis === 'NOMINAL'
                ? (float) $line->nominal
                : round($harga * (float) $line->persen / 100, 2);
            $jatuh = $kontrak->tgl_kontrak
                ? $kontrak->tgl_kontrak->copy()->addDays((int) $line->jatuh_tempo_hari)
                : null;
            $rows[] = [
                'nama' => $line->nama_termin,
                'basis' => $line->basis,
                'persen' => $line->persen,
                'amount' => $amount,
                'jatuh' => $jatuh,
            ];
        }

        return $this->render('pdf.fo.jadwal', ['kontrak' => $kontrak, 'rows' => $rows], 'JADWAL-'.$kontrak->no_kontrak);
    }

    public function fakturPenjualan(Fp $fp): mixed
    {
        $fp->load(['kontrak.customer', 'kontrak.unit.unitType', 'kontrak.unit.lokasi', 'kontrak.agent', 'kontrak.skema', 'kontrak.tagihan']);

        return $this->render('pdf.fo.faktur-penjualan', ['fp' => $fp], 'FAKTUR-'.$fp->no_fp);
    }

    /** Cetak Surat Peringatan (SP) tercetak — snapshot dari fo_sp_letters. */
    public function sp(SpLetter $sp): mixed
    {
        $sp->load(['kontrak.customer', 'kontrak.unit.unitType', 'kontrak.unit.lokasi']);

        return $this->render('pdf.fo.sp', [
            'sp' => $sp,
            'settings' => app(CollectionSettings::class),
        ], str_replace('/', '-', (string) ($sp->no_surat ?: 'SP-'.$sp->id)));
    }

    /**
     * Memo Rekom Batal Cash — dokumen 2 halaman (Memo Internal + lampiran daftar SPR).
     *
     * Sepadan dengan memo rekom batal di modul KPR (Back Office), tetapi berdiri sendiri di
     * sini: memo BO adalah halaman Inertia/Vue di aplikasi lain, tidak terjangkau panel
     * Filament ini, dan SPR cash tidak punya baris apa pun di modul KPR untuk dirujuk.
     *
     * TIDAK menulis apa pun. Penandanya sudah dicatat layar Rekom Batal Cash sebelum operator
     * diantar ke sini, jadi memuat ulang halaman ini aman — persis pemisahan GET/POST yang
     * dipakai memo KPR di Back Office.
     *
     * Daftar barisnya disaring ulang lewat RekomBatalCashService::daftar(), bukan sekadar
     * whereIn id: tanpa itu siapa pun bisa mencetak memo untuk kontrak KPR atau kontrak yang
     * sudah batal hanya dengan menyusun ?ids= sendiri di alamat.
     */
    public function rekomBatalCash(Request $request): mixed
    {
        $ids = collect(explode(',', (string) $request->query('ids')))
            ->map(fn ($v) => (int) trim((string) $v))->filter()->unique()->values();

        abort_if($ids->isEmpty(), 404, 'Pilih minimal satu SPR untuk dibuatkan memo rekom batal.');

        $rows = RekomBatalCashService::daftar()
            ->with(['customer', 'unit.unitType', 'unit.lokasi', 'unit.tahapMaster', 'agent', 'skema'])
            ->whereKey($ids)
            ->orderBy('no_kontrak')
            ->get();

        abort_if($rows->isEmpty(), 404, 'Tidak ada SPR cash yang cocok dengan pilihan itu.');

        $now = now();
        $roman = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$now->month];

        return $this->render('pdf.fo.rekom-batal-cash', [
            'rows' => $rows,
            'jumlah' => $rows->count(),
            'jumlahKata' => $this->terbilangJumlah($rows->count()),
            'memo' => [
                'no' => '____/MUP-CASH/JRK/'.$roman.'/'.$now->year,
                'kepada' => 'RS',
                'tembusan' => 'RZ',
                'dari' => 'PS',
                'tanggal' => ucfirst($now->translatedFormat('l, d F Y')),
                'perihal' => 'Rekomendasi Pembatalan SPR Cash',
            ],
            'listHeading' => 'List Rekomendasi Pembatalan SPR Cash',
            'listDate' => ucfirst($now->translatedFormat('d F Y')),
            'settings' => app(CollectionSettings::class),
        ], 'Memo-Rekom-Batal-Cash-'.$now->format('Ymd-Hi'));
    }

    /**
     * "3" → "tiga" untuk kalimat "3 (tiga) user terlampir".
     *
     * Memakai ulang Terbilang, bukan menulis pengubah angka→kata kedua: dua salinan berarti
     * dua aturan "sebelas/seribu" yang bisa berbeda diam-diam. Akhiran " rupiah"-nya dibuang
     * karena words() sendiri privat.
     */
    private function terbilangJumlah(int $n): string
    {
        return lcfirst(preg_replace('/\s*rupiah$/i', '', Terbilang::rupiah((float) $n)) ?: (string) $n);
    }

    /** @param  array<string,mixed>  $data */
    private function render(string $view, array $data, string $filename): mixed
    {
        if (request()->boolean('pdf') && class_exists(Browsershot::class)) {
            $html = view($view, $data)->render();
            $pdf = Browsershot::html($html)->noSandbox()->addChromiumArguments(['disable-dev-shm-usage'])->format('A4')->pdf();

            return new Response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'.pdf"',
            ]);
        }

        return view($view, $data);
    }
}
