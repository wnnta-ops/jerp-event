<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsCogsCategory;
use App\Domain\BusinessSupport\Models\BsCogsComponentApCategory;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * COGS → Komponen COGS: Tanah, Housing, Infrastruktur, License Permit.
 *
 * Setiap komponen menampilkan biaya yang MEMBENTUKNYA, diambil langsung dari modul sumbernya:
 *
 *   Tanah, License Permit   → Account Payable: invoice hutang pada Kategori Invoice yang
 *                             DITAUTKAN ke komponen itu (tabel bs_cogs_component_ap_categories)
 *   Housing, Infrastruktur  → Contractor Management: Surat Perintah Kerja dengan jenis
 *                             bangunan / sarana_prasarana — BsWorkOrder::JENIS sendiri sudah
 *                             melabeli keduanya "Housing" dan "Infrastruktur"
 *
 * Kunci 'cogs' pada definisi di bawah adalah kunci komponen HPP yang SUDAH ADA
 * (BsCogsCategory::COMPONENTS: tanah, bangunan, sarana, permit) — layar ini tidak membuat
 * konsep komponen kedua yang bisa berbeda dari yang dipakai CogsService::generateHpp().
 */
class CogsComponentController extends Controller
{
    public const KOMPONEN = [
        'tanah'          => ['label' => 'Tanah',          'cogs' => 'tanah',    'sumber' => 'ap'],
        'housing'        => ['label' => 'Housing',        'cogs' => 'bangunan', 'sumber' => 'spk', 'jenis' => 'bangunan'],
        'infrastruktur'  => ['label' => 'Infrastruktur',  'cogs' => 'sarana',   'sumber' => 'spk', 'jenis' => 'sarana_prasarana'],
        'license-permit' => ['label' => 'License Permit', 'cogs' => 'permit',   'sumber' => 'ap'],
    ];

    /** Pola nama/kode kategori invoice yang DISARANKAN untuk ditautkan — hanya saran di layar,
     *  tidak pernah ditautkan otomatis: menautkan biaya berdasarkan tebakan nama berarti satu
     *  salah tebak memindahkan uang ke komponen yang salah tanpa ada yang memutuskannya. */
    private const SARAN = [
        'tanah'  => '/TANAH|LAHAN/i',
        'permit' => '/LICENSE|LISENSI|PERMIT|IZIN|PERIZINAN|PBG|IMB/i',
    ];

    /** Invoice/SPK berstatus ini tidak ikut dijumlah — biaya yang dibatalkan bukan biaya. */
    private const BATAL_AP = ['void', 'cancelled'];

    private const BATAL_SPK = ['cancelled'];

    public function show(Request $request, string $komponen): Response
    {
        abort_unless($request->user()?->can('cogs.component.view'), 403);
        $def = self::KOMPONEN[$komponen] ?? abort(404);

        $filters = [
            'tahun'  => $request->filled('tahun') ? (int) $request->input('tahun') : null,
            'search' => trim((string) $request->input('search', '')),
        ];

        $props = [
            'komponen'  => ['slug' => $komponen, 'label' => $def['label'], 'cogs' => $def['cogs'], 'sumber' => $def['sumber'],
                'cogsLabel' => BsCogsCategory::COMPONENTS[$def['cogs']] ?? $def['label']],
            'daftar'    => collect(self::KOMPONEN)->map(fn ($d, $s) => ['slug' => $s, 'label' => $d['label']])->values(),
            'filters'   => $filters,
            'bisaAtur'  => (bool) $request->user()?->can('cogs.component.manage'),
        ];

        return Inertia::render('BusinessSupport/Cogs/Komponen/Show', array_merge($props,
            $def['sumber'] === 'ap' ? $this->dataAp($def, $filters) : $this->dataSpk($def, $filters)));
    }

    /** Simpan daftar Kategori Invoice yang ditautkan ke komponen (Tanah / License Permit). */
    public function simpanTautan(Request $request, string $komponen): RedirectResponse
    {
        abort_unless($request->user()?->can('cogs.component.manage'), 403);
        $def = self::KOMPONEN[$komponen] ?? abort(404);
        abort_unless($def['sumber'] === 'ap', 404);

        $v = $request->validate([
            'kategori'   => ['present', 'array'],
            // exists ber-tenant: Rule::exists melewati TenantScope, jadi tanpa filter ini
            // kategori milik tenant lain bisa ditautkan dengan menebak id-nya.
            'kategori.*' => ['integer', 'distinct', Rule::exists('bs_ap_categories', 'id')->where('tenant_id', tenant_id())],
        ]);
        $ids = array_values(array_unique(array_map('intval', $v['kategori'])));

        // Kategori yang sudah jadi milik komponen LAIN ditolak dengan pesan yang menyebut
        // pemiliknya. Unique index basis datanya juga menolak, tetapi sebagai galat SQL yang
        // tidak bisa dibaca siapa pun.
        $bentrok = BsCogsComponentApCategory::query()
            ->whereIn('ap_category_id', $ids)
            ->where('component', '!=', $def['cogs'])
            ->with('apCategory:id,code,name')
            ->get();
        if ($bentrok->isNotEmpty()) {
            $label = collect(self::KOMPONEN)->mapWithKeys(fn ($d) => [$d['cogs'] => $d['label']]);
            throw ValidationException::withMessages([
                'kategori' => $bentrok->map(fn ($b) => ($b->apCategory?->code ?? '#'.$b->ap_category_id)
                    .' sudah ditautkan ke '.($label[$b->component] ?? $b->component))->implode('; ').'.',
            ]);
        }

        DB::transaction(function () use ($def, $ids): void {
            BsCogsComponentApCategory::query()
                ->where('component', $def['cogs'])
                ->whereNotIn('ap_category_id', $ids ?: [0])
                ->delete();

            $sudah = BsCogsComponentApCategory::query()->where('component', $def['cogs'])->pluck('ap_category_id')->all();
            foreach (array_diff($ids, $sudah) as $id) {
                BsCogsComponentApCategory::create(['component' => $def['cogs'], 'ap_category_id' => $id]);
            }
        });

        return back()->with('success', 'Tautan Kategori Invoice untuk komponen '.$def['label'].' disimpan ('.count($ids).' kategori).');
    }

    // ── Sumber: Account Payable ────────────────────────────────────────────────────────

    private function dataAp(array $def, array $filters): array
    {
        $tertaut = BsCogsComponentApCategory::query()->where('component', $def['cogs'])->pluck('ap_category_id')->all();
        $milik = BsCogsComponentApCategory::query()->pluck('component', 'ap_category_id');   // id kategori → komponen
        $labelKomponen = collect(self::KOMPONEN)->mapWithKeys(fn ($d) => [$d['cogs'] => $d['label']]);
        $pola = self::SARAN[$def['cogs']] ?? null;

        $kategori = BsApCategory::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $tertaut ?: [0]))
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active'])
            ->map(function ($k) use ($milik, $labelKomponen, $def, $pola) {
                $pemilik = $milik[$k->id] ?? null;

                return [
                    'id'         => $k->id,
                    'code'       => $k->code,
                    'name'       => $k->name,
                    'aktif'      => (bool) $k->is_active,
                    'tertaut'    => $pemilik === $def['cogs'],
                    // Sudah dipakai komponen lain → tidak bisa dipilih di sini.
                    'milikLain'  => $pemilik !== null && $pemilik !== $def['cogs'] ? ($labelKomponen[$pemilik] ?? $pemilik) : null,
                    'disarankan' => $pemilik === null && $pola !== null && preg_match($pola, $k->code.' '.$k->name) === 1,
                ];
            })
            ->values();

        $dasar = BsApInvoice::query()
            ->whereIn('bs_ap_invoices.category_id', $tertaut ?: [0])
            ->whereNotIn('bs_ap_invoices.status', self::BATAL_AP)
            ->when($filters['tahun'], fn ($q, $t) => $q->whereYear('bs_ap_invoices.invoice_date', $t))
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $s = '%'.$filters['search'].'%';
                $q->where(fn ($w) => $w->where('bs_ap_invoices.invoice_number', 'like', $s)->orWhere('bs_ap_invoices.description', 'like', $s)
                    ->orWhere('s.name', 'like', $s)->orWhere('c.code', 'like', $s)->orWhere('c.name', 'like', $s));
            })
            ->leftJoin('bs_suppliers as s', 's.id', '=', 'bs_ap_invoices.supplier_id')
            ->leftJoin('bs_ap_categories as c', 'c.id', '=', 'bs_ap_invoices.category_id');

        // Total dihitung dari SELURUH baris yang lolos saringan, BUKAN dari halaman yang sedang
        // tampil — kalau tidak, angka total diam-diam berubah setiap kali pindah halaman.
        $total = (clone $dasar)->selectRaw('COUNT(*) AS jumlah, COALESCE(SUM(bs_ap_invoices.total),0) AS nilai, COALESCE(SUM(bs_ap_invoices.paid_amount),0) AS terbayar')->first();

        $baris = (clone $dasar)
            ->orderByDesc('bs_ap_invoices.invoice_date')->orderByDesc('bs_ap_invoices.id')
            ->select(['bs_ap_invoices.id', 'bs_ap_invoices.invoice_number', 'bs_ap_invoices.invoice_date', 'bs_ap_invoices.description', 'bs_ap_invoices.total', 'bs_ap_invoices.paid_amount', 'bs_ap_invoices.status',
                's.name as supplier', 'c.code as kategori_kode', 'c.name as kategori_nama'])
            ->paginate(100)
            ->withQueryString()
            ->through(fn ($r) => [
                'id'        => $r->id,
                'nomor'     => $r->invoice_number,
                'tanggal'   => $r->invoice_date ? substr((string) $r->invoice_date, 0, 10) : null,
                'supplier'  => $r->supplier,
                'kategori'  => trim(($r->kategori_kode ?? '').' — '.($r->kategori_nama ?? ''), ' —'),
                'keterangan'=> $r->description,
                'nilai'     => (float) $r->total,
                'terbayar'  => (float) $r->paid_amount,
                'sisa'      => max(0.0, (float) $r->total - (float) $r->paid_amount),
                'status'    => $r->status,
            ]);

        return [
            'kategori' => $kategori,
            'baris'    => $baris,
            'ringkas'  => [
                'jumlah'   => (int) ($total->jumlah ?? 0),
                'nilai'    => (float) ($total->nilai ?? 0),
                'terbayar' => (float) ($total->terbayar ?? 0),
                'sisa'     => max(0.0, (float) ($total->nilai ?? 0) - (float) ($total->terbayar ?? 0)),
            ],
        ];
    }

    // ── Sumber: Contractor Management (Surat Perintah Kerja) ───────────────────────────

    private function dataSpk(array $def, array $filters): array
    {
        /** @var Builder $dasar */
        $dasar = BsWorkOrder::query()
            ->where('bs_work_orders.jenis', $def['jenis'])
            ->whereNotIn('bs_work_orders.status', self::BATAL_SPK)
            ->when($filters['tahun'], fn ($q, $t) => $q->whereYear('bs_work_orders.spk_date', $t))
            ->when($filters['search'] !== '', function ($q) use ($filters) {
                $s = '%'.$filters['search'].'%';
                $q->where(fn ($w) => $w->where('bs_work_orders.spk_number', 'like', $s)->orWhere('bs_work_orders.title', 'like', $s)
                    ->orWhere('s.name', 'like', $s)->orWhere('bs_work_orders.cluster', 'like', $s)->orWhere('bs_work_orders.unit', 'like', $s));
            })
            ->leftJoin('bs_suppliers as s', 's.id', '=', 'bs_work_orders.supplier_id');

        // Nilai SPK = nilai kontrak + addendum. Menjumlah kontrak saja membuat SPK yang sudah
        // di-addendum terlihat lebih murah daripada yang sebenarnya dibayar.
        $total = (clone $dasar)->selectRaw('COUNT(*) AS jumlah,'
            .' COALESCE(SUM(bs_work_orders.contract_value),0) AS kontrak,'
            .' COALESCE(SUM(COALESCE(bs_work_orders.addendum_value,0)),0) AS addendum,'
            .' COALESCE(SUM(COALESCE(bs_work_orders.progress_value,0)),0) AS progres')->first();
        $nilai = (float) ($total->kontrak ?? 0) + (float) ($total->addendum ?? 0);

        $baris = (clone $dasar)
            ->orderByDesc('bs_work_orders.spk_date')->orderByDesc('bs_work_orders.id')
            ->select(['bs_work_orders.id', 'bs_work_orders.spk_number', 'bs_work_orders.spk_date', 'bs_work_orders.title', 'bs_work_orders.cluster', 'bs_work_orders.tahap', 'bs_work_orders.unit',
                'bs_work_orders.contract_value', 'bs_work_orders.addendum_value', 'bs_work_orders.progress_value', 'bs_work_orders.status', 's.name as kontraktor'])
            ->paginate(100)
            ->withQueryString()
            ->through(function ($r) {
                $nilai = (float) $r->contract_value + (float) ($r->addendum_value ?? 0);

                return [
                    'id'         => $r->id,
                    'nomor'      => $r->spk_number,
                    'tanggal'    => $r->spk_date ? substr((string) $r->spk_date, 0, 10) : null,
                    'kontraktor' => $r->kontraktor,
                    'lokasi'     => implode(' · ', array_filter([$r->cluster, $r->tahap, $r->unit], fn ($x) => (string) $x !== '')),
                    'judul'      => $r->title,
                    'kontrak'    => (float) $r->contract_value,
                    'addendum'   => (float) ($r->addendum_value ?? 0),
                    'nilai'      => $nilai,
                    'progres'    => (float) ($r->progress_value ?? 0),
                    'sisa'       => max(0.0, $nilai - (float) ($r->progress_value ?? 0)),
                    'status'     => $r->status,
                ];
            });

        return [
            'jenisLabel' => BsWorkOrder::JENIS[$def['jenis']] ?? $def['jenis'],
            'baris'      => $baris,
            'ringkas'    => [
                'jumlah'  => (int) ($total->jumlah ?? 0),
                'kontrak' => (float) ($total->kontrak ?? 0),
                'addendum'=> (float) ($total->addendum ?? 0),
                'nilai'   => $nilai,
                'progres' => (float) ($total->progres ?? 0),
                'sisa'    => max(0.0, $nilai - (float) ($total->progres ?? 0)),
            ],
        ];
    }
}
