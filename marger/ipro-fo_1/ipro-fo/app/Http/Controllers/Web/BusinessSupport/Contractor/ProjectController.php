<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsProject;
use App\Domain\BusinessSupport\Models\BsProjectMuk;
use App\Domain\BusinessSupport\Services\UnitProject;
use App\Domain\BusinessSupport\Services\UsulProjectMuk;
use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Unit\Models\Unit;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\BusinessSupport\Contractor\Concerns\MapsUnitBast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Master Project (Contractor Management → Setup → Project). */
class ProjectController extends Controller
{
    use MapsUnitBast;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('contractor.project.view'), 403);

        return Inertia::render('BusinessSupport/Contractor/Project', [
            'projects' => BsProject::orderBy('code')->get([
                'id', 'code', 'name', 'dept', 'cluster', 'tahap', 'coa_account_id', 'is_active', 'notes',
            ]),
            // COA default project — filter SAMA dgn "Akun Konstruksi/CIP" di Register SPK
            // (ContractorController::create): postable + (kode 10104x ATAU tipe expense).
            'coaAccounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where(fn ($q) => $q->where('code', 'like', '10104%')->orWhere('type', 'expense'))
                ->orderBy('code')->get(['id', 'code', 'name']),
            // Master untuk dropdown Cluster — di-scope client-side ke sales project yang
            // kodenya cocok dgn kode project (fallback: tampilkan semua bila tak ada yg cocok).
            'salesProjects' => Project::orderBy('code')->get(['id', 'code', 'name']),
            'clusters'      => Cluster::orderBy('name')->get(['id', 'project_id', 'code', 'name']),

            // Pilihan "proyek" MUK diambil dari SALINAN KONTRAK, bukan daftar master
            // tersendiri: MUK tidak menyediakan endpoint daftar proyek, dan yang relevan
            // memang hanya proyek yang benar-benar punya kontrak di sini.
            'mukProjects'   => BsMukContract::query()
                ->selectRaw('proyek_kode, MAX(proyek_nama) AS proyek_nama, COUNT(*) AS jml')
                ->whereNotNull('proyek_kode')->where('proyek_kode', '!=', '')
                ->groupBy('proyek_kode')->orderBy('proyek_kode')->get(),

            // Pemetaan tersimpan, dikelompokkan per project supaya layar tidak perlu
            // menyaring sendiri 95 baris untuk tiap barisnya.
            'mukMap'        => BsProjectMuk::orderBy('muk_proyek_kode')
                ->get(['project_id', 'muk_proyek_kode', 'muk_proyek_nama'])
                ->groupBy('project_id'),

            // Usulan baris Project dari paket MUK yang belum dipetakan. MENGUSULKAN, bukan
            // memutuskan - seluruhnya melewati layar dan ditekan orang. Lihat UsulProjectMuk
            // untuk angka yang mendasarinya.
            'usulMuk'       => app(UsulProjectMuk::class)->untukYangBelumDipetakan(),
        ]);
    }

    /**
     * Detail Project → daftar unit di dalamnya + status Sudah/Belum BAST.
     *
     * UNITNYA DICARI LEWAT CLUSTER + TAHAP, bukan kode project. Master Sales hanya punya
     * SATU project ("JRES — Java Residence"); yang membedakan satu kumpulan unit dari yang
     * lain adalah cluster dan tahapnya. Kode project kontraktor (C3T4, C6T1) tidak pernah
     * ada padanannya di sana, sehingga pencocokan lewat kode selalu memulangkan nol unit —
     * dan layarnya menyuruh operator "samakan kode project" ke sesuatu yang tidak bisa
     * disamakan.
     *
     * Kolom Cluster menyimpan NAMA ("JAVA RESIDENCE KRIAN 3") sementara kode unit memakai
     * KODE ("JRK3-004-XIX-17"), jadi cluster-nya diresolusi dulu untuk mendapatkan kodenya.
     *
     * Tahap tidak punya kolom sendiri di cermin unit BO — ia hanya ada DI DALAM kode unit.
     * Karena itu penyaringnya awalan kode, dan tahap dinormalkan ke tiga angka: operator
     * mengetik "5" sementara kodenya berbunyi "005".
     */
    public function show(Request $request, BsProject $project, UnitProject $unitProject): Response
    {
        abort_unless($request->user()?->can('contractor.project.view'), 403);

        $sales = Project::where('code', $project->code)->first()
            ?? Project::whereRaw('LOWER(name) = ?', [mb_strtolower($project->name)])->first();

        // SATU definisi "unit milik project", dipakai bersama layar pemasangan unit —
        // lihat UnitProject. Dua penyaring terpisah suatu saat akan berbeda, dan yang satu
        // menawarkan unit yang tidak muncul di yang lain.
        $cluster = $unitProject->cluster($project);
        $tahap = trim((string) $project->tahap);

        $rows = collect();
        if ($cluster || $sales) {
            $units = ($cluster !== null
                    ? $unitProject->query($project)
                    // Tanpa cluster, jatuh ke cara lama: seluruh unit milik sales project.
                    : Unit::query()->where('project_id', $sales->id))
                ->with([
                    'cluster:id,code,name', 'block:id,code',
                    'unitType:id,code,name,lt,lb', 'project:id,code,name,location',
                ])
                ->orderBy('unit_code')
                ->get();
            $bastIds = $this->bastUnitIds($units->pluck('id')->all());
            $rows    = $units->map(fn (Unit $u) => $this->mapUnitRow($u, in_array($u->id, $bastIds, true)))->values();
        }

        return Inertia::render('BusinessSupport/Contractor/ProjectDetail', [
            'project'      => $project->only(['id', 'code', 'name', 'dept', 'cluster', 'tahap', 'coa_account_id', 'notes']),
            'salesProject' => $sales?->only(['id', 'code', 'name', 'location']),
            // Dikirim supaya layar bisa menyebut DASAR penyaringnya, bukan menyuruh
            // operator menyamakan kode project ke sesuatu yang tidak bisa disamakan.
            'cluster'      => $cluster?->only(['id', 'code', 'name']),
            'tahap'        => $tahap !== '' ? $tahap : null,
            'rows'         => $rows,
            'summary'      => [
                'total' => $rows->count(),
                'sudah' => $rows->where('bast_status', 'sudah')->count(),
                'belum' => $rows->where('bast_status', 'belum')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['contractor.project.create', 'businesssupport.setup.manage']), 403);
        $v = $this->validateData($request);
        $project = BsProject::create(array_merge($v, ['tenant_id' => tenant_id(), 'created_by' => $request->user()->id]));
        $this->simpanPemetaanMuk($request, $project);

        return back()->with('success', 'Project ditambahkan.');
    }

    public function update(Request $request, BsProject $project): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['contractor.project.update', 'businesssupport.setup.manage']), 403);
        $project->update($this->validateData($request));
        $this->simpanPemetaanMuk($request, $project);

        return back()->with('success', 'Project diperbarui.');
    }

    public function destroy(Request $request, BsProject $project): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['contractor.project.delete', 'businesssupport.setup.manage']), 403);
        $project->delete();

        return back()->with('success', 'Project dihapus.');
    }

    /**
     * Terima usulan yang sudah DISUNTING & DISETUJUI operator: buat baris Project, lalu
     * tempelkan paket-paket MUK-nya.
     *
     * Yang dikirim layar adalah hasil akhir, bukan "jalankan ulang penguraiannya": operator
     * boleh mengganti cluster, mengosongkan tahap, atau membuang paket dari satu kelompok.
     * Mengurai ulang di sini akan membuang suntingannya tanpa ada yang tahu.
     *
     * TAHAP BOLEH KOSONG. Paket infrastruktur dan site instruction mencakup satu cluster
     * penuh; kosong di layar detail sudah berarti "seluruh unit di cluster itu".
     */
    public function dariMuk(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['contractor.project.create', 'businesssupport.setup.manage']), 403);

        $v = $request->validate([
            'usul'                => ['required', 'array', 'min:1'],
            'usul.*.code'         => ['required', 'string', 'max:30'],
            'usul.*.name'         => ['required', 'string', 'max:150'],
            'usul.*.cluster'      => ['nullable', 'string', 'max:100'],
            'usul.*.tahap'        => ['nullable', 'string', 'max:100'],
            'usul.*.muk_proyek'   => ['required', 'array', 'min:1'],
            'usul.*.muk_proyek.*' => ['required', 'string', 'max:32'],
        ]);

        $dibuat = 0;
        $paket = 0;

        DB::transaction(function () use ($v, $request, &$dibuat, &$paket) {
            foreach ($v['usul'] as $u) {
                // Kode project unik per tenant. Yang sudah ada DIPAKAI ULANG, bukan dibuat
                // kembar: operator bisa menjalankan ini dua kali, dan kedua kalinya hanya
                // menambah paket yang belum tertempel.
                $project = BsProject::firstOrNew(['code' => $u['code']]);
                $baru = ! $project->exists;

                $project->fill([
                    'tenant_id'  => $project->tenant_id ?? tenant_id(),
                    'name'       => $u['name'],
                    'cluster'    => $u['cluster'] ?? null,
                    'tahap'      => $u['tahap'] ?? null,
                    'is_active'  => $project->exists ? $project->is_active : true,
                    'created_by' => $project->created_by ?? $request->user()?->id,
                ])->save();

                $dibuat += $baru ? 1 : 0;

                $nama = BsMukContract::whereIn('proyek_kode', $u['muk_proyek'])
                    ->pluck('proyek_nama', 'proyek_kode');

                foreach ($u['muk_proyek'] as $kode) {
                    // Paket yang sudah menempel di project LAIN dilewati, tidak dipindahkan
                    // diam-diam: memindahkannya menggeser biaya antar project tanpa ada yang
                    // memutuskan. Yang bentrok disebut pada pesan hasilnya.
                    $ada = BsProjectMuk::where('muk_proyek_kode', $kode)->first();
                    if ($ada && (int) $ada->project_id !== (int) $project->id) {
                        continue;
                    }

                    BsProjectMuk::updateOrCreate(
                        ['tenant_id' => tenant_id(), 'muk_proyek_kode' => $kode],
                        [
                            'project_id'      => $project->id,
                            'muk_proyek_nama' => $nama[$kode] ?? null,
                            'created_by'      => $request->user()?->id,
                        ],
                    );
                    $paket++;
                }
            }
        });

        return back()->with('success', $dibuat.' project dibuat, '.$paket.' paket MUK ditempelkan.');
    }

    /** @return array<string,mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'code'           => ['required', 'string', 'max:30'],
            'name'           => ['required', 'string', 'max:150'],
            'dept'           => ['nullable', 'string', 'max:100'],
            'cluster'        => ['nullable', 'string', 'max:100'],
            'tahap'          => ['nullable', 'string', 'max:100'],
            'coa_account_id' => ['nullable', 'integer', 'exists:bs_accounts,id'],
            'is_active'      => ['nullable', 'boolean'],
            'notes'          => ['nullable', 'string', 'max:200'],
        ]);
    }

    /**
     * Sinkronkan daftar "proyek" MUK milik sebuah Project: yang dicentang disimpan, yang
     * dilepas dihapus.
     *
     * TIDAK MENYENTUH APA PUN bila formulirnya tidak mengirim bagian ini — supaya pemanggil
     * lama (atau layar lain yang memperbarui project) tidak diam-diam menghapus pemetaan
     * yang sudah disusun orang.
     *
     * BENTROK DITOLAK DENGAN NAMA PROJECT-nya. Indeks unik di database sudah menjaga satu
     * paket hanya menempel pada satu project, tetapi tanpa pemeriksaan di sini operator cuma
     * melihat galat SQL mentah dan tidak tahu harus melepasnya dari mana.
     */
    private function simpanPemetaanMuk(Request $request, BsProject $project): void
    {
        if (! $request->has('muk_proyek')) {
            return;
        }

        $kode = collect($request->input('muk_proyek', []))
            ->map(fn ($k) => trim((string) $k))->filter()->unique()->values();

        $bentrok = BsProjectMuk::whereIn('muk_proyek_kode', $kode)
            ->where('project_id', '!=', $project->id)
            ->with('project:id,code')->get();

        if ($bentrok->isNotEmpty()) {
            // ValidationException, BUKAN abort(422): galatnya muncul di dalam formulir yang
            // sedang terbuka dan isian operator tidak hilang. abort() menampilkan layar galat
            // dan membuang seluruh pekerjaannya.
            throw ValidationException::withMessages([
                'muk_proyek' => 'Paket berikut sudah dipakai project lain: '.$bentrok
                    ->map(fn ($b) => $b->muk_proyek_kode.' → '.($b->project?->code ?? '?'))->implode(', '),
            ]);
        }

        // Nama paket ikut disalin supaya layar tetap terbaca ketika salinan kontrak sedang
        // kosong — mis. tepat sesudah dibuang dan belum ditarik ulang.
        $nama = BsMukContract::whereIn('proyek_kode', $kode)->pluck('proyek_nama', 'proyek_kode');

        BsProjectMuk::where('project_id', $project->id)
            ->whereNotIn('muk_proyek_kode', $kode->all())->delete();

        foreach ($kode as $k) {
            BsProjectMuk::updateOrCreate(
                ['tenant_id' => tenant_id(), 'muk_proyek_kode' => $k],
                [
                    'project_id'      => $project->id,
                    'muk_proyek_nama' => $nama[$k] ?? null,
                    'created_by'      => $request->user()?->id,
                ],
            );
        }
    }
}
