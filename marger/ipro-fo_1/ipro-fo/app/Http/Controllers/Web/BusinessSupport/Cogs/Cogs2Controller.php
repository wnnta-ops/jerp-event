<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsCogs2Actual;
use App\Domain\BusinessSupport\Models\BsCogs2HousingTipe;
use App\Domain\BusinessSupport\Models\BsCogs2Kategori;
use App\Domain\BusinessSupport\Models\BsCogs2Periode;
use App\Domain\BusinessSupport\Models\BsCogs2Unit;
use App\Domain\BusinessSupport\Models\BsCogs2Akun;
use App\Domain\BusinessSupport\Services\Cogs2Kalkulator;
use App\Domain\BusinessSupport\Services\Cogs2Sumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * COGS Baru — Perhitungan COGS, Set Up Kategori, Actual Costing, dan Rincian COGS.
 *
 * Keempat layar membaca dari SATU pemanggilan Cogs2Kalkulator atas periode yang sama, sehingga
 * tidak pernah ada dua jawaban untuk "HPP unit ini berapa". Menghitung ulang di tiap layar
 * adalah cara termudah membuat empat layar berbeda beberapa rupiah tanpa ada yang tahu mana
 * yang benar.
 *
 * Izin: baca cogs.budget.view, tulis businesssupport.setup.manage — pasangan yang sudah dipakai
 * seluruh layar Setup COGS. Sengaja TIDAK membuat izin baru: izin baru di pemasangan ini lahir
 * yatim dan menunya hilang dari sidebar tanpa satu pun galat.
 */
class Cogs2Controller extends Controller
{
    /** Slug alamat → kunci komponen. Keduanya sengaja sama supaya tidak ada terjemahan yang terlupa. */
    private const KOMPONEN = ['tanah', 'housing', 'infra', 'license'];

    public function __construct(
        private readonly Cogs2Kalkulator $kalkulator,
        private readonly Cogs2Sumber $sumber,
    ) {}

    // ── Daftar periode ──────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('cogs.budget.view'), 403);

        $rows = BsCogs2Periode::withCount('unit')->orderBy('lokasi_kode')->orderBy('tahap')->get()
            ->map(function (BsCogs2Periode $p) {
                $budget = (float) BsCogs2Kategori::where('periode_id', $p->id)->sum('nominal');
                $aktual = (float) BsCogs2Actual::where('periode_id', $p->id)
                    ->selectRaw('COALESCE(SUM(penambahan - pengurangan), 0) v')->value('v');

                return [
                    'id'          => $p->id,
                    'lokasi'      => trim((string) (($p->lokasi_kode ? $p->lokasi_kode.' — ' : '').$p->lokasi_nama)),
                    'lokasi_id'   => $p->lokasi_id,
                    'tahap'       => $p->tahap,
                    'luas_lahan'  => (float) $p->luas_lahan,
                    'saleable'    => (float) $p->saleable,
                    'luas_bangunan' => (float) $p->luas_bangunan,
                    'unit'        => $p->unit_count,
                    'budget'      => round($budget, 2),
                    'aktual'      => round($aktual, 2),
                ];
            })->values();

        [$lokasi, $galatFo] = $this->lokasiFo();

        return Inertia::render('BusinessSupport/Cogs2/Index', [
            'rows'     => $rows,
            'lokasiFo' => $lokasi,
            'tahapFo'  => $this->tahapFo(),
            'galatFo'  => $galatFo,
            'bisaUbah' => (bool) $request->user()?->can('businesssupport.setup.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'lokasi_id' => ['required', 'integer', 'min:1'],
            'tahap'     => ['present', 'nullable', 'string', 'max:32'],
        ]);
        $tahap = trim((string) ($v['tahap'] ?? ''));

        if (BsCogs2Periode::where('lokasi_id', (int) $v['lokasi_id'])->where('tahap', $tahap)->exists()) {
            return back()->withErrors(['lokasi_id' => 'Periode COGS Baru untuk cakupan itu sudah ada.']);
        }

        [$lokasi] = $this->lokasiFo();
        $pilih = collect($lokasi)->firstWhere('id', (int) $v['lokasi_id']);

        $p = BsCogs2Periode::create([
            'tenant_id'   => tenant_id(),
            'lokasi_id'   => (int) $v['lokasi_id'],
            'lokasi_kode' => $pilih['kode'] ?? null,
            'lokasi_nama' => $pilih['nama'] ?? null,
            'tahap'       => $tahap,
            'created_by'  => $request->user()?->id,
            'updated_by'  => $request->user()?->id,
        ]);

        return redirect()->route('bs.cogs2.perhitungan', $p->id)->with('success', 'Periode COGS Baru dibuat.');
    }

    /** Kunci sesi: periode COGS Baru yang TERAKHIR DIBUKA orang ini. */
    private const SESI_PERIODE = 'cogs2.periode';

    /**
     * Pintu dari sidebar ke layar COGS Baru.
     *
     * Butir menu tidak bisa tahu periode mana yang sedang dikerjakan, sedangkan seluruh layar
     * COGS Baru berbutir periode.
     *
     * Sejak keempat sub-modul berdiri sebagai layar yang TERPISAH, sidebar adalah jalan utama
     * berpindah di antara keempatnya — jadi pintu ini wajib mendarat di periode yang SAMA dengan
     * yang barusan dibuka. Karena itu yang diingat adalah periode yang TERAKHIR DIBUKA (disimpan
     * di sesi oleh props()), bukan yang terakhir disunting: "terakhir disunting" berpindah begitu
     * orang lain menyimpan periode lain, dan berpindah periode diam-diam di tengah pekerjaan
     * berarti angka masuk ke lokasi yang salah tanpa satu pun galat.
     *
     * Bila periode yang diingat sudah dihapus — atau milik tenant lain, yang tidak akan pernah
     * terbaca karena model ini bercakupan tenant — ia jatuh kembali ke yang terakhir disunting.
     * Bila belum ada periode sama sekali, ia mendarat di daftar periode dengan keterangan, bukan
     * 404 yang membuat menunya terlihat rusak.
     */
    public function buka(Request $request, string $layar, ?string $komponen = null): RedirectResponse
    {
        abort_unless($request->user()?->can('cogs.budget.view'), 403);
        abort_unless(in_array($layar, ['perhitungan', 'kategori', 'actual', 'rincian'], true), 404);

        $ingat = $request->hasSession() ? (int) $request->session()->get(self::SESI_PERIODE, 0) : 0;

        $periode = ($ingat > 0 ? BsCogs2Periode::find($ingat) : null)
            ?? BsCogs2Periode::orderByDesc('updated_at')->orderByDesc('id')->first();

        if ($periode === null) {
            return redirect()->route('bs.cogs2.index')
                ->with('info', 'Belum ada periode COGS Baru — buat satu lebih dulu untuk membuka layar itu.');
        }

        if ($layar === 'kategori' || $layar === 'actual') {
            $komponen = in_array((string) $komponen, self::KOMPONEN, true) ? (string) $komponen : 'tanah';

            return redirect()->route('bs.cogs2.'.$layar, [$periode->id, $komponen]);
        }

        return redirect()->route('bs.cogs2.'.$layar, $periode->id);
    }

    public function destroy(Request $request, BsCogs2Periode $periode): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        DB::transaction(function () use ($periode) {
            BsCogs2Unit::where('periode_id', $periode->id)->delete();
            BsCogs2Actual::where('periode_id', $periode->id)->delete();
            BsCogs2Kategori::where('periode_id', $periode->id)->delete();
            BsCogs2HousingTipe::where('periode_id', $periode->id)->delete();
            $periode->delete();
        });

        return redirect()->route('bs.cogs2.index')->with('success', 'Periode COGS Baru dihapus.');
    }

    // ── 1. Perhitungan COGS ─────────────────────────────────────────────────────────

    public function perhitungan(Request $request, BsCogs2Periode $periode): Response
    {
        abort_unless($request->user()?->can('cogs.budget.view'), 403);

        return Inertia::render('BusinessSupport/Cogs2/Perhitungan', $this->props($request, $periode));
    }

    public function simpanPerhitungan(Request $request, BsCogs2Periode $periode): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'luas_lahan'    => ['required', 'numeric', 'min:0'],
            'saleable'      => ['required', 'numeric', 'min:0'],
            'rth'           => ['required', 'numeric', 'min:0'],
            'luas_bangunan' => ['required', 'numeric', 'min:0'],
            'jumlah_unit'   => ['nullable', 'integer', 'min:0'],
            'catatan'       => ['nullable', 'string', 'max:2000'],
        ]);

        // Jalan adalah SISA (Lahan − Saleable − RTH). Sisa yang negatif berarti angka lahannya
        // mustahil — ditolak di sini, bukan dibiarkan tampil merah: tarif per m2 yang lahir dari
        // lahan yang tidak konsisten akan terlihat sepenuhnya wajar.
        if ((float) $v['saleable'] + (float) $v['rth'] > (float) $v['luas_lahan'] + 0.005) {
            return back()->withErrors(['saleable' => 'Saleable + RTH melebihi Luas Lahan, sehingga Jalan menjadi negatif.']);
        }

        $periode->update($v + ['updated_by' => $request->user()?->id]);

        return back()->with('success', 'Perhitungan COGS disimpan.');
    }

    // ── 2. Set Up Kategori COGS ─────────────────────────────────────────────────────

    public function kategori(Request $request, BsCogs2Periode $periode, string $komponen): Response
    {
        abort_unless($request->user()?->can('cogs.budget.view'), 403);
        abort_unless(in_array($komponen, self::KOMPONEN, true), 404);

        return Inertia::render('BusinessSupport/Cogs2/Kategori', $this->props($request, $periode) + ['komponen' => $komponen]);
    }

    /**
     * Simpan SELURUH baris kategori satu komponen sekaligus.
     *
     * Diganti utuh, bukan ditambal per baris: layarnya menampilkan daftar yang bisa disisipi dan
     * dihapus, dan menambalnya per baris menyisakan baris yatim ketika satu permintaan gagal di
     * tengah. Seluruhnya di dalam satu transaksi.
     */
    public function simpanKategori(Request $request, BsCogs2Periode $periode, string $komponen): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        abort_unless(in_array($komponen, self::KOMPONEN, true), 404);

        $v = $request->validate([
            'baris'              => ['present', 'array', 'max:500'],
            'baris.*.lokasi'     => ['nullable', 'string', 'max:32'],
            'baris.*.tahap'      => ['nullable', 'string', 'max:32'],
            'baris.*.kategori'   => ['nullable', 'string', 'max:100'],
            'baris.*.no_spk'     => ['nullable', 'string', 'max:150'],
            'baris.*.luas_tanah' => ['nullable', 'numeric', 'min:0'],
            'baris.*.harga_rata2' => ['nullable', 'numeric', 'min:0'],
            'baris.*.nominal'    => ['nullable', 'numeric'],
            // Blok tipe rumah hanya ada pada komponen Housing.
            'tipe'               => ['nullable', 'array', 'max:100'],
            'tipe.*.nama'        => ['required_with:tipe', 'string', 'max:100'],
            'tipe.*.luas_bangunan' => ['required_with:tipe', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($v, $periode, $komponen, $request) {
            BsCogs2Kategori::where('periode_id', $periode->id)->where('komponen', $komponen)->delete();

            foreach (array_values($v['baris']) as $i => $b) {
                // TANAH: nominal = luas × harga rata-rata (rumus F3 di lampiran). Dihitung di sisi
                // server supaya angka yang tersimpan tidak pernah berbeda dari rumusnya.
                $nominal = $komponen === 'tanah'
                    ? round((float) ($b['luas_tanah'] ?? 0) * (float) ($b['harga_rata2'] ?? 0), 2)
                    : round((float) ($b['nominal'] ?? 0), 2);

                BsCogs2Kategori::create([
                    'tenant_id'   => tenant_id(),
                    'periode_id'  => $periode->id,
                    'komponen'    => $komponen,
                    'lokasi'      => $b['lokasi'] ?? null,
                    'tahap'       => $b['tahap'] ?? null,
                    'kategori'    => $b['kategori'] ?? null,
                    'no_spk'      => $b['no_spk'] ?? null,
                    'luas_tanah'  => $komponen === 'tanah' ? ($b['luas_tanah'] ?? 0) : null,
                    'harga_rata2' => $komponen === 'tanah' ? ($b['harga_rata2'] ?? 0) : null,
                    'nominal'     => $nominal,
                    'urut'        => $i + 1,
                ]);
            }

            if ($komponen === 'housing' && array_key_exists('tipe', $v)) {
                BsCogs2HousingTipe::where('periode_id', $periode->id)->delete();
                foreach (array_values($v['tipe'] ?? []) as $i => $t) {
                    BsCogs2HousingTipe::create([
                        'tenant_id'     => tenant_id(),
                        'periode_id'    => $periode->id,
                        'nama'          => trim((string) $t['nama']),
                        'luas_bangunan' => round((float) $t['luas_bangunan'], 2),
                        'urut'          => $i + 1,
                    ]);
                }
            }

            $periode->update(['updated_by' => $request->user()?->id]);
        });

        return back()->with('success', 'Set Up Kategori COGS disimpan.');
    }

    // ── 3. Actual Costing COGS ──────────────────────────────────────────────────────

    public function actual(Request $request, BsCogs2Periode $periode, string $komponen): Response
    {
        abort_unless($request->user()?->can('cogs.budget.view'), 403);
        abort_unless(in_array($komponen, self::KOMPONEN, true), 404);

        return Inertia::render('BusinessSupport/Cogs2/Actual', $this->props($request, $periode) + [
            'komponen'     => $komponen,
            // Akun COA yang ditandai komponen ini di Tambah/Edit Akun, beserta keterangan apakah
            // sudah diakui milik periode ini — inilah tempat tanda di Chart of Account benar-benar
            // tersambung ke Actual Costing COGS.
            'akunBertanda' => $this->sumber->akunBertanda($periode, $komponen),
            'perluKategori' => Cogs2Sumber::perluKategori($komponen),
        ]);
    }

    /**
     * Akui / cabut akun COA bertanda komponen sebagai milik periode ini.
     *
     * Diganti utuh per komponen, bukan ditambal per akun: layarnya berupa daftar centang, dan
     * menambalnya satu per satu menyisakan keadaan setengah jadi bila satu permintaan gagal.
     * Akun komponen LAIN tidak ikut tersentuh.
     */
    public function simpanAkun(Request $request, BsCogs2Periode $periode, string $komponen): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        abort_unless(in_array($komponen, self::KOMPONEN, true), 404);

        $v = $request->validate([
            'account_id'   => ['present', 'array', 'max:500'],
            'account_id.*' => ['integer', 'min:1'],
        ]);

        // Hanya akun yang BENAR-BENAR bertanda komponen ini yang boleh diakui. Tanpa penyaring
        // ini, akun mana pun bisa diselundupkan lewat kiriman dan mutasinya ikut membebani HPP.
        $sah = collect($this->sumber->akunBertanda($periode, $komponen))->pluck('id')->all();
        $dipilih = array_values(array_intersect(array_map('intval', $v['account_id']), $sah));

        DB::transaction(function () use ($dipilih, $sah, $periode, $request) {
            BsCogs2Akun::where('periode_id', $periode->id)->whereIn('account_id', $sah)->delete();
            foreach ($dipilih as $id) {
                BsCogs2Akun::create(['tenant_id' => tenant_id(), 'periode_id' => $periode->id, 'account_id' => $id]);
            }
            $periode->update(['updated_by' => $request->user()?->id]);
        });

        return back()->with('success', count($dipilih).' akun COA diakui untuk periode ini.');
    }

    /** Saklar kedua sumber otomatis. */
    public function simpanSumber(Request $request, BsCogs2Periode $periode): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'ikut_muk' => ['required', 'boolean'],
            'ikut_gl'  => ['required', 'boolean'],
        ]);
        $periode->update($v + ['updated_by' => $request->user()?->id]);

        return back()->with('success', 'Sumber biaya aktual disimpan.');
    }

    public function simpanActual(Request $request, BsCogs2Periode $periode, string $komponen): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        abort_unless(in_array($komponen, self::KOMPONEN, true), 404);

        $v = $request->validate([
            'baris'               => ['present', 'array', 'max:2000'],
            'baris.*.lokasi'      => ['nullable', 'string', 'max:32'],
            'baris.*.tahap'       => ['nullable', 'string', 'max:32'],
            'baris.*.kategori'    => ['nullable', 'string', 'max:100'],
            'baris.*.no_spk'      => ['nullable', 'string', 'max:150'],
            'baris.*.no_jurnal'   => ['nullable', 'string', 'max:100'],
            'baris.*.keterangan'  => ['nullable', 'string', 'max:255'],
            'baris.*.penambahan'  => ['nullable', 'numeric'],
            'baris.*.pengurangan' => ['nullable', 'numeric'],
        ]);

        DB::transaction(function () use ($v, $periode, $komponen, $request) {
            BsCogs2Actual::where('periode_id', $periode->id)->where('komponen', $komponen)->delete();

            foreach (array_values($v['baris']) as $i => $b) {
                BsCogs2Actual::create([
                    'tenant_id'   => tenant_id(),
                    'periode_id'  => $periode->id,
                    'komponen'    => $komponen,
                    'lokasi'      => $b['lokasi'] ?? null,
                    'tahap'       => $b['tahap'] ?? null,
                    'kategori'    => $b['kategori'] ?? null,
                    'no_spk'      => $b['no_spk'] ?? null,
                    'no_jurnal'   => $b['no_jurnal'] ?? null,
                    'keterangan'  => $b['keterangan'] ?? null,
                    'penambahan'  => round((float) ($b['penambahan'] ?? 0), 2),
                    'pengurangan' => round((float) ($b['pengurangan'] ?? 0), 2),
                    'urut'        => $i + 1,
                ]);
            }

            $periode->update(['updated_by' => $request->user()?->id]);
        });

        return back()->with('success', 'Actual Costing COGS disimpan.');
    }

    // ── 4. Rincian COGS ─────────────────────────────────────────────────────────────

    public function rincian(Request $request, BsCogs2Periode $periode): Response
    {
        abort_unless($request->user()?->can('cogs.budget.view'), 403);

        [$lokasi, $galatFo] = $this->lokasiFo();

        return Inertia::render('BusinessSupport/Cogs2/Rincian', $this->props($request, $periode) + [
            'foLokasi' => $lokasi,
            'foTahap'  => $this->tahapFo(),
            'galatFo'  => $galatFo,
            // Daftar unit dari modul Unit (Set Up Sales) TIDAK ikut dikirim saat halaman dibuka:
            // satu lokasi bisa berisi ratusan unit, dan hampir setiap kali layar Rincian dibuka
            // daftarnya tidak dipakai sama sekali. Ia baru diambil saat pemilihnya dibuka, lewat
            // muat-ulang sebagian.
            'unitFo'   => Inertia::lazy(fn () => $this->pilihanUnitFo($request, $periode)),
        ]);
    }

    /** Berapa banyak unit yang dikirim ke pemilih sekali muat. */
    private const BATAS_PILIHAN_UNIT = 500;

    /**
     * Daftar unit dari modul Unit (Set Up Sales) untuk dicentang ke Rincian COGS.
     *
     * Lokasi & tahapnya BISA BERBEDA dari periode yang sedang dibuka — operator memang perlu
     * memilih "lokasi dan tahap yang diinginkan", dan satu periode COGS bisa memuat unit dari
     * tahap yang berbeda.
     *
     * No. SPR & nama customer diambil dari kontrak bila ada, kalau tidak dari reservasi: kontrak
     * yang sudah ditandatangani menggantikan reservasinya. Keduanya dibaca lewat kueri TERPISAH
     * yang dibungkus try/catch — kegagalan di sisi FO hanya mengosongkan kedua kolom itu, bukan
     * menjatuhkan seluruh pemilihnya.
     *
     * @return array<string,mixed>
     */
    private function pilihanUnitFo(Request $request, BsCogs2Periode $periode): array
    {
        $db = $this->dbFo();
        $lokasiId = (int) ($request->input('fo_lokasi') ?: $periode->lokasi_id);
        $tahap = trim((string) $request->input('fo_tahap', $periode->tahap));
        $cari = trim((string) $request->input('fo_cari', ''));
        $filters = ['lokasi_id' => $lokasiId, 'tahap' => $tahap, 'cari' => $cari];

        if ($db === '') {
            return ['rows' => [], 'filters' => $filters, 'galat' => 'Nama basis data Front Office belum diatur.', 'total' => 0, 'terpotong' => false];
        }

        $sql = 'SELECT u.id, u.kode_unit, u.blok, u.no_unit, u.tahap, u.cluster,'
            .' t.name AS tipe, u.luas_tanah, u.luas_lebih_tanah, u.luas_bangunan, u.harga_jual, u.status'
            .' FROM `'.$db.'`.fo_units u'
            .' LEFT JOIN `'.$db.'`.fo_unit_types t ON t.id = u.unit_type_id'
            .' WHERE u.deleted_at IS NULL AND u.lokasi_id = ?'
            // Unit BATAL dikeluarkan: ia bukan lagi bagian dari lahan yang dijual, jadi
            // membebaninya mengecilkan tarif per m2 seluruh unit lain.
            .' AND (u.status IS NULL OR u.status <> ?)';
        $bind = [$lokasiId, 'BATAL'];

        if ($tahap !== '') {
            $sql .= ' AND u.tahap = ?';
            $bind[] = $tahap;
        }
        if ($cari !== '') {
            $sql .= ' AND (u.kode_unit LIKE ? OR u.blok LIKE ? OR u.no_unit LIKE ? OR t.name LIKE ?)';
            array_push($bind, '%'.$cari.'%', '%'.$cari.'%', '%'.$cari.'%', '%'.$cari.'%');
        }
        $sql .= ' ORDER BY u.blok, u.no_unit, u.kode_unit LIMIT '.(self::BATAS_PILIHAN_UNIT + 1);

        try {
            $rows = DB::select($sql, $bind);
        } catch (\Throwable $e) {
            report($e);

            return ['rows' => [], 'filters' => $filters, 'galat' => 'Daftar unit tidak bisa dibaca dari Front Office.', 'total' => 0, 'terpotong' => false];
        }

        // Batasnya DISEBUT, tidak dipotong diam-diam: daftar yang terpotong tanpa keterangan
        // membuat operator mengira unit yang hilang memang tidak ada.
        $terpotong = count($rows) > self::BATAS_PILIHAN_UNIT;
        if ($terpotong) {
            $rows = array_slice($rows, 0, self::BATAS_PILIHAN_UNIT);
        }

        $identitas = $this->identitasUnitFo($db, array_map(fn ($r) => (int) $r->id, $rows));
        $sudahAda = BsCogs2Unit::where('periode_id', $periode->id)->pluck('unit_rumah')
            ->map(fn ($v) => mb_strtolower((string) $v))->all();

        return [
            'rows' => array_map(function ($r) use ($identitas, $sudahAda) {
                $id = (int) $r->id;

                return [
                    'kode'          => (string) $r->kode_unit,
                    'blok'          => $r->blok,
                    'no_unit'       => $r->no_unit,
                    'tahap'         => $r->tahap,
                    'cluster'       => $r->cluster,
                    'tipe'          => $r->tipe,
                    'lt'            => round((float) ($r->luas_tanah ?? 0), 2),
                    'lt_lebih'      => round((float) ($r->luas_lebih_tanah ?? 0), 2),
                    'lb'            => round((float) ($r->luas_bangunan ?? 0), 2),
                    'harga_jual'    => round((float) ($r->harga_jual ?? 0), 2),
                    'status'        => $r->status,
                    'no_spr'        => $identitas[$id]['no'] ?? null,
                    'nama_customer' => $identitas[$id]['nama'] ?? null,
                    // Unit yang sudah ada di Rincian ditandai, bukan disembunyikan: menyembunyikannya
                    // membuat operator mengira unitnya belum pernah ditarik.
                    'sudah'         => in_array(mb_strtolower((string) $r->kode_unit), $sudahAda, true),
                ];
            }, $rows),
            'filters'   => $filters,
            'galat'     => null,
            'total'     => count($rows),
            'terpotong' => $terpotong,
        ];
    }

    /**
     * No. SPR & nama customer per unit — kontrak lebih dulu, lalu reservasi.
     *
     * @param  list<int>  $unitId
     * @return array<int, array{no:?string, nama:?string}>
     */
    private function identitasUnitFo(string $db, array $unitId): array
    {
        if ($unitId === []) {
            return [];
        }
        $tanda = implode(',', array_fill(0, count($unitId), '?'));
        $out = [];

        // Reservasi lebih dulu, lalu kontrak MENIMPANYA: kontrak yang sudah ditandatangani
        // menggantikan reservasinya, jadi ia yang harus terbaca bila keduanya ada.
        foreach ([
            ['fo_reservasi', 'no_reservasi'],
            ['fo_kontrak', 'no_kontrak'],
        ] as [$tabel, $kolom]) {
            try {
                $rows = DB::select(
                    'SELECT r.unit_id, r.'.$kolom.' AS no, c.full_name AS nama'
                    .' FROM `'.$db.'`.'.$tabel.' r'
                    .' LEFT JOIN `'.$db.'`.fo_customers c ON c.id = r.customer_id'
                    .' WHERE r.deleted_at IS NULL AND r.unit_id IN ('.$tanda.')',
                    $unitId,
                );
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
            foreach ($rows as $r) {
                $out[(int) $r->unit_id] = ['no' => $r->no, 'nama' => $r->nama];
            }
        }

        return $out;
    }

    public function simpanUnit(Request $request, BsCogs2Periode $periode): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'baris'                 => ['present', 'array', 'max:2000'],
            'baris.*.no_spr'        => ['nullable', 'string', 'max:64'],
            'baris.*.nama_customer' => ['nullable', 'string', 'max:150'],
            'baris.*.unit_rumah'    => ['required', 'string', 'max:64'],
            'baris.*.lokasi'        => ['nullable', 'string', 'max:32'],
            'baris.*.tahap'         => ['nullable', 'string', 'max:32'],
            'baris.*.tipe'          => ['nullable', 'string', 'max:100'],
            'baris.*.lt'            => ['required', 'numeric', 'min:0'],
            'baris.*.lb'            => ['required', 'numeric', 'min:0'],
            'baris.*.status'        => ['required', Rule::in(array_keys(BsCogs2Unit::STATUS))],
            'baris.*.costing'       => ['required', Rule::in(array_keys(BsCogs2Unit::COSTING))],
            'baris.*.booked_revenue' => ['nullable', 'numeric', 'min:0'],
        ], [
            'baris.*.unit_rumah.required' => 'Kode unit wajib diisi.',
        ]);

        // Unit BELUM booked tidak boleh mengunci pilihan costing: selama belum booked, keduanya
        // masih terbuka, dan mengunci "Standard Costing" di sini akan mengeluarkannya dari
        // pembebanan realisasi padahal ia belum terjual.
        foreach (array_values($v['baris']) as $i => $b) {
            if ($b['status'] === 'belum' && $b['costing'] !== 'belum') {
                return back()->withErrors([
                    'baris' => 'Unit "'.$b['unit_rumah'].'" belum booked, jadi pilihan costing-nya harus tetap terbuka.',
                ]);
            }
        }

        $kode = array_map(fn ($b) => trim((string) $b['unit_rumah']), array_values($v['baris']));
        if (count($kode) !== count(array_unique($kode))) {
            return back()->withErrors(['baris' => 'Ada kode unit yang kembar — satu unit hanya boleh muncul sekali.']);
        }

        DB::transaction(function () use ($v, $periode, $request) {
            BsCogs2Unit::where('periode_id', $periode->id)->delete();

            foreach (array_values($v['baris']) as $i => $b) {
                BsCogs2Unit::create([
                    'tenant_id'      => tenant_id(),
                    'periode_id'     => $periode->id,
                    'no_spr'         => $b['no_spr'] ?? null,
                    'nama_customer'  => $b['nama_customer'] ?? null,
                    'unit_rumah'     => trim((string) $b['unit_rumah']),
                    'lokasi'         => $b['lokasi'] ?? null,
                    'tahap'          => $b['tahap'] ?? null,
                    'tipe'           => $b['tipe'] ?? null,
                    'lt'             => round((float) $b['lt'], 2),
                    'lb'             => round((float) $b['lb'], 2),
                    'status'         => (string) $b['status'],
                    'costing'        => (string) $b['costing'],
                    'booked_revenue' => $b['booked_revenue'] ?? null,
                    'urut'           => $i + 1,
                ]);
            }

            $periode->update(['updated_by' => $request->user()?->id]);
        });

        return back()->with('success', 'Rincian COGS disimpan.');
    }

    /**
     * Tarik daftar unit dari Front Office.
     *
     * MENAMBAH, tidak menimpa: unit yang sudah ada di Rincian COGS dibiarkan apa adanya beserta
     * status booked dan pilihan costing-nya. Menimpanya akan mengembalikan seluruh unit ke
     * "belum booked" dan diam-diam membatalkan HPP yang sudah terkunci.
     */
    public function tarikUnit(Request $request, BsCogs2Periode $periode): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        [$unitFo, $galat] = $this->unitFo((int) $periode->lokasi_id, (string) $periode->tahap);
        if ($galat !== null) {
            return back()->withErrors(['tarik' => $galat]);
        }
        if ($unitFo === []) {
            return back()->withErrors(['tarik' => 'Tidak ada unit pada cakupan ini di Front Office.']);
        }

        $ada = BsCogs2Unit::where('periode_id', $periode->id)->pluck('unit_rumah')
            ->map(fn ($v) => mb_strtolower((string) $v))->all();
        $urut = (int) BsCogs2Unit::where('periode_id', $periode->id)->max('urut');
        $baru = 0;

        DB::transaction(function () use ($unitFo, $ada, $periode, &$urut, &$baru) {
            foreach ($unitFo as $u) {
                // Dicocokkan TANPA peka huruf: kode unit di dua sistem berbeda besar-kecil
                // hurufnya, dan pencocokan yang peka huruf akan menggandakan unit yang sama.
                if (in_array(mb_strtolower((string) $u['kode']), $ada, true)) {
                    continue;
                }
                BsCogs2Unit::create([
                    'tenant_id'  => tenant_id(),
                    'periode_id' => $periode->id,
                    'unit_rumah' => $u['kode'],
                    'lokasi'     => $periode->lokasi_kode,
                    'tahap'      => $u['tahap'] ?? $periode->tahap,
                    'tipe'       => $u['tipe'],
                    'lt'         => $u['luas_tanah'],
                    'lb'         => $u['luas_bangunan'],
                    'status'     => 'belum',
                    'costing'    => 'belum',
                    'urut'       => ++$urut,
                ]);
                $baru++;
            }
        });

        return back()->with('success', $baru === 0
            ? 'Seluruh unit Front Office sudah ada di Rincian COGS.'
            : $baru.' unit ditambahkan dari Front Office.');
    }

    // ── Props bersama ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function props(Request $request, BsCogs2Periode $periode): array
    {
        // Periode yang sedang dibuka DIINGAT, supaya berpindah sub-modul lewat sidebar mendarat di
        // periode yang sama — lihat buka(). Ditaruh di sini karena keempat layar melewati props();
        // menaruhnya di tiap layar berarti empat tempat yang bisa terlupa satu.
        if ($request->hasSession()) {
            $request->session()->put(self::SESI_PERIODE, $periode->id);
        }

        $periode->load(['tipe', 'kategori', 'actual', 'unit']);

        // ── Tiga sumber biaya aktual, digabung SEBELUM dihitung ──────────────────────
        // Kalkulator tidak perlu tahu dari mana sebuah baris datang; ia hanya menjumlah. Dengan
        // menggabungkannya di sini, rekonsiliasi tetap tertutup apa pun sumbernya, dan tidak ada
        // jalur kedua yang bisa menghasilkan angka berbeda.
        $mukBaris = $periode->ikut_muk ? $this->sumber->muk($periode) : collect();
        $glBaris = $periode->ikut_gl ? $this->sumber->gl($periode) : collect();

        $gabungan = $periode->actual->map(fn (BsCogs2Actual $a) => (object) [
            'sumber' => 'manual', 'komponen' => $a->komponen, 'kategori' => $a->kategori,
            'no_spk' => $a->no_spk, 'penambahan' => (float) $a->penambahan, 'pengurangan' => (float) $a->pengurangan,
        ])->concat($mukBaris)->concat($glBaris);

        $hitung = $this->kalkulator->hitung(
            $periode,
            $periode->tipe,
            $periode->kategori,
            $gabungan,
            $periode->unit,
        );

        // Subtotal tiap sumber, per komponen — supaya penggandaan (baris yang diketik padahal
        // kontrak/jurnalnya sudah ditarik otomatis) terlihat seketika, bukan tersembunyi di
        // dalam satu angka besar.
        $subtotal = [];
        foreach (array_keys(Cogs2Kalkulator::KOMPONEN) as $k) {
            $subtotal[$k] = [
                'manual' => round((float) $periode->actual->where('komponen', $k)
                    ->sum(fn ($a) => (float) $a->penambahan - (float) $a->pengurangan), 2),
                'muk' => round((float) $mukBaris->where('komponen', $k)
                    ->sum(fn ($a) => (float) $a->penambahan - (float) $a->pengurangan), 2),
                'gl' => round((float) $glBaris->where('komponen', $k)
                    ->sum(fn ($a) => (float) $a->penambahan - (float) $a->pengurangan), 2),
            ];
        }

        return [
            'periode' => [
                'id'            => $periode->id,
                'lokasi_id'     => $periode->lokasi_id,
                'lokasi_kode'   => $periode->lokasi_kode,
                'lokasi_nama'   => $periode->lokasi_nama,
                'tahap'         => $periode->tahap,
                'cakupan'       => $periode->cakupan,
                'luas_lahan'    => (float) $periode->luas_lahan,
                'saleable'      => (float) $periode->saleable,
                'rth'           => (float) $periode->rth,
                'luas_bangunan' => (float) $periode->luas_bangunan,
                'jumlah_unit'   => $periode->jumlah_unit,
                'ikut_muk'      => (bool) $periode->ikut_muk,
                'ikut_gl'       => (bool) $periode->ikut_gl,
                'catatan'       => $periode->catatan,
            ],
            // Baris dari kedua sumber otomatis, berdiri SENDIRI di layar: baris yang diketik
            // operator tetap bisa disunting, sedangkan yang ditarik hanya dibaca.
            'sumberMuk'   => $mukBaris->groupBy('komponen')->map(fn ($g) => $g->values()),
            'sumberGl'    => $glBaris->groupBy('komponen')->map(fn ($g) => $g->values()),
            'subtotal'    => $subtotal,
            'kategoriBaris' => $periode->kategori->groupBy('komponen')->map(
                fn ($g) => $g->map(fn (BsCogs2Kategori $r) => [
                    'id' => $r->id, 'lokasi' => $r->lokasi, 'tahap' => $r->tahap,
                    'kategori' => $r->kategori, 'no_spk' => $r->no_spk,
                    'luas_tanah' => $r->luas_tanah === null ? null : (float) $r->luas_tanah,
                    'harga_rata2' => $r->harga_rata2 === null ? null : (float) $r->harga_rata2,
                    'nominal' => (float) $r->nominal,
                ])->values(),
            ),
            'actualBaris' => $periode->actual->groupBy('komponen')->map(
                fn ($g) => $g->map(fn (BsCogs2Actual $r) => [
                    'id' => $r->id, 'lokasi' => $r->lokasi, 'tahap' => $r->tahap,
                    'kategori' => $r->kategori, 'no_spk' => $r->no_spk, 'no_jurnal' => $r->no_jurnal,
                    'keterangan' => $r->keterangan,
                    'penambahan' => (float) $r->penambahan, 'pengurangan' => (float) $r->pengurangan,
                    'saldo_akhir' => $r->saldo_akhir,
                ])->values(),
            ),
            'tipeBaris' => $periode->tipe->map(fn (BsCogs2HousingTipe $t) => [
                'id' => $t->id, 'nama' => $t->nama, 'luas_bangunan' => (float) $t->luas_bangunan,
            ])->values(),
            'unitBaris' => $periode->unit->map(fn (BsCogs2Unit $u) => [
                'id' => $u->id, 'no_spr' => $u->no_spr, 'nama_customer' => $u->nama_customer,
                'unit_rumah' => $u->unit_rumah, 'lokasi' => $u->lokasi, 'tahap' => $u->tahap,
                'tipe' => $u->tipe, 'lt' => (float) $u->lt, 'lb' => (float) $u->lb,
                'status' => $u->status, 'costing' => $u->costing,
                'booked_revenue' => $u->booked_revenue === null ? null : (float) $u->booked_revenue,
            ])->values(),
            'hitung'      => $hitung,
            'statusOpsi'  => BsCogs2Unit::STATUS,
            'costingOpsi' => BsCogs2Unit::COSTING,
            'komponenOpsi' => collect(Cogs2Kalkulator::KOMPONEN)->map(fn ($d) => $d['label']),
            'bisaUbah'    => (bool) $request->user()?->can('businesssupport.setup.manage'),
        ];
    }

    // ── Pembacaan Front Office ──────────────────────────────────────────────────────

    private function dbFo(): string
    {
        $db = (string) config('bankloan.fo_database', '');

        return preg_match('/^[A-Za-z0-9_]+$/', $db) === 1 ? $db : '';
    }

    /** @return array{0: list<array{id:int,kode:string,nama:string}>, 1: ?string} */
    private function lokasiFo(): array
    {
        $db = $this->dbFo();
        if ($db === '') {
            return [[], 'Nama basis data Front Office belum diatur (config bankloan.fo_database).'];
        }

        try {
            $rows = DB::select('SELECT id, kode, nama FROM `'.$db.'`.fo_lokasi WHERE deleted_at IS NULL ORDER BY kode');
        } catch (\Throwable $e) {
            report($e);

            return [[], 'Daftar lokasi tidak bisa dibaca dari Front Office.'];
        }

        return [array_map(fn ($r) => [
            'id' => (int) $r->id, 'kode' => (string) $r->kode, 'nama' => (string) $r->nama,
        ], $rows), null];
    }

    /** @return list<array{id:int,lokasi_id:int,kode:string,nama:string}> */
    private function tahapFo(): array
    {
        $db = $this->dbFo();
        if ($db === '') {
            return [];
        }

        try {
            $rows = DB::select('SELECT id, lokasi_id, kode, nama FROM `'.$db.'`.fo_tahap WHERE deleted_at IS NULL ORDER BY kode');
        } catch (\Throwable $e) {
            report($e);

            return [];
        }

        return array_map(fn ($r) => [
            'id' => (int) $r->id, 'lokasi_id' => (int) $r->lokasi_id,
            'kode' => (string) $r->kode, 'nama' => (string) $r->nama,
        ], $rows);
    }

    /**
     * Unit beserta luasnya dari `jerp`.fo_units — satu-satunya tempat luas tanah & luas bangunan
     * benar-benar tersimpan per unit. Unit BATAL dikeluarkan: ia bukan lagi bagian dari lahan yang
     * dijual, jadi membebaninya mengecilkan tarif seluruh unit lain.
     *
     * Kuerinya berdiri sendiri dan dibungkus try/catch, dengan nama basis data disaring ketat
     * sebelum menyatu ke SQL sebagai pengenal — ia tidak bisa diparameterkan.
     *
     * @return array{0: list<array<string,mixed>>, 1: ?string}
     */
    private function unitFo(int $lokasiId, string $tahap): array
    {
        $db = $this->dbFo();
        if ($db === '') {
            return [[], 'Nama basis data Front Office belum diatur (config bankloan.fo_database).'];
        }

        $sql = 'SELECT u.kode_unit, u.tahap, t.name AS tipe, u.luas_tanah, u.luas_bangunan'
            .' FROM `'.$db.'`.fo_units u'
            .' LEFT JOIN `'.$db.'`.fo_unit_types t ON t.id = u.unit_type_id'
            .' WHERE u.deleted_at IS NULL AND u.lokasi_id = ?'
            .' AND (u.status IS NULL OR u.status <> ?)';
        $bind = [$lokasiId, 'BATAL'];

        if ($tahap !== '') {
            $sql .= ' AND u.tahap = ?';
            $bind[] = $tahap;
        }
        $sql .= ' ORDER BY u.kode_unit';

        try {
            $rows = DB::select($sql, $bind);
        } catch (\Throwable $e) {
            report($e);

            return [[], 'Data unit tidak bisa dibaca dari Front Office.'];
        }

        return [array_map(fn ($r) => [
            'kode'          => (string) $r->kode_unit,
            'tahap'         => $r->tahap !== null ? (string) $r->tahap : null,
            'tipe'          => $r->tipe !== null ? (string) $r->tipe : null,
            'luas_tanah'    => (float) ($r->luas_tanah ?? 0),
            'luas_bangunan' => (float) ($r->luas_bangunan ?? 0),
        ], $rows), null];
    }
}
