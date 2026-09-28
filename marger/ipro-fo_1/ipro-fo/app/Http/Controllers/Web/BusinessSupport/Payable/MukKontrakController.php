<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsMukContractCogs;
use App\Domain\BusinessSupport\Services\Cogs2Sumber;
use App\Domain\BusinessSupport\Services\MukApi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsComponentController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Kontrak SPK dari MUK ERP — daftar beserta total yang sudah ditagih, dan rincian
 * penagihannya (COP Owner) saat satu kontrak dibuka.
 *
 * DAFTARNYA dari salinan lokal (bs_muk_contracts), bukan panggilan langsung: kalau layar
 * ini memanggil API MUK tiap kali dimuat, gangguan di sisi mereka menjadi layar kosong di
 * sini. Kesegarannya ditunjukkan lewat 'ditarik_pada' supaya orang tahu angka yang dilihat
 * setua apa.
 *
 * RINCIANNYA ditarik langsung saat dibuka, karena hanya satu kontrak dan pemakaiannya
 * sesekali — menyalin seluruh riwayat tagihan 101 kontrak untuk sesuatu yang jarang
 * dibuka lebih banyak ruginya. Bila MUK sedang mati, pesannya ditampilkan apa adanya dan
 * bagian kepala kontrak tetap terbaca.
 */
class MukKontrakController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.posting.view'), 403);

        $cari = trim((string) $request->input('cari'));
        $status = (string) $request->input('status');

        $q = BsMukContract::query()
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('no_kontrak', 'like', "%{$cari}%")
                ->orWhere('no_spk', 'like', "%{$cari}%")
                ->orWhere('proyek_nama', 'like', "%{$cari}%")
                ->orWhere('pelanggan_nama', 'like', "%{$cari}%")))
            ->when($status !== '', fn ($w) => $w->where('status', $status))
            ->orderByDesc('diperbarui');

        // Tautan komponen COGS per kontrak. Diambil SEKALI, bukan sekali per baris: daftarnya
        // pendek sedangkan tabelnya dipaginasi 25 baris.
        $tautan = BsMukContractCogs::query()->get(['muk_id', 'component', 'kategori'])->keyBy('muk_id');

        $rows = $q->paginate(25)->withQueryString()->through(function (BsMukContract $k) use ($tautan) {
            // ->get(), BUKAN $tautan[...]: akses kurung pada Collection memanggil offsetGet,
            // yang MELEMPAR "Undefined array key" untuk kunci yang tidak ada. Operator ?->
            // sesudahnya tidak pernah sempat bekerja — galatnya terjadi lebih dulu, dan
            // kontrak yang belum ditautkan ke komponen COGS adalah keadaan yang WAJAR,
            // bukan kesalahan. Seluruh layar Kontrak SPK 500 karenanya.
            $cogs = $tautan->get((int) $k->muk_id);

            return [
                'id' => $k->id,
                'muk_id' => $k->muk_id,
                'cogs' => $cogs?->component,
                'cogsKategori' => $cogs?->kategori,
                'no_kontrak' => $k->no_kontrak,
                'no_spk' => $k->no_spk,
                'jenis' => $k->jenis,
                'status' => $k->status,
                'proyek' => trim(($k->proyek_kode ? $k->proyek_kode.' — ' : '').$k->proyek_nama),
                'pelanggan' => $k->pelanggan_nama,
                'nilai_kontrak' => (float) $k->nilai_kontrak,
                'diopname' => (float) $k->kum_diopname,
                'ditagih' => (float) $k->kum_ditagih_neto,
                'diterima' => (float) $k->kum_diterima,
                'retensi' => (float) $k->kum_retensi_ditahan,
                'belum_ditagih' => $k->belum_ditagih,
                'janggal' => $k->angka_janggal,
            ];
        });

        return Inertia::render('BusinessSupport/Payable/MukKontrak/Index', [
            'kontrak' => $rows,
            'filters' => ['cari' => $cari, 'status' => $status],
            'ringkas' => $this->ringkas(),
            // Daftar pilihan datang dari SATU tempat (CogsComponentController::KOMPONEN),
            // sama dengan yang dipakai Chart of Account — bukan disalin di layar.
            'cogsOpsi' => CogsComponentController::pilihanKomponen(),
            'bisaLink' => (bool) $request->user()?->can('cogs.component.manage'),
            // Pilihan kategori datang dari Set Up Kategori COGS, dikelompokkan menurut kunci HPP
            // supaya layar tidak perlu tahu bahwa kedua modul memakai kosakata komponen yang
            // berbeda — penerjemahannya hanya ada di Cogs2Sumber.
            'kategoriOpsi' => collect(CogsComponentController::pilihanKomponen())->keys()
                ->mapWithKeys(fn (string $hpp) => [
                    $hpp => Cogs2Sumber::pilihanKategori()[Cogs2Sumber::dariHpp($hpp)] ?? [],
                ])->all(),
            // Komponen mana yang memang BERKOLOM kategori di Actual Costing COGS. Tanah tidak.
            'perluKategori' => collect(CogsComponentController::pilihanKomponen())->keys()
                ->mapWithKeys(fn (string $hpp) => [
                    $hpp => Cogs2Sumber::perluKategori((string) Cogs2Sumber::dariHpp($hpp)),
                ])->all(),
            'ditarik_pada' => optional(BsMukContract::max('ditarik_pada'))
                ? \Illuminate\Support\Carbon::parse(BsMukContract::max('ditarik_pada'))->translatedFormat('d M Y H:i')
                : null,
        ]);
    }

    /**
     * Tautkan / lepas tautan satu kontrak ke sebuah Komponen COGS.
     *
     * Izinnya cogs.component.manage — bukan ap.posting.view seperti layarnya. Menautkan
     * kontrak berarti menambah biaya ke perhitungan HPP; itu wewenang pemegang Komponen COGS,
     * bukan siapa pun yang boleh membaca daftar kontrak.
     *
     * Disimpan dengan kunci muk_id (identitas di MUK ERP), satu kontrak satu komponen:
     * kontrak yang tertaut ke dua komponen menyumbang nilainya dua kali ke HPP tanpa satu pun
     * galat yang memberi tahu.
     */
    public function simpanCogs(Request $request, BsMukContract $kontrak): RedirectResponse
    {
        abort_unless($request->user()?->can('cogs.component.manage'), 403);

        $v = $request->validate([
            'component' => ['present', 'nullable', Rule::in(array_keys(CogsComponentController::pilihanKomponen()))],
            'kategori'  => ['nullable', 'string', 'max:100'],
        ], [
            'component.in' => 'Komponen COGS yang dipilih tidak dikenal.',
        ]);

        if (($v['component'] ?? null) === null) {
            BsMukContractCogs::where('muk_id', $kontrak->muk_id)->delete();

            return back()->with('success', 'Tautan Komponen COGS untuk '.$kontrak->no_kontrak.' dilepas.');
        }

        // Kategori hanya bermakna pada komponen yang BERKOLOM KATEGORI di Actual Costing COGS
        // (Housing, Infrastruktur, License Permit). Untuk Tanah ia dikosongkan, bukan disimpan
        // diam-diam: kategori yang tersimpan pada komponen yang tidak memakainya adalah data
        // yang tidak pernah dibaca siapa pun, dan kelak menyesatkan yang membacanya.
        $cogs2 = Cogs2Sumber::dariHpp($v['component']);
        $kategori = $cogs2 !== null && Cogs2Sumber::perluKategori($cogs2)
            ? (trim((string) ($v['kategori'] ?? '')) ?: null)
            : null;

        BsMukContractCogs::updateOrCreate(
            ['tenant_id' => tenant_id(), 'muk_id' => (int) $kontrak->muk_id],
            [
                'component'      => $v['component'],
                'kategori'       => $kategori,
                'no_kontrak'     => $kontrak->no_kontrak,
                'linked_by'      => $request->user()?->id,
                'linked_by_name' => $request->user()?->name,
            ],
        );

        return back()->with('success', $kontrak->no_kontrak.' ditautkan ke komponen '
            .(CogsComponentController::pilihanKomponen()[$v['component']] ?? $v['component']).'.');
    }

    public function show(Request $request, BsMukContract $kontrak, MukApi $muk): Response
    {
        abort_unless($request->user()?->can('ap.posting.view'), 403);

        $tagihan = [];
        $galat = null;

        try {
            $tagihan = $muk->tagihan((int) $kontrak->muk_id);
        } catch (RuntimeException $e) {
            // Ditampilkan, bukan dilempar: kepala kontraknya dari salinan lokal dan tetap
            // berguna walau riwayat tagihannya sedang tak bisa diambil.
            $galat = $e->getMessage();
        }

        return Inertia::render('BusinessSupport/Payable/MukKontrak/Show', [
            'kontrak' => [
                'muk_id' => $kontrak->muk_id,
                'no_kontrak' => $kontrak->no_kontrak,
                'no_spk' => $kontrak->no_spk,
                'jenis' => $kontrak->jenis,
                'status' => $kontrak->status,
                'proyek' => trim(($kontrak->proyek_kode ? $kontrak->proyek_kode.' — ' : '').$kontrak->proyek_nama),
                'pelanggan' => $kontrak->pelanggan_nama,
                'tanggal' => optional($kontrak->tanggal)->toDateString(),
                'mulai' => optional($kontrak->mulai)->toDateString(),
                'selesai' => optional($kontrak->selesai)->toDateString(),
                'nilai_kontrak' => (float) $kontrak->nilai_kontrak,
                'retensi_persen' => (float) $kontrak->retensi_persen,
                'diopname' => (float) $kontrak->kum_diopname,
                'ditagih' => (float) $kontrak->kum_ditagih_neto,
                'diterima' => (float) $kontrak->kum_diterima,
                'retensi' => (float) $kontrak->kum_retensi_ditahan,
                'belum_ditagih' => $kontrak->belum_ditagih,
                'janggal' => $kontrak->angka_janggal,
                'ditarik_pada' => optional($kontrak->ditarik_pada)->translatedFormat('d M Y H:i'),
            ],
            'tagihan' => array_map(fn (array $b) => [
                'no_tagihan' => $b['no_tagihan'] ?? null,
                'jenis' => $b['jenis'] ?? null,
                'tanggal' => $b['tanggal'] ?? null,
                'jatuh_tempo' => $b['jatuh_tempo'] ?? null,
                'dpp' => (float) ($b['dpp'] ?? 0),
                'ppn' => (float) ($b['ppn'] ?? 0),
                'pph' => (float) ($b['pph'] ?? 0),
                'total' => (float) ($b['total'] ?? 0),
                'diterima' => (float) ($b['diterima'] ?? 0),
                'status' => $b['status'] ?? null,
                'opname' => array_map(fn (array $o) => [
                    'no_opname' => $o['no_opname'] ?? null,
                    'tanggal' => $o['tanggal'] ?? null,
                    'bruto' => (float) ($o['bruto'] ?? 0),
                ], (array) ($b['opname'] ?? [])),
            ], $tagihan),
            'galat' => $galat,
        ]);
    }

    /** @return array<string, float|int> */
    private function ringkas(): array
    {
        $a = BsMukContract::selectRaw(
            'COUNT(*) n, SUM(nilai_kontrak) nilai, SUM(kum_diopname) opname,
             SUM(kum_ditagih_neto) ditagih, SUM(kum_diterima) diterima,
             SUM(kum_retensi_ditahan) retensi'
        )->first();

        return [
            'jumlah' => (int) ($a->n ?? 0),
            'nilai_kontrak' => (float) ($a->nilai ?? 0),
            'diopname' => (float) ($a->opname ?? 0),
            'ditagih' => (float) ($a->ditagih ?? 0),
            'diterima' => (float) ($a->diterima ?? 0),
            'retensi' => (float) ($a->retensi ?? 0),
        ];
    }
}
