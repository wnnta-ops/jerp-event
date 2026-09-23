<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Cogs;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsCogsCategory;
use App\Domain\BusinessSupport\Models\BsCogsComponentApCategory;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsJournalLine;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Services\BukuBesarDokumen;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /**
     * Pilihan "Komponen COGS" pada Chart of Account: kunci komponen HPP => label yang dibaca
     * operator.
     *
     * KUNCINYA kunci HPP ('tanah'|'bangunan'|'sarana'|'permit'), BUKAN slug alamat layar
     * ('housing'|'infrastruktur'|'license-permit'). Itulah kosakata yang sudah dipakai
     * bs_cogs_component_ap_categories.component dan BsCogsCategory::COMPONENTS; memakai slug
     * di basis data berarti setiap pembacaan harus menerjemahkan lebih dulu, dan satu
     * terjemahan yang terlupa menghasilkan komponen yang diam-diam kosong.
     *
     * Diturunkan dari KOMPONEN, tidak ditulis ulang: menambah satu komponen kelak cukup di
     * satu tempat, dan layar COA ikut berubah tanpa disentuh.
     *
     * @return array<string,string>
     */
    public static function pilihanKomponen(): array
    {
        return collect(self::KOMPONEN)->mapWithKeys(fn (array $d) => [$d['cogs'] => $d['label']])->all();
    }

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

    public function __construct(private readonly BukuBesarDokumen $bukuBesar) {}

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

        return Inertia::render('BusinessSupport/Cogs/Komponen/Show', array_merge(
            $props,
            $def['sumber'] === 'ap' ? $this->dataAp($def, $filters) : $this->dataSpk($def, $filters),
            // Sumber KETIGA, berdampingan — bukan pengganti. Angkanya SENGAJA tidak pernah
            // dijumlahkan dengan dua sumber di atas: satu akun kewajiban di bagan akun ini
            // melayani beberapa kategori invoice sekaligus (mis. 20107070704 adalah akun debit
            // TANAH-C1, LICENSE-PERMIT-C1, DAN KONSTRUKSI-INFRA-C1), sehingga menjumlahkan
            // keduanya akan menghitung uang yang sama dua kali tanpa satu pun galat.
            $this->dataGl($def, $filters),
        ));
    }

    /**
     * Mutasi buku besar pada akun yang ditandai komponen ini (bs_accounts.cogs_component).
     *
     * ── Kenapa MASUK dan KELUAR ditampilkan sebagai DUA kolom, bukan satu angka bersih ──
     * Akun CIP yang biasa ditandai komponen tidak hanya didebit oleh biaya; ia juga DIKREDIT
     * oleh proses HPP itu sendiri — CogsService::postPersediaan() menulis "Dr Persediaan Unit /
     * Cr CIP". Kalau kolomnya satu angka bersih (debit − kredit), maka begitu HPP dikapitalisasi
     * angka komponennya meluncur ke NOL dan layar ini terlihat seperti kehilangan biaya, tanpa
     * satu pun galat. User pun memang meminta "mutasi masuk dan keluar" — keduanya ditampilkan
     * apa adanya, dan nettonya disebut terpisah.
     *
     * ── Kenapa jurnal yang belum Approved tetap DITAMPILKAN tetapi tidak dijumlahkan ──
     * Status jurnal bawaan di pemasangan ini adalah 'final', bukan 'posted'
     * (config/business_support.php), sedangkan Buku Besar & Neraca hanya menghitung 'posted'.
     * Menyaring 'posted' saja membuat layar ini kosong padahal biayanya sudah ada; menjumlahkan
     * semuanya membuat angkanya tidak pernah sama dengan Buku Besar. Jadi barisnya ditampilkan
     * seluruhnya dengan penanda 'efektif', dan RINGKASANNYA hanya menjumlah yang efektif —
     * pola yang sama dengan Rekap PPh Dipotong.
     *
     * ── Baris yang tidak bisa ditelusuri TIDAK dibuang ──
     * No. SPR / Nama Customer / Kode Unit hanya ada bila jurnalnya bisa ditelusuri balik ke
     * dokumen yang membawa booking. Baris yang tidak bisa ditelusuri tetap tampil dengan sel
     * kosong: membuangnya membuat jumlah layar berhenti sama dengan mutasi akunnya, dan
     * selisihnya tidak akan terlihat sebagai kesalahan.
     *
     * @param  array<string,mixed>  $def
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function dataGl(array $def, array $filters): array
    {
        $akun = BsAccount::query()
            ->where('cogs_component', $def['cogs'])
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        if ($akun->isEmpty()) {
            return ['glAkun' => [], 'glBaris' => [], 'glRingkas' => $this->glRingkasKosong()];
        }

        $lines = BsJournalLine::query()
            ->whereIn('account_id', $akun->pluck('id'))
            ->whereHas('journal', fn ($q) => $q
                ->when($filters['tahun'], fn ($w, $v) => $w->whereYear('journal_date', $v)))
            ->with('journal:id,journal_number,journal_date,reference,description,status')
            ->get()
            ->sortBy(fn ($l) => [(string) $l->journal?->journal_date, (int) $l->id])
            ->values();

        $dokumen = $this->bukuBesar->documentsFor(
            $lines->pluck('journal_id')->map(fn ($v) => (int) $v)->unique()->values()->all(),
        );

        $jejak = $this->jejakUnit($dokumen);
        $namaAkun = $akun->pluck('name', 'id');
        $proyek = $this->proyekPerAkun($akun->pluck('id')->all());

        $cari = mb_strtolower((string) $filters['search']);
        $no = 0;
        $baris = $lines->map(function ($l) use ($dokumen, $jejak, $namaAkun, $proyek, &$no) {
            $doc = $dokumen[(int) $l->journal_id] ?? null;
            $kunci = $doc !== null ? $doc['type'].':'.$doc['id'] : null;
            $j = $jejak[$kunci] ?? [];
            $p = $proyek[(int) $l->account_id] ?? [];

            return [
                'no'             => ++$no,
                'spr'            => $j['spr'] ?? null,
                'customer'       => $j['customer'] ?? null,
                'unit'           => $j['unit'] ?? ($p['unit'] ?? null),
                // Lokasi diambil dari cluster unitnya bila jurnalnya tertelusur; bila tidak,
                // dari Project yang memetakan akun ini (bs_projects.coa_account_id).
                'lokasi'         => $j['lokasi'] ?? ($p['cluster'] ?? null),
                // units tidak punya kolom tahap sama sekali — satu-satunya sumbernya Project.
                'tahap'          => $p['tahap'] ?? null,
                'tanggal'        => substr((string) $l->journal?->journal_date, 0, 10),
                'akun'           => (string) ($namaAkun[$l->account_id] ?? ''),
                'jurnal'         => $l->journal?->journal_number,
                'keterangan'     => $l->description ?: $l->journal?->description,
                'masuk'          => (float) $l->debit,
                'keluar'         => (float) $l->credit,
                'status'         => (string) ($l->journal?->status ?? ''),
                'status_label'   => BsJournal::STATUSES[$l->journal?->status] ?? (string) ($l->journal?->status ?? '—'),
                // Hanya jurnal Approved yang masuk Buku Besar & Neraca; yang belum tetap
                // DITAMPILKAN supaya rinciannya utuh, tetapi tidak ikut menaikkan ringkasan.
                'efektif'        => ($l->journal?->status ?? '') === 'posted',
            ];
        });

        if ($cari !== '') {
            $baris = $baris->filter(fn (array $r) => str_contains(mb_strtolower(implode(' ', [
                $r['spr'], $r['customer'], $r['unit'], $r['lokasi'], $r['tahap'], $r['akun'], $r['jurnal'], $r['keterangan'],
            ])), $cari))->values();
        }

        $efektif = $baris->where('efektif', true);

        return [
            'glAkun'  => $akun->map(fn ($a) => ['code' => $a->code, 'name' => $a->name])->values(),
            'glBaris' => $baris->values(),
            'glRingkas' => [
                'jumlah'       => $baris->count(),
                'masuk'        => round((float) $efektif->sum('masuk'), 2),
                'keluar'       => round((float) $efektif->sum('keluar'), 2),
                'netto'        => round((float) $efektif->sum('masuk') - (float) $efektif->sum('keluar'), 2),
                'belumEfektif' => $baris->count() - $efektif->count(),
                'tanpaJejak'   => $baris->filter(fn (array $r) => ($r['spr'] ?? null) === null)->count(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function glRingkasKosong(): array
    {
        return ['jumlah' => 0, 'masuk' => 0.0, 'keluar' => 0.0, 'netto' => 0.0, 'belumEfektif' => 0, 'tanpaJejak' => 0];
    }

    /**
     * Dokumen sumber → No. SPR, customer, unit, dan lokasi (cluster).
     *
     * Hanya dokumen yang benar-benar membawa booking/customer yang bisa ditelusuri; sisanya
     * memulangkan array kosong dan barisnya tampil dengan sel kosong.
     *
     * @param  array<int,array<string,mixed>>  $dokumen
     * @return array<string,array<string,mixed>>
     */
    private function jejakUnit(array $dokumen): array
    {
        $bookingIds = collect($dokumen)->pluck('booking_id')->filter()->unique()->values()->all();
        $customerIds = collect($dokumen)->pluck('customer_id')->filter()->unique()->values()->all();

        $booking = $bookingIds === [] ? collect() : DB::table('bookings as b')
            ->leftJoin('customers as c', 'c.id', '=', 'b.customer_id')
            ->leftJoin('units as u', 'u.id', '=', 'b.unit_id')
            ->leftJoin('clusters as cl', 'cl.id', '=', 'u.cluster_id')
            ->whereIn('b.id', $bookingIds)
            ->get(['b.id', 'b.booking_number', 'c.full_name', 'u.unit_code', 'cl.name as cluster_name'])
            ->keyBy('id');

        $customer = $customerIds === [] ? collect() : DB::table('customers')
            ->whereIn('id', $customerIds)->pluck('full_name', 'id');

        $out = [];
        foreach ($dokumen as $d) {
            $b = isset($d['booking_id']) ? ($booking[$d['booking_id']] ?? null) : null;
            $out[$d['type'].':'.$d['id']] = [
                'spr'      => $b?->booking_number,
                'customer' => $b?->full_name ?? ($customer[$d['customer_id'] ?? 0] ?? null),
                'unit'     => $b?->unit_code,
                'lokasi'   => $b?->cluster_name,
            ];
        }

        return $out;
    }

    /**
     * Project per akun (bs_projects.coa_account_id) → cluster / tahap / unit.
     *
     * Satu-satunya sumber kolom "Tahap": tabel units tidak punya kolom tahap sama sekali.
     * Dipakai juga sebagai cadangan Lokasi & Unit untuk baris yang jurnalnya tidak tertelusur.
     *
     * @param  list<int>  $accountIds
     * @return array<int,array<string,mixed>>
     */
    private function proyekPerAkun(array $accountIds): array
    {
        if ($accountIds === [] || ! Schema::hasTable('bs_projects')) {
            return [];
        }

        return DB::table('bs_projects')
            ->whereIn('coa_account_id', $accountIds)
            ->get(['coa_account_id', 'cluster', 'tahap', 'unit'])
            ->keyBy('coa_account_id')
            ->map(fn ($p) => ['cluster' => $p->cluster, 'tahap' => $p->tahap, 'unit' => $p->unit])
            ->all();
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
