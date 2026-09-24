<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsCogsSetup;
use App\Domain\BusinessSupport\Models\BsCogsSetupComponent;
use App\Domain\BusinessSupport\Services\CogsSetupKalkulator;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Set Up COGS — perhitungan budget per unit dari luas tanah & luas bangunan.
 *
 * JANGAN TERTUKAR dengan CogsSetupController. Grup sidebar "Setup" yang lama (Lokasi, Ketentuan,
 * Budget, Set Akun Budget, Set COA Jenis Unit, Upload) dilayani pengendali itu. Yang ini adalah
 * sub-modul "Set Up COGS" yang berdiri di bawah Komponen COGS: ia menghitung tarif Rp per m2 dari
 * blok "Perhitungan Lahan", lalu menurunkannya ke tiap unit menurut luas tanah & luas bangunan
 * unit itu sendiri.
 *
 * ── Dari mana luas per unit dibaca ──
 * Dari `jerp`.fo_units (luas_tanah, luas_bangunan, luas_lebih_tanah — decimal, PER UNIT), bukan
 * dari BO. Tabel `units` di BO tidak punya kolom luas sama sekali; luas hanya dititipkan di kolom
 * attributes (JSON) oleh sinkron yang sampai sekarang belum pernah berhasil, dan unit_types.lt/lb
 * hanya berlaku per JENIS unit dan bertipe integer — dua unit bertipe sama akan selalu dianggap
 * berluas sama, dan luas 105,5 m2 dibulatkan diam-diam menjadi 106. Keduanya membuat pembagian
 * "per masing-masing unit" tidak pernah benar-benar per unit.
 *
 * Pembacaannya meniru MasterDataKprController::lokasiTahapUnit(): kueri TERPISAH yang dibungkus
 * try/catch, dengan nama basis data disaring ketat sebelum menyatu ke SQL sebagai pengenal
 * (ia tidak bisa diparameterkan, jadi tidak ada penjaga lain yang menahannya). Bila FO tak
 * terjangkau, daftar unitnya kosong dan layarnya mengatakan begitu — bukan layar yang mati.
 *
 * ── Izin ──
 * Baca cogs.budget.view, tulis businesssupport.setup.manage — pasangan yang sudah dipakai seluruh
 * layar Setup COGS yang ada (CogsSetupController). Sengaja TIDAK membuat izin baru: izin baru di
 * pemasangan ini lahir yatim dan menunya hilang dari sidebar tanpa satu pun galat, dan
 * businesssupport.setup.manage sudah dipegang FinanceManager selain SuperAdmin.
 */
class CogsSetupCogsController extends Controller
{
    /** Pos biaya bawaan saat set-up dibuat — persis keempat baris pada lampiran. */
    private const KOMPONEN_BAWAAN = [
        ['nama' => 'LAC Tanah', 'dasar' => 'tanah'],
        ['nama' => 'Infrastruktur', 'dasar' => 'tanah'],
        ['nama' => 'Undertable', 'dasar' => 'tanah'],
        ['nama' => 'License Permit', 'dasar' => 'tanah'],
    ];

    public function __construct(private readonly CogsSetupKalkulator $kalkulator) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('cogs.budget.view'), 403);

        $rows = BsCogsSetup::with('komponen')->orderBy('lokasi_kode')->orderBy('tahap')->get()
            ->map(function (BsCogsSetup $s) {
                $total = round((float) $s->komponen->sum(fn ($k) => (float) $k->nilai), 2);

                return [
                    'id'            => $s->id,
                    'lokasi_id'     => $s->lokasi_id,
                    'lokasi'        => trim((string) (($s->lokasi_kode ? $s->lokasi_kode.' — ' : '').$s->lokasi_nama)),
                    'tahap'         => $s->tahap,
                    'luas_lahan'    => (float) $s->luas_lahan,
                    'saleable'      => (float) $s->saleable,
                    'rth'           => (float) $s->rth,
                    'jalan'         => $s->jalan,
                    'jumlah_unit'   => $s->jumlah_unit,
                    'komponen'      => $s->komponen->count(),
                    'total_cogs'    => $total,
                    'cogs_gross'    => (float) $s->luas_lahan > 0.009 ? $total / (float) $s->luas_lahan : null,
                    'cogs_saleable' => (float) $s->saleable > 0.009 ? $total / (float) $s->saleable : null,
                ];
            })->values();

        [$lokasi, $galatFo] = $this->lokasiFo();

        return Inertia::render('BusinessSupport/Cogs/SetupCogs/Index', [
            'rows'      => $rows,
            'lokasiFo'  => $lokasi,
            'tahapFo'   => $this->tahapFo(),
            'galatFo'   => $galatFo,
            'bisaUbah'  => (bool) $request->user()?->can('businesssupport.setup.manage'),
        ]);
    }

    public function show(Request $request, BsCogsSetup $setup): Response
    {
        abort_unless($request->user()?->can('cogs.budget.view'), 403);

        $setup->load('komponen');
        [$unit, $galatFo] = $this->unitFo((int) $setup->lokasi_id, (string) $setup->tahap);

        $hitung = $this->kalkulator->hitung(
            [
                'luas_lahan'           => (float) $setup->luas_lahan,
                'saleable'             => (float) $setup->saleable,
                'rth'                  => (float) $setup->rth,
                'jumlah_unit'          => $setup->jumlah_unit,
                'dasar_tanah'          => (string) $setup->dasar_tanah,
                'termasuk_lebih_tanah' => (bool) $setup->termasuk_lebih_tanah,
            ],
            $setup->komponen->map(fn (BsCogsSetupComponent $k) => [
                'id' => $k->id, 'nama' => (string) $k->nama, 'dasar' => (string) $k->dasar, 'nilai' => (float) $k->nilai,
            ])->all(),
            $unit,
        );

        return Inertia::render('BusinessSupport/Cogs/SetupCogs/Show', [
            'setup' => [
                'id'                   => $setup->id,
                'lokasi_id'            => $setup->lokasi_id,
                'lokasi_kode'          => $setup->lokasi_kode,
                'lokasi_nama'          => $setup->lokasi_nama,
                'tahap'                => $setup->tahap,
                'cakupan'              => $setup->cakupan,
                'luas_lahan'           => (float) $setup->luas_lahan,
                'saleable'             => (float) $setup->saleable,
                'rth'                  => (float) $setup->rth,
                'jumlah_unit'          => $setup->jumlah_unit,
                'dasar_tanah'          => (string) $setup->dasar_tanah,
                'termasuk_lebih_tanah' => (bool) $setup->termasuk_lebih_tanah,
                'catatan'              => $setup->catatan,
            ],
            'komponen' => $setup->komponen->map(fn (BsCogsSetupComponent $k) => [
                'id' => $k->id, 'nama' => (string) $k->nama, 'dasar' => (string) $k->dasar, 'nilai' => (float) $k->nilai,
            ])->values(),
            'hitung'    => $hitung,
            'dasarOpsi' => BsCogsSetupComponent::DASAR,
            'galatFo'   => $galatFo,
            'bisaUbah'  => (bool) $request->user()?->can('businesssupport.setup.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'lokasi_id' => ['required', 'integer', 'min:1'],
            // '' berarti seluruh lokasi. Disimpan sebagai string kosong, BUKAN null: kunci unik
            // (tenant_id, lokasi_id, tahap) tidak menahan NULL yang kembar, sehingga dua set-up
            // untuk cakupan yang sama akan lolos dan menghasilkan dua budget per unit.
            'tahap'     => ['present', 'nullable', 'string', 'max:32'],
        ]);

        $tahap = trim((string) ($v['tahap'] ?? ''));
        [$lokasi] = $this->lokasiFo();
        $pilih = collect($lokasi)->firstWhere('id', (int) $v['lokasi_id']);

        if (BsCogsSetup::where('lokasi_id', (int) $v['lokasi_id'])->where('tahap', $tahap)->exists()) {
            return back()->withErrors(['lokasi_id' => 'Set Up COGS untuk cakupan itu sudah ada.']);
        }

        $setup = DB::transaction(function () use ($v, $tahap, $pilih, $request) {
            $s = BsCogsSetup::create([
                'tenant_id'   => tenant_id(),
                'lokasi_id'   => (int) $v['lokasi_id'],
                'lokasi_kode' => $pilih['kode'] ?? null,
                'lokasi_nama' => $pilih['nama'] ?? null,
                'tahap'       => $tahap,
                'created_by'  => $request->user()?->id,
                'updated_by'  => $request->user()?->id,
            ]);

            foreach (self::KOMPONEN_BAWAAN as $i => $k) {
                BsCogsSetupComponent::create([
                    'tenant_id' => tenant_id(), 'setup_id' => $s->id,
                    'nama' => $k['nama'], 'dasar' => $k['dasar'], 'nilai' => 0, 'urut' => $i + 1,
                ]);
            }

            return $s;
        });

        return redirect()->route('bs.cogs.setupcogs.show', $setup->id)->with('success', 'Set Up COGS dibuat.');
    }

    public function update(Request $request, BsCogsSetup $setup): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'luas_lahan'           => ['required', 'numeric', 'min:0'],
            'saleable'             => ['required', 'numeric', 'min:0'],
            'rth'                  => ['required', 'numeric', 'min:0'],
            'jumlah_unit'          => ['nullable', 'integer', 'min:0'],
            'dasar_tanah'          => ['required', 'in:saleable,unit'],
            'termasuk_lebih_tanah' => ['required', 'boolean'],
            'catatan'              => ['nullable', 'string', 'max:2000'],
        ]);

        // Saleable + RTH tidak boleh melampaui Luas Lahan: Jalan adalah SISA-nya, dan sisa yang
        // negatif berarti angkanya mustahil. Ditolak di sini, bukan dibiarkan tampil merah —
        // tarif per m2 yang lahir dari lahan yang tidak konsisten akan terlihat wajar.
        if ((float) $v['saleable'] + (float) $v['rth'] > (float) $v['luas_lahan'] + 0.005) {
            return back()->withErrors([
                'saleable' => 'Saleable + RTH melebihi Luas Lahan, sehingga Jalan menjadi negatif.',
            ]);
        }

        $setup->update($v + ['updated_by' => $request->user()?->id]);

        return back()->with('success', 'Perhitungan Lahan disimpan.');
    }

    /**
     * Simpan SELURUH baris komponen sekaligus (tambah / ubah / hapus dalam satu kiriman).
     *
     * Diganti utuh, bukan ditambal per baris: layar ini menampilkan daftar yang bisa disisipi dan
     * dihapus, dan menambalnya per baris menyisakan baris yatim ketika satu permintaan gagal di
     * tengah. Seluruhnya di dalam satu transaksi.
     */
    public function simpanKomponen(Request $request, BsCogsSetup $setup): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'komponen'         => ['present', 'array', 'max:50'],
            'komponen.*.nama'  => ['required', 'string', 'max:100'],
            'komponen.*.dasar' => ['required', 'in:tanah,bangunan'],
            'komponen.*.nilai' => ['required', 'numeric', 'min:0'],
        ], [
            'komponen.*.nama.required'  => 'Nama pos biaya wajib diisi.',
            'komponen.*.dasar.in'       => 'Dasar pembagi hanya boleh Luas Tanah atau Luas Bangunan.',
        ]);

        DB::transaction(function () use ($v, $setup, $request) {
            BsCogsSetupComponent::where('setup_id', $setup->id)->delete();

            foreach (array_values($v['komponen']) as $i => $k) {
                BsCogsSetupComponent::create([
                    'tenant_id' => tenant_id(),
                    'setup_id'  => $setup->id,
                    'nama'      => trim((string) $k['nama']),
                    'dasar'     => (string) $k['dasar'],
                    'nilai'     => round((float) $k['nilai'], 2),
                    'urut'      => $i + 1,
                ]);
            }

            $setup->update(['updated_by' => $request->user()?->id]);
        });

        return back()->with('success', 'Pos biaya disimpan.');
    }

    public function destroy(Request $request, BsCogsSetup $setup): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        DB::transaction(function () use ($setup) {
            BsCogsSetupComponent::where('setup_id', $setup->id)->delete();
            $setup->delete();
        });

        return redirect()->route('bs.cogs.setupcogs.index')->with('success', 'Set Up COGS dihapus.');
    }

    // ── Pembacaan FO ────────────────────────────────────────────────────────────────────

    /** Nama basis data FO, sudah disaring. Kosong berarti tidak boleh dipakai. */
    private function dbFo(): string
    {
        $db = (string) config('bankloan.fo_database', '');

        return preg_match('/^[A-Za-z0-9_]+$/', $db) === 1 ? $db : '';
    }

    /**
     * @return array{0: list<array{id:int,kode:string,nama:string}>, 1: ?string}
     */
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
     * Unit beserta luasnya untuk satu cakupan.
     *
     * Unit BATAL dikeluarkan: ia bukan lagi bagian dari lahan yang dijual, jadi membebaninya
     * mengecilkan tarif seluruh unit lain. Status lain (TERSEDIA, BOOKING, TERJUAL, LUNAS, HOLD)
     * semuanya IKUT — lampiran membagi ke 199 unit, yaitu seluruh unit proyek, bukan hanya yang
     * sudah laku. Membaginya hanya ke unit terjual membuat tarif per m2 melonjak dan berubah
     * setiap ada booking baru.
     *
     * @return array{0: list<array<string,mixed>>, 1: ?string}
     */
    private function unitFo(int $lokasiId, string $tahap): array
    {
        $db = $this->dbFo();
        if ($db === '') {
            return [[], 'Nama basis data Front Office belum diatur (config bankloan.fo_database).'];
        }

        $sql = 'SELECT u.kode_unit, u.blok, u.no_unit, u.tahap, t.name AS tipe,'
            .' u.luas_tanah, u.luas_bangunan, u.luas_lebih_tanah'
            .' FROM `'.$db.'`.fo_units u'
            .' LEFT JOIN `'.$db.'`.fo_unit_types t ON t.id = u.unit_type_id'
            .' WHERE u.deleted_at IS NULL AND u.lokasi_id = ?'
            .' AND (u.status IS NULL OR u.status <> ?)';
        $bind = [$lokasiId, 'BATAL'];

        if ($tahap !== '') {
            $sql .= ' AND u.tahap = ?';
            $bind[] = $tahap;
        }

        $sql .= ' ORDER BY u.blok, u.no_unit, u.kode_unit';

        try {
            $rows = DB::select($sql, $bind);
        } catch (\Throwable $e) {
            report($e);

            return [[], 'Data unit tidak bisa dibaca dari Front Office, jadi budget per unit belum bisa dihitung.'];
        }

        return [array_map(fn ($r) => [
            'kode'             => (string) $r->kode_unit,
            'blok'             => $r->blok !== null ? (string) $r->blok : null,
            'no_unit'          => $r->no_unit !== null ? (string) $r->no_unit : null,
            'tipe'             => $r->tipe !== null ? (string) $r->tipe : null,
            'luas_tanah'       => (float) ($r->luas_tanah ?? 0),
            'luas_bangunan'    => (float) ($r->luas_bangunan ?? 0),
            'luas_lebih_tanah' => (float) ($r->luas_lebih_tanah ?? 0),
        ], $rows), null];
    }
}
