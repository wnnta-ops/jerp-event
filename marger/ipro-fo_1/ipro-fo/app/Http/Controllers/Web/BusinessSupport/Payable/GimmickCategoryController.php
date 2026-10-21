<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsGimmickCategory;
use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kategori Gimmick — fungsi sama dengan Kategori Invoice (ApCategoryController),
 * tapi tabelnya sendiri (bs_gimmick_categories).
 *
 * Satu kategori boleh punya BEBERAPA akun debit dan BEBERAPA akun kredit, masing-masing
 * berporsi persen (jumlah per sisi = 100). Kategori juga bisa ditautkan ke satu kolom
 * Rincian Biaya di Master Data KPR agar biaya yang diposting dari FO otomatis muncul di sana.
 */
class GimmickCategoryController extends Controller
{
    /** Toleransi pembulatan saat memeriksa jumlah porsi per sisi. */
    private const PORTION_EPSILON = 0.0001;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.category.view'), 403);

        // cluster dimuat withTrashed: kategori yang menunjuk cluster sudah dipensiunkan akan
        // memulangkan relasi kosong bila disaring, sehingga barisnya berbohong "Semua cluster"
        // padahal cluster_id-nya terisi dan posting justru tidak lagi menemukannya.
        $rows = BsGimmickCategory::with(['debitAccount:id,code,name', 'payableAccount:id,code,name', 'accounts.account:id,code,name',
            'cluster' => fn ($q) => $q->withTrashed()->select('id', 'code', 'name', 'deleted_at')])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            // Kategori sejenis dari cluster berbeda berdampingan, supaya perbedaan akunnya
            // langsung terbaca alih-alih harus dicari di dua tempat pada daftar.
            ->orderBy('name')->orderByRaw('cluster_id is not null')->orderBy('cluster_id')->get()
            ->map(function ($c) {
                $side = fn (string $s) => $c->accounts->where('side', $s)->values()->map(fn ($a) => [
                    'account_id' => $a->account_id,
                    'portion'    => (float) $a->portion,
                    'label'      => $a->account ? $a->account->code.' - '.$a->account->name : null,
                ])->all();

                return [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_active' => $c->is_active,
                    // Cluster kosong = berlaku untuk semua cluster (cadangan).
                    'cluster_id'    => $c->cluster_id,
                    // Akhiran dikatakan apa adanya: cluster yang sudah dihapus di FO tidak boleh
                    // terbaca sama seperti cluster yang masih hidup.
                    'cluster_label' => $c->cluster
                        ? trim($c->cluster->code.' — '.$c->cluster->name).($c->cluster->trashed() ? ' (nonaktif di FO)' : '')
                        : null,
                    'kpr_biaya_key'   => $c->kpr_biaya_key,
                    'kpr_biaya_label' => $c->kpr_biaya_key ? (BsGimmickCategory::KPR_BIAYA_COLUMNS[$c->kpr_biaya_key] ?? null) : null,
                    'sync_kpr'        => (bool) $c->sync_kpr,
                    // Akun utama (baris pertama tiap sisi) — dipertahankan untuk pembaca lama.
                    'debit_account_id' => $c->debit_account_id, 'payable_account_id' => $c->payable_account_id,
                    'debit'   => $c->debitAccount?->code.' - '.$c->debitAccount?->name,
                    'payable' => $c->payableAccount?->code.' - '.$c->payableAccount?->name,
                    'debits'  => $side('debit'),
                    'credits' => $side('credit'),
                ];
            });

        // Daftar cluster BUKAN master milik BO — ia cerminan master Lokasi di FO
        // (Setup Unit → Lokasi), yang di FO memang disebut cluster dan tampil di Master Unit.
        // Karena itu tidak ada layar tambah cluster di sini; menambahnya dilakukan di FO.
        $dipakai = BsGimmickCategory::whereNotNull('cluster_id')->distinct()->pluck('cluster_id');

        // Pilihan = cluster hidup DITAMBAH cluster yang masih dirujuk kategori mana pun.
        // Tanpa yang kedua, kategori yang menunjuk cluster sudah dipensiunkan akan dibuka
        // dengan pilihan kosong, dan menyimpannya kembali diam-diam mengubahnya menjadi
        // "berlaku semua cluster" — kategori itu lalu menyerap GMC dari seluruh cluster.
        $clusters = Cluster::withTrashed()
            ->where(fn ($q) => $q->whereNull('deleted_at')->orWhereIn('id', $dipakai))
            ->orderBy('code')->get(['id', 'code', 'name', 'deleted_at'])
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name,
                'nonaktif' => $c->trashed(),
                'label' => trim($c->code.' — '.$c->name).($c->trashed() ? ' (nonaktif di FO)' : '')]);

        // Cakupan hanya boleh menghitung cluster yang masih hidup: cluster yang sudah
        // dipensiunkan di FO akan selamanya diteriaki "belum ada kategori" padahal memang
        // tidak akan pernah ada, dan peringatan yang tidak bisa dituntaskan akan diabaikan
        // orang — termasuk saat ia akhirnya benar.
        $clusterHidup = $clusters->reject(fn ($c) => $c['nonaktif'])->values();

        // Cakupan kolom Rincian Biaya Master Data KPR — supaya ketahuan kalau ada yang terlewat.
        // SENGAJA lepas dari filter pencarian: kalau dihitung dari daftar yang tersaring, mengetik
        // di kotak cari akan membuat kolom yang sebenarnya sudah terpetakan terlihat "belum ada".
        // Hanya kategori yang sinkronnya HIDUP yang boleh dihitung menutupi sebuah kolom:
        // kategori terpetakan tapi sinkronnya dimatikan tidak akan pernah mengisi kolom itu,
        // jadi menghitungnya akan membuat cakupan tampak aman padahal tidak.
        // Cakupan dihitung dari TAUTANNYA: begitu sebuah kolom punya kategori, ia tidak lagi
        // diperingatkan. Sakelar sinkron tidak lagi ikut menentukan cakupan — peringatan
        // "belum ada kategori" seharusnya menjawab pertanyaan "kolom ini sudah dipegang
        // siapa?", bukan "apakah sudah jalan?". Pertanyaan kedua tetap dijawab, tapi di baris
        // kategorinya sendiri lewat penanda sinkron — di sanalah sakelarnya bisa dihidupkan.
        //
        // Sejak kategori bercluster, cakupannya dihitung PER CLUSTER: satu kategori tidak lagi
        // otomatis membuat kolomnya aman, karena cluster lain bisa saja belum punya kategori
        // sendiri. Kategori tanpa cluster berlaku untuk semua cluster, jadi ia menutup sisanya.
        $mapped = BsGimmickCategory::whereNotNull('kpr_biaya_key')
            ->orderBy('code')->get(['code', 'kpr_biaya_key', 'sync_kpr', 'cluster_id'])->groupBy('kpr_biaya_key');
        $coverage = collect(BsGimmickCategory::KPR_BIAYA_COLUMNS)
            ->map(function ($label, $key) use ($mapped, $clusterHidup) {
                $isi = collect($mapped->get($key, []));
                // Cluster yang sudah punya kategori sendiri untuk kolom ini.
                $terpegang = $isi->pluck('cluster_id')->filter()->unique();
                // Kategori tanpa cluster berlaku untuk semua cluster, jadi ia menutup sisanya.
                $adaCadangan = $isi->whereNull('cluster_id')->isNotEmpty();

                return [
                    'key'        => $key,
                    'label'      => $label,
                    'categories' => $isi->pluck('code')->values()->all(),
                    // Berapa di antaranya yang sinkronnya benar-benar hidup — dipakai layar
                    // untuk membedakan "sudah dipegang" dari "sudah akan terisi".
                    'aktif'      => $isi->where('sync_kpr', true)->count(),
                    // Cluster yang belum terpegang DAN tidak tertutup kategori cadangan.
                    // Inilah yang membuat kolom terlihat aman padahal satu cluster terlewat:
                    // tanpa rincian ini, satu kategori saja sudah membuat kolomnya hijau.
                    'semua_cluster'  => $adaCadangan,
                    'cluster_isi'    => $clusterHidup->whereIn('id', $terpegang->all())->pluck('code')->values()->all(),
                    'cluster_kosong' => $adaCadangan || $isi->isEmpty()
                        ? []
                        : $clusterHidup->whereNotIn('id', $terpegang->all())->pluck('code')->values()->all(),
                ];
            })->values();

        return Inertia::render('BusinessSupport/Payable/GimmickCategories', [
            'categories' => $rows,
            'filters'    => $request->only('search'),
            // Hanya akun transaksi (is_postable) — CoA induk/header tidak ditampilkan.
            'accounts'   => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'clusters'         => $clusters,
            'defaultPayable'   => BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id'),
            'kprBiayaColumns'  => collect(BsGimmickCategory::KPR_BIAYA_COLUMNS)->map(fn ($label, $key) => ['id' => $key, 'label' => $label])->values(),
            'kprBiayaCoverage' => $coverage,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['ap.category.create', 'businesssupport.setup.manage']), 403);

        $v = $this->data($request);
        DB::transaction(function () use ($v): void {
            $cat = BsGimmickCategory::create($this->headerOf($v));
            $this->syncAccounts($cat, $v);
        });

        return back()->with('success', 'Kategori gimmick ditambahkan.');
    }

    public function update(Request $request, BsGimmickCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['ap.category.update', 'businesssupport.setup.manage']), 403);

        $v = $this->data($request, $category->id);
        DB::transaction(function () use ($category, $v): void {
            $category->update($this->headerOf($v));
            $this->syncAccounts($category, $v);
        });

        return back()->with('success', 'Kategori gimmick diperbarui.');
    }

    public function destroy(Request $request, BsGimmickCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['ap.category.delete', 'businesssupport.setup.manage']), 403);
        $category->delete();

        return back()->with('success', 'Kategori gimmick dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        // Kode unik PER CLUSTER: "BIAYA KPR" boleh ada di tiap cluster dengan akun sendiri,
        // tetapi tidak boleh dua kali di cluster yang sama. Kategori tanpa cluster (cadangan
        // untuk semua cluster) juga hanya boleh satu per kode.
        $clusterId = $request->input('cluster_id') ?: null;

        // Cluster yang SEDANG tersimpan pada kategori ini — dipakai memberi kelonggaran pada
        // validasi di bawah supaya kategori lama tidak terkunci saat Lokasinya dihapus di FO.
        $clusterTersimpan = $id ? BsGimmickCategory::whereKey($id)->value('cluster_id') : null;

        $v = $request->validate([
            // exists:clusters,id yang polos meloloskan cluster milik tenant lain, yang lalu
            // tampil sebagai "Semua cluster" di daftar padahal cluster_id-nya terisi — dan
            // posting tidak akan pernah menemukannya lagi, sehingga biayanya jatuh ke kategori
            // cadangan tanpa satu pun peringatan.
            //
            // Cluster yang sudah dipensiunkan ditolak untuk pilihan BARU, tetapi yang SEDANG
            // tersimpan pada kategori ini tetap diloloskan. Tanpa pengecualian itu, menghapus
            // satu Lokasi di FO mengunci setiap kategori yang menunjuknya: mengganti namanya
            // saja pun gagal, karena kolom yang tidak disentuh operator ikut divalidasi.
            'cluster_id'    => ['nullable', 'integer', function ($attr, $value, $gagal) use ($clusterTersimpan) {
                if ($value === null) {
                    return;   // tanpa cluster = berlaku semua cluster, itu sah
                }
                // Batas tenant datang dari TenantScope milik model, bukan dari
                // ->where('tenant_id', tenant_id()): helper itu memulangkan null di luar
                // konteks request (console, job, webhook), dan filter "tenant_id IS NULL"
                // menolak SEMUA cluster — layarnya jadi buntu di tempat yang tidak terduga.
                $sah = Cluster::withTrashed()->whereKey($value)
                    ->where(fn ($q) => $q->whereNull('deleted_at')
                        ->when($clusterTersimpan, fn ($w) => $w->orWhere('id', $clusterTersimpan)))
                    ->exists();
                if (! $sah) {
                    $gagal('Cluster yang dipilih tidak ada, atau Lokasinya sudah dihapus di FO.');
                }
            }],
            'code'          => ['required', 'string', 'max:40', Rule::unique('bs_gimmick_categories', 'code')
                ->ignore($id)->where('tenant_id', tenant_id())
                ->where(fn ($q) => $clusterId === null ? $q->whereNull('cluster_id') : $q->where('cluster_id', $clusterId))],
            'name'          => ['required', 'string', 'max:150'],
            'kpr_biaya_key' => ['nullable', 'string', Rule::in(array_keys(BsGimmickCategory::KPR_BIAYA_COLUMNS))],
            'sync_kpr'      => ['boolean'],
            'is_active'     => ['boolean'],
            // Multi-akun per sisi; menggantikan pasangan akun tunggal yang lama.
            'debits'               => ['required', 'array', 'min:1'],
            'debits.*.account_id'  => ['required', 'integer', 'exists:bs_accounts,id'],
            'debits.*.portion'     => ['required', 'numeric', 'min:0.0001', 'max:100'],
            'credits'              => ['required', 'array', 'min:1'],
            'credits.*.account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'credits.*.portion'    => ['required', 'numeric', 'min:0.0001', 'max:100'],
        ]);

        foreach (['debits' => 'Akun Debit', 'credits' => 'Akun Kredit'] as $key => $label) {
            $ids = array_column($v[$key], 'account_id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages([$key => "{$label}: satu akun tidak boleh dipakai dua kali."]);
            }
            $sum = round(array_sum(array_column($v[$key], 'portion')), 4);
            if (abs($sum - 100) > self::PORTION_EPSILON) {
                throw ValidationException::withMessages([$key => "{$label}: jumlah porsi harus 100% (sekarang ".rtrim(rtrim(number_format($sum, 4, ',', '.'), '0'), ',').'%).']);
            }
        }

        // Akun yang sama di kedua sisi membuat jurnalnya saling meniadakan — tolak sejak setup.
        if (array_intersect(array_column($v['debits'], 'account_id'), array_column($v['credits'], 'account_id'))) {
            throw ValidationException::withMessages(['credits' => 'Akun yang sama tidak boleh ada di sisi Debit sekaligus Kredit.']);
        }

        return $v;
    }

    /** Kolom header — akun utama = baris pertama tiap sisi (kompatibilitas pembaca lama). */
    private function headerOf(array $v): array
    {
        return [
            'cluster_id'         => $v['cluster_id'] ?? null,
            'code'               => $v['code'],
            'name'               => $v['name'],
            'kpr_biaya_key'      => $v['kpr_biaya_key'] ?? null,
            // Sinkron hanya mungkin bila kolom tujuannya dipilih — tanpa kolom, tak ada
            // yang bisa diisi, jadi sakelarnya dipaksa mati agar tidak menyesatkan di layar.
            'sync_kpr'           => ($v['kpr_biaya_key'] ?? null) ? (bool) ($v['sync_kpr'] ?? true) : false,
            'is_active'          => $v['is_active'] ?? true,
            'debit_account_id'   => (int) $v['debits'][0]['account_id'],
            'payable_account_id' => (int) $v['credits'][0]['account_id'],
        ];
    }

    private function syncAccounts(BsGimmickCategory $category, array $v): void
    {
        $category->accounts()->delete();
        foreach (['debit' => 'debits', 'credit' => 'credits'] as $side => $key) {
            foreach (array_values($v[$key]) as $i => $row) {
                $category->accounts()->create([
                    'side'       => $side,
                    'account_id' => (int) $row['account_id'],
                    'portion'    => round((float) $row['portion'], 4),
                    'sort_order' => $i,
                ]);
            }
        }
    }
}
