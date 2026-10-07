<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsMukLokasi;
use App\Domain\BusinessSupport\Models\BsMukUnitPasangan;
use App\Domain\BusinessSupport\Models\BsProject;
use App\Domain\BusinessSupport\Models\BsProjectMuk;
use App\Domain\BusinessSupport\Services\UnitProject;
use App\Domain\BusinessSupport\Services\UsulPasanganUnit;
use App\Domain\Master\Unit\Models\Unit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pasangan Unit MUK (Contractor Management → Setup).
 *
 * MUK menyebut rumah dengan namanya sendiri ("CENDANA JRKC2 VI No. 15"); kami menyebutnya
 * dengan kode unit ("JRKC2-002-VI-15"). Layar ini tempat keduanya disandingkan, satu kali
 * per rumah, oleh orang.
 *
 * ── KENAPA HARUS ADA LAYAR, BUKAN DICOCOKKAN OTOMATIS ──
 *
 * Pasangan inilah yang menentukan biaya SPK sebuah rumah menempel ke rumah yang mana (lihat
 * AlokasiSpkUnit). Salah pasang tidak memunculkan galat apa pun: angkanya tetap rapi,
 * totalnya tetap sama dengan nilai SPK, hanya saja rumah A dibayari pekerjaan rumah B.
 * Usulan dari blok+nomor disodorkan supaya 1438 rumah tidak dipasangkan dari nol, tetapi
 * tidak ada satu pun yang tersimpan tanpa ditekan.
 *
 * ── DISARING PER PROJECT ──
 *
 * 1438 lokasi lawan 1362 unit sekaligus tidak bisa diperiksa siapa pun. Project membatasi
 * keduanya: lokasinya dari paket MUK yang menempel di project itu, unitnya dari cluster &
 * tahap project itu — definisi yang SAMA dengan layar detail Setup Project, lihat UnitProject.
 *
 * ── MENAMBAH, BUKAN MENYELARASKAN ──
 *
 * Simpan hanya MENAMBAH & MENGUBAH yang dikirim. Pasangan yang tidak disebut dibiarkan utuh,
 * dan melepas pasangan punya tombolnya sendiri. Bila layar ini menyelaraskan, satu halaman
 * yang terbuka sejak kemarin akan menghapus pekerjaan orang lain begitu ditekan Simpan.
 */
class MukPasanganController extends Controller
{
    public function index(Request $request, UnitProject $unitProject, UsulPasanganUnit $usulan): Response
    {
        abort_unless($request->user()?->can('contractor.project.view'), 403);

        $projects = BsProject::orderBy('code')->get(['id', 'code', 'name', 'cluster', 'tahap']);

        $project = $request->filled('project')
            ? $projects->firstWhere('id', $request->integer('project'))
            : null;

        if ($project === null) {
            return Inertia::render('BusinessSupport/Contractor/MukPasangan', [
                'projects' => $projects,
                'project'  => null,
                'lokasi'   => [],
                'units'    => [],
                'summary'  => ['lokasi' => 0, 'terpasang' => 0, 'usul' => 0, 'unit' => 0, 'unit_bebas' => 0],
            ]);
        }

        // Lokasi project ini: lewat paket MUK yang menempel padanya. Dikelompokkan per
        // lokasi_kode — satu rumah muncul di beberapa kontrak dengan lokasi_id berbeda
        // tetapi kode yang sama, dan yang dipasangkan adalah rumahnya, bukan barisnya.
        $paket = BsProjectMuk::where('project_id', $project->id)->pluck('muk_proyek_kode');
        $mukId = $paket->isEmpty()
            ? collect()
            : BsMukContract::whereIn('proyek_kode', $paket)->pluck('muk_id');

        $baris = $mukId->isEmpty()
            ? collect()
            : BsMukLokasi::whereIn('muk_id', $mukId)->orderBy('nama')->get();

        $lokasi = $baris->groupBy('lokasi_kode')->map(function ($grup) {
            /** @var BsMukLokasi $wakil */
            $wakil = $grup->first();
            $tebak = $wakil->tebakBlokNomor();

            return [
                'lokasi_kode' => (string) $wakil->lokasi_kode,
                'nama'        => $wakil->nama,
                'tipe_rumah'  => $wakil->tipe_rumah,
                'blok'        => $tebak['blok'],
                'no_rumah'    => $tebak['no_rumah'],
                // Berapa kontrak menyebut rumah ini — housing, lalu kanopi, lalu site
                // instruction. Angka ini yang menjelaskan kenapa pasangannya satu, bukan
                // sebanyak barisnya.
                'jml_kontrak' => $grup->count(),
            ];
        })->values();

        $units = $unitProject->query($project)
            ->with(['unitType:id,code,name,lt,lb'])
            ->orderBy('unit_code')
            ->get(['id', 'unit_code', 'unit_type_id']);

        // Pasangan diambil dari DUA arah: lewat kode lokasi (menandai barisnya) dan lewat
        // unit (menandai unit yang sudah terpakai — termasuk oleh lokasi project LAIN, yang
        // tidak muncul di daftar kiri sama sekali). Tanpa arah kedua, unit yang sudah
        // terpakai tetap tampak bebas dan Simpan berakhir dengan galat unik.
        $pasangan = BsMukUnitPasangan::query()
            ->where(fn ($q) => $q->whereIn('lokasi_kode', $lokasi->pluck('lokasi_kode'))
                ->orWhereIn('unit_id', $units->pluck('id')))
            ->get(['id', 'lokasi_kode', 'unit_id', 'nama_saat_dipasangkan']);

        $kodeUnit = Unit::whereIn('id', $pasangan->pluck('unit_id'))->pluck('unit_code', 'id');
        $perKode = $pasangan->keyBy('lokasi_kode');
        $perUnit = $pasangan->keyBy('unit_id');

        // Usulan hanya untuk yang masih bebas di KEDUA sisi. Mengusulkan unit yang sudah
        // terpakai berarti menyodorkan baris yang pasti ditolak saat disimpan.
        $usul = $usulan->pasangkan(
            $lokasi->reject(fn ($l) => $perKode->has($l['lokasi_kode']))->values()->all(),
            $units->reject(fn (Unit $u) => $perUnit->has($u->id))
                ->map(fn (Unit $u) => ['id' => $u->id, 'unit_code' => $u->unit_code])->values()->all(),
        );

        $barisLokasi = $lokasi->map(function (array $l) use ($perKode, $kodeUnit, $usul) {
            $ada = $perKode->get($l['lokasi_kode']);

            return $l + [
                'pasangan_id'  => $ada?->id,
                'unit_id'      => $ada?->unit_id,
                'unit_code'    => $ada ? ($kodeUnit[$ada->unit_id] ?? null) : null,
                'usul_unit_id' => $usul[$l['lokasi_kode']] ?? null,
            ];
        });

        return Inertia::render('BusinessSupport/Contractor/MukPasangan', [
            'projects' => $projects,
            'project'  => $project->only(['id', 'code', 'name', 'cluster', 'tahap']),
            'lokasi'   => $barisLokasi,
            'units'    => $units->map(fn (Unit $u) => [
                'id'        => $u->id,
                'unit_code' => $u->unit_code,
                'tipe'      => $u->unitType?->code,
                'lt'        => $u->unitType?->lt,
                'lb'        => $u->unitType?->lb,
                // Lokasi yang sudah memakai unit ini, bila ada — supaya pilihan di layar
                // bisa menyebutnya alih-alih sekadar hilang dari daftar.
                'dipakai_oleh' => $perUnit->get($u->id)?->lokasi_kode,
            ]),
            'summary'  => [
                'lokasi'     => $barisLokasi->count(),
                'terpasang'  => $barisLokasi->whereNotNull('unit_id')->count(),
                'usul'       => count($usul),
                'unit'       => $units->count(),
                'unit_bebas' => $units->reject(fn (Unit $u) => $perUnit->has($u->id))->count(),
            ],
        ]);
    }

    /**
     * Simpan pasangan yang sudah diputuskan operator.
     *
     * BENTROK DITOLAK DENGAN SEBUTANNYA, bukan dibiarkan jadi galat unik dari database.
     * Indeks unik di bs_muk_unit_pasangan memang menjaga satu unit hanya dipakai satu lokasi,
     * tetapi tanpa pemeriksaan di sini operator hanya melihat SQL mentah dan tidak tahu
     * pasangan mana yang harus dilepas lebih dulu.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'pasangan'               => ['required', 'array', 'min:1'],
            'pasangan.*.lokasi_kode' => ['required', 'string', 'max:64'],
            'pasangan.*.unit_id'     => ['required', 'integer', 'exists:units,id'],
        ]);

        $kirim = collect($v['pasangan']);

        // Dobel di dalam kirimannya sendiri ditolak lebih dulu. updateOrCreate memprosesnya
        // satu per satu dan yang terakhir menang — diam-diam, dan bukan keputusan siapa pun.
        $periksaDobel = [
            'unit_id'     => 'Satu unit dipasangkan ke lebih dari satu lokasi',
            'lokasi_kode' => 'Satu lokasi dipasangkan ke lebih dari satu unit',
        ];

        foreach ($periksaDobel as $kolom => $pesan) {
            $dobel = $kirim->duplicates($kolom);
            if ($dobel->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'pasangan' => $pesan.': '.$dobel->unique()->implode(', '),
                ]);
            }
        }

        // Unit yang sudah dipakai LOKASI LAIN. Diperiksa sebelum menulis apa pun supaya
        // kiriman yang sebagian bentrok tidak tersimpan separuh.
        $terpakai = BsMukUnitPasangan::whereIn('unit_id', $kirim->pluck('unit_id'))
            ->get(['lokasi_kode', 'unit_id'])
            ->filter(fn ($p) => $kirim->firstWhere('unit_id', $p->unit_id)['lokasi_kode'] !== $p->lokasi_kode);

        if ($terpakai->isNotEmpty()) {
            $kode = Unit::whereIn('id', $terpakai->pluck('unit_id'))->pluck('unit_code', 'id');

            throw ValidationException::withMessages([
                'pasangan' => 'Unit berikut sudah dipasangkan ke lokasi lain, lepas dulu di sana: '.$terpakai
                    ->map(fn ($p) => ($kode[$p->unit_id] ?? $p->unit_id).' ke '.$p->lokasi_kode)->implode(', '),
            ]);
        }

        $nama = BsMukLokasi::whereIn('lokasi_kode', $kirim->pluck('lokasi_kode'))
            ->pluck('nama', 'lokasi_kode');

        DB::transaction(function () use ($kirim, $nama, $request) {
            foreach ($kirim as $p) {
                BsMukUnitPasangan::updateOrCreate(
                    ['tenant_id' => tenant_id(), 'lokasi_kode' => $p['lokasi_kode']],
                    [
                        'unit_id'               => (int) $p['unit_id'],
                        'nama_saat_dipasangkan' => $nama[$p['lokasi_kode']] ?? null,
                        'dipasangkan_oleh'      => $request->user()?->id,
                        'dipasangkan_pada'      => now(),
                    ],
                );
            }
        });

        return back()->with('success', $kirim->count().' pasangan unit disimpan.');
    }

    /** Lepas satu pasangan. Alokasi SPK rumah itu ikut lepas — lihat AlokasiSpkUnit. */
    public function destroy(Request $request, BsMukUnitPasangan $pasangan): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $kode = $pasangan->lokasi_kode;
        $pasangan->delete();

        return back()->with('success', 'Pasangan lokasi '.$kode.' dilepas.');
    }
}
