<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Models\KprCalculatorSetting;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kalkulator KPR & pengaturannya.
 *
 * Dua layar, SATU sumber data (kpr_calculator_settings.config):
 *   - KPR → Set Up → Kategori Perhitungan KPR   (mengubah; izin bankloan.setup.*)
 *   - KPR → Query/Report → Kalkulator KPR       (membaca; izin bankloan.application.view)
 *
 * Bentuk dokumennya dipertahankan sama persis dengan aplikasi Kalkulator KPR Java Residence
 * supaya mesin hitungnya (resources/js/Pages/BankLoan/Kalkulator/kprCalc.ts) bisa dipakai
 * apa adanya dan angkanya tetap sama.
 */
class KprCalculatorController extends Controller
{
    /** Folder berkas gambar yang diunggah lewat panel (di luar public, disajikan lewat rute). */
    private const FOLDER_UNGGAHAN = 'kpr-calculator';

    /** Awalan nilai kolom gambar untuk berkas hasil unggahan. */
    private const AWALAN_UNGGAHAN = 'unggahan/';

    /** Gambar bawaan yang ikut repo dan disajikan langsung oleh web server. */
    private const AWALAN_BAWAAN = '/img/kpr-calculator/';

    private const TIPE = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
    ];

    // ── Layar kalkulator (Query/Report) ────────────────────────────────────────────────

    public function kalkulator(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        return Inertia::render('BankLoan/Kalkulator/Index', [
            'config' => $this->configUntukLayar(),
        ]);
    }

    // ── Layar pengaturan (Set Up) ──────────────────────────────────────────────────────

    public function setup(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.setup.view'), 403);

        return Inertia::render('BankLoan/Setup/PerhitunganKpr/Index', [
            'config'     => $this->configUntukLayar(),
            'bisaUbah'   => (bool) $request->user()?->can('bankloan.setup.manage'),
            'terakhir'   => optional($this->baris()->updated_at)->toDateTimeString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $bersih = $this->validasi($request);

        $baris = $this->baris();
        // Hash kata sandi admin aplikasi lama TIDAK dipakai di sini (Back Office punya login &
        // izinnya sendiri) tetapi tetap dibawa apa adanya, supaya berkas hasil "Unduh config.js"
        // masih bisa dipakai oleh aplikasi aslinya.
        $lama = is_array($baris->config) ? $baris->config : [];
        $bersih['adminPasswordHash'] = (string) ($lama['adminPasswordHash'] ?? '');
        $bersih['lastUpdated'] = now()->toIso8601String();

        $baris->config = $bersih;
        $baris->save();

        return back()->with('success', 'Pengaturan perhitungan KPR disimpan.');
    }

    /**
     * Unggah satu gambar (logo bank, logo brand, gambar promo, foto intro).
     *
     * Memulangkan JSON, bukan redirect: layar pengaturan menyimpan seluruh dokumen config
     * sekaligus saat tombol Simpan ditekan, jadi unggahan harus bisa menyisipkan URL barunya
     * ke dalam form yang sedang diisi TANPA memuat ulang halaman — memuat ulang akan
     * membuang perubahan lain yang belum tersimpan.
     */
    public function unggahGambar(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $request->validate([
            // 4 MB: logo & gambar promo terbesar pada data bawaan 451 KB, jadi batas ini longgar
            // tanpa membuka peluang berkas raksasa masuk ke halaman kalkulator.
            'gambar' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:4096'],
        ], [
            'gambar.max'   => 'Ukuran gambar maksimal 4 MB.',
            'gambar.mimes' => 'Format gambar harus PNG, JPG, WEBP, atau GIF.',
        ]);

        $berkas = $request->file('gambar');
        $ext = strtolower($berkas->getClientOriginalExtension() ?: 'png');
        $nama = bin2hex(random_bytes(16)).'.'.$ext;
        $berkas->storeAs(self::FOLDER_UNGGAHAN, $nama, 'local');

        return response()->json([
            'nilai' => self::AWALAN_UNGGAHAN.$nama,
            'url'   => route('kpr.kalkulator.gambar', ['berkas' => $nama]),
        ]);
    }

    /** Sajikan gambar hasil unggahan. Tanpa rute ini berkas di storage/ tidak terjangkau
     *  browser — dan aplikasi ini tidak memakai symlink public/storage (memang tidak ada). */
    public function gambar(Request $request, string $berkas): StreamedResponse|HttpResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view')
            || $request->user()?->can('bankloan.setup.view'), 403);

        // Hanya nama berkas polos: tanpa ini '..%2F..' bisa membaca berkas lain di server.
        abort_unless(preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $berkas) === 1, 404);

        $path = self::FOLDER_UNGGAHAN.'/'.$berkas;
        abort_unless(Storage::disk('local')->exists($path), 404);

        $ext = strtolower(pathinfo($berkas, PATHINFO_EXTENSION));

        return Storage::disk('local')->response($path, $berkas, [
            'Content-Type'  => self::TIPE[$ext] ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Unduh config.js — bentuk yang sama dengan aplikasi aslinya, untuk cadangan/ekspor. */
    public function ekspor(Request $request): HttpResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.view'), 403);

        $cfg = $this->baris()->config ?: [];
        $isi = "/* ============================================================\n"
            ."   PENGATURAN KPR — per bank: plafon, suku bunga, tenor, syarat.\n"
            ."   Dihasilkan otomatis dari Back Office pada ".now()->format('d/m/Y H:i:s').".\n"
            ."   ============================================================ */\n\n"
            .'const KPR_CONFIG = '.json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).";\n";

        return response($isi, 200, [
            'Content-Type'        => 'text/javascript; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="config.js"',
        ]);
    }

    // ── Internal ───────────────────────────────────────────────────────────────────────

    private function baris(): KprCalculatorSetting
    {
        return KprCalculatorSetting::query()->firstOrCreate([], ['config' => ['banks' => []]]);
    }

    /** Dokumen config untuk layar: kolom gambar diubah jadi URL, hash kata sandi DIBUANG. */
    private function configUntukLayar(): array
    {
        $cfg = $this->baris()->config ?: [];
        unset($cfg['adminPasswordHash']);

        $cfg['brandLogo'] = $this->urlGambar($cfg['brandLogo'] ?? '');
        $cfg['introPhoto'] = $this->urlGambar($cfg['introPhoto'] ?? '');
        $cfg['introPhotoMobile'] = $this->urlGambar($cfg['introPhotoMobile'] ?? '');
        $cfg['banks'] = array_values(array_map(function (array $b): array {
            $b['logoImage'] = $this->urlGambar($b['logoImage'] ?? '');
            if (isset($b['promo']) && is_array($b['promo'])) {
                $b['promo']['slide2Image'] = $this->urlGambar($b['promo']['slide2Image'] ?? '');
            }

            return $b;
        }, $cfg['banks'] ?? []));

        return $cfg;
    }

    /**
     * Pola nama berkas yang diterima: satu ruas nama + ekstensi, tanpa garis miring.
     *
     * Memeriksa AWALANNYA saja tidak cukup — "/img/kpr-calculator/../../berkas-lain" juga
     * berawalan benar, dan peramban akan menormalkannya keluar dari folder yang dimaksud.
     */
    private const POLA_BERKAS = '[A-Za-z0-9_-]+\.[A-Za-z0-9]{1,5}';

    /** Nilai kolom gambar → URL yang bisa dibuka browser. */
    private function urlGambar(mixed $nilai): string
    {
        $nilai = (string) $nilai;
        if ($nilai === '') {
            return '';
        }
        if (preg_match('#^'.preg_quote(self::AWALAN_UNGGAHAN, '#').'('.self::POLA_BERKAS.')$#', $nilai, $m) === 1) {
            return route('kpr.kalkulator.gambar', ['berkas' => $m[1]]);
        }
        if (preg_match('#^'.preg_quote(self::AWALAN_BAWAAN, '#').self::POLA_BERKAS.'$#', $nilai) === 1) {
            return $nilai;
        }

        return '';   // nilai asing tidak pernah sampai ke atribut src
    }

    /** URL → nilai kolom gambar (kebalikan urlGambar); dipakai saat layar mengirim balik config. */
    private function nilaiGambar(mixed $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^'.preg_quote(self::AWALAN_UNGGAHAN, '#').self::POLA_BERKAS.'$#', $url) === 1) {
            return $url;
        }
        if (preg_match('#^'.preg_quote(self::AWALAN_BAWAAN, '#').self::POLA_BERKAS.'$#', $url) === 1) {
            return $url;
        }
        // URL hasil route() untuk berkas unggahan → kembalikan ke bentuk simpanannya.
        if (preg_match('#/kpr/kalkulator/gambar/('.self::POLA_BERKAS.')$#', $url, $m) === 1) {
            return self::AWALAN_UNGGAHAN.$m[1];
        }
        // URL absolut ke gambar bawaan (mis. https://host/img/kpr-calculator/x.png).
        if (preg_match('#^https?://[^/]+('.preg_quote(self::AWALAN_BAWAAN, '#').self::POLA_BERKAS.')$#', $url, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /**
     * Validasi + susun ulang dokumen config dari nilai yang lolos validasi.
     *
     * Disusun ULANG, bukan disimpan apa adanya: dokumen ini dirender ke layar (termasuk ke
     * atribut src gambar) dan diumpankan ke mesin hitung. Menyimpan apa pun yang dikirim
     * peramban berarti mempercayai isinya.
     */
    private function validasi(Request $request): array
    {
        $v = $request->validate([
            'effectiveMonth'            => ['nullable', 'string', 'max:60'],
            'brandLogo'                 => ['nullable', 'string', 'max:255'],
            'introPhoto'                => ['nullable', 'string', 'max:255'],
            'introPhotoMobile'          => ['nullable', 'string', 'max:255'],

            'banks'                     => ['required', 'array', 'min:1'],
            'banks.*.id'                => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'banks.*.name'              => ['required', 'string', 'max:64'],
            'banks.*.fullName'          => ['nullable', 'string', 'max:150'],
            'banks.*.logoImage'         => ['nullable', 'string', 'max:255'],
            'banks.*.plafon.min'        => ['required', 'numeric', 'min:0', 'max:1000000000000'],
            'banks.*.plafon.max'        => ['required', 'numeric', 'min:0', 'max:1000000000000'],
            'banks.*.tenor.minYears'    => ['required', 'integer', 'min:1', 'max:50'],
            'banks.*.tenor.maxYears'    => ['required', 'integer', 'min:1', 'max:50'],
            'banks.*.dpDefaultPercent'  => ['required', 'numeric', 'min:0', 'max:90'],
            'banks.*.dpMinPercent'      => ['nullable', 'numeric', 'min:0', 'max:90'],
            'banks.*.utjAmount'         => ['nullable', 'numeric', 'min:0'],
            'banks.*.maxInstallmentRatio' => ['required', 'numeric', 'min:0', 'max:1'],

            'banks.*.promo.enabled'     => ['nullable', 'boolean'],
            'banks.*.promo.slide1Text'  => ['nullable', 'string', 'max:5000'],
            'banks.*.promo.slide2Text'  => ['nullable', 'string', 'max:5000'],
            'banks.*.promo.slide2Image' => ['nullable', 'string', 'max:255'],

            'banks.*.schemes'                     => ['required', 'array', 'min:1'],
            'banks.*.schemes.*.id'                => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'banks.*.schemes.*.type'              => ['required', 'in:fixed_floating,tiered,anuitas,efektif'],
            'banks.*.schemes.*.label'             => ['required', 'string', 'max:120'],
            'banks.*.schemes.*.minTenorYears'     => ['nullable', 'integer', 'min:0', 'max:50'],
            'banks.*.schemes.*.fixedYears'        => ['nullable', 'numeric', 'min:0', 'max:50'],
            'banks.*.schemes.*.fixedRatePercent'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'banks.*.schemes.*.floatingRatePercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'banks.*.schemes.*.ratePercent'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'banks.*.schemes.*.tiers'             => ['nullable', 'array', 'max:12'],
            'banks.*.schemes.*.tiers.*.years'     => ['required', 'numeric', 'min:0', 'max:50'],
            'banks.*.schemes.*.tiers.*.ratePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'banks.*.schemes.*.tiers.*.tierLabel' => ['nullable', 'string', 'max:40'],
        ], [
            'banks.required'            => 'Minimal harus ada satu bank.',
            'banks.*.schemes.required'  => 'Setiap bank harus punya minimal satu skema bunga.',
            'banks.*.id.regex'          => 'Kode bank hanya boleh huruf, angka, garis bawah, dan strip.',
        ]);

        $bank = [];
        foreach ($v['banks'] as $b) {
            $skema = [];
            foreach ($b['schemes'] as $s) {
                $satu = [
                    'id'            => $s['id'],
                    'type'          => $s['type'],
                    'label'         => trim($s['label']),
                    'minTenorYears' => (int) ($s['minTenorYears'] ?? 0),
                ];
                if ($s['type'] === 'fixed_floating') {
                    $satu['fixedYears'] = (float) ($s['fixedYears'] ?? 0);
                    $satu['fixedRatePercent'] = (float) ($s['fixedRatePercent'] ?? 0);
                    $satu['floatingRatePercent'] = (float) ($s['floatingRatePercent'] ?? 0);
                } elseif ($s['type'] === 'tiered') {
                    $satu['tiers'] = array_values(array_map(fn ($t) => [
                        'years'      => (float) $t['years'],
                        'ratePercent' => (float) $t['ratePercent'],
                        'tierLabel'  => trim((string) ($t['tierLabel'] ?? '')),
                    ], $s['tiers'] ?? []));
                    $satu['floatingRatePercent'] = (float) ($s['floatingRatePercent'] ?? 0);
                } else {   // anuitas & efektif
                    $satu['ratePercent'] = (float) ($s['ratePercent'] ?? 0);
                }
                $skema[] = $satu;
            }

            $bank[] = [
                'id'        => $b['id'],
                'name'      => trim($b['name']),
                'fullName'  => trim((string) ($b['fullName'] ?? '')),
                'logoImage' => $this->nilaiGambar($b['logoImage'] ?? ''),
                'promo'     => [
                    'enabled'     => (bool) ($b['promo']['enabled'] ?? false),
                    'slide1Text'  => trim((string) ($b['promo']['slide1Text'] ?? '')),
                    'slide2Text'  => trim((string) ($b['promo']['slide2Text'] ?? '')),
                    'slide2Image' => $this->nilaiGambar($b['promo']['slide2Image'] ?? ''),
                ],
                'plafon'    => ['min' => (float) $b['plafon']['min'], 'max' => (float) $b['plafon']['max']],
                'dpMinPercent' => (float) ($b['dpMinPercent'] ?? 0),
                'dpDefaultPercent' => (float) $b['dpDefaultPercent'],
                'utjAmount' => (float) ($b['utjAmount'] ?? 0),
                'tenor'     => ['minYears' => (int) $b['tenor']['minYears'], 'maxYears' => (int) $b['tenor']['maxYears']],
                'maxInstallmentRatio' => (float) $b['maxInstallmentRatio'],
                'schemes'   => $skema,
            ];
        }

        return [
            'banks'            => $bank,
            'brandLogo'        => $this->nilaiGambar($v['brandLogo'] ?? ''),
            'effectiveMonth'   => trim((string) ($v['effectiveMonth'] ?? '')),
            'introPhoto'       => $this->nilaiGambar($v['introPhoto'] ?? ''),
            'introPhotoMobile' => $this->nilaiGambar($v['introPhotoMobile'] ?? ''),
        ];
    }
}
