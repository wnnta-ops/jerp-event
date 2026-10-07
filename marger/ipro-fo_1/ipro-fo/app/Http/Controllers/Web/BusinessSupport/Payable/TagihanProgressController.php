<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Models\BsMukContractCogs;
use App\Domain\BusinessSupport\Models\BsMukTagihan;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\BarisSpkMuk;
use App\Domain\BusinessSupport\Services\RincianUnitSpk;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tagihan Progress Project — daftar COP Owner dari MUK, siap diposting ke AP.
 *
 * Inilah pintu masuk tagihan MUK ke pembukuan kita. Sebelum layar ini, tagihan mereka hanya
 * bisa dilihat; tidak ada jalan menjadikannya hutang yang dibayar lewat Kuitansi.
 *
 * ── SATU BARIS = SATU TAGIHAN, BUKAN SATU KONTRAK ──
 *
 * Tanggal Tagihan dan Tanggal Jatuh Tempo hanya ada pada tagihan, dan yang diposting ke AP
 * juga tagihannya — satu per satu, seperti progress SPK.
 *
 * KARENA ITU kolom DPP, PPN, dan PPh 4(2) di layar ini adalah nilai TAGIHANNYA, bukan nilai
 * kontraknya. Kalau yang dipakai nilai kontrak, setiap kali satu tagihan diposting seluruh
 * nilai SPK akan terbukukan sebagai hutang — dan diulang untuk tiap tagihan berikutnya.
 *
 * Ini juga satu-satunya tempat PPN & PPh 4(2) benar-benar ada: endpoint kontrak MUK tidak
 * memuat keduanya sama sekali (lihat BarisSpkMuk), sedangkan tiap COP membawanya.
 *
 * Yang tetap datang dari kontraknya — dan dari definisi yang sama dengan Kontrak SPK (MUK)
 * serta Informasi CTC — adalah No. SPK, Lokasi, Tahap, dan Kategori COGS.
 *
 * ── POSTING HANYA MEMASUKKAN KE ANTREAN ──
 *
 * "Posting ke AP" TIDAK menerbitkan hutang. Ia memasukkan tagihan itu ke antrean Posting
 * Invoice SPK, tempat Finance menekan Generate Invoice. Dua tangan untuk satu hutang: yang
 * memilih tagihan dan yang menerbitkannya tidak harus orang yang sama, dan jalur persetujuan
 * yang sudah ada tidak dilangkahi.
 */
class TagihanProgressController extends Controller
{
    public function index(Request $request, BarisSpkMuk $baris): Response
    {
        abort_unless($request->user()?->can('ap.posting.view'), 403);

        $cari = trim((string) $request->input('cari'));
        $status = (string) $request->input('status', 'belum');

        $q = BsMukTagihan::query()
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('no_tagihan', 'like', "%{$cari}%")
                ->orWhere('no_kontrak', 'like', "%{$cari}%")))
            // Bawaannya YANG BELUM DIPOSTING — itu pekerjaan yang menunggu. Yang sudah
            // tetap bisa dilihat, supaya orang bisa memastikan sesuatu sudah masuk antrean
            // tanpa harus menebak.
            ->when($status === 'belum', fn ($w) => $w->whereNull('diposting_pada'))
            ->when($status === 'sudah', fn ($w) => $w->whereNotNull('diposting_pada'))
            ->orderByDesc('tanggal')->orderBy('no_tagihan');

        $tagihan = $q->paginate(50)->withQueryString();

        $mukId = collect($tagihan->items())->pluck('muk_id')->unique();

        $kontrak = BsMukContract::whereIn('muk_id', $mukId)->get()->keyBy('muk_id');
        $petaProyek = $baris->petaProyek();
        $cogs = BsMukContractCogs::whereIn('muk_id', $mukId)->get(['muk_id', 'component', 'kategori'])->keyBy('muk_id');

        // Tagihan yang invoice-nya SUDAH terbit — supaya layar bisa membedakan "menunggu di
        // antrean" dari "sudah jadi hutang", dua keadaan yang sangat berbeda bagi Finance.
        $sudahInvoice = BsApInvoice::where('source_type', 'muk_tagihan')
            ->whereIn('source_id', collect($tagihan->items())->pluck('id'))
            ->pluck('invoice_number', 'source_id');

        $rows = $tagihan->through(function (BsMukTagihan $t) use ($kontrak, $petaProyek, $cogs, $baris, $sudahInvoice) {
            $k = $kontrak->get((int) $t->muk_id);
            $kolom = $k !== null
                ? $baris->kolom($k, $petaProyek->get((string) $k->proyek_kode))
                : ['no_spk' => null, 'lokasi' => null, 'tahap' => null];
            $c = $cogs->get((int) $t->muk_id);

            return [
                'id' => $t->id,
                'muk_id' => $t->muk_id,
                'kontrak_id' => $k?->id,
                'no_spk' => $kolom['no_spk'] ?? $t->no_kontrak,
                'lokasi' => $kolom['lokasi'] ?? null,
                'tahap' => $kolom['tahap'] ?? null,
                'no_tagihan' => $t->no_tagihan,
                'tanggal' => optional($t->tanggal)->toDateString(),
                'jatuh_tempo' => optional($t->jatuh_tempo)->toDateString(),
                'dpp' => (float) $t->dpp,
                'ppn' => (float) $t->ppn,
                'pph' => (float) $t->pph,
                'nilai_tagihan' => $t->nilai_tagihan,
                // Selisih antara `total` kiriman MUK dan DPP+PPN−PPh. Ditandai, bukan
                // dirapikan: arti `total` mereka belum dipastikan, dan kalau dugaan kita
                // keliru, barisnyalah yang harus memberi tahu.
                'selisih_total_muk' => $t->selisihTotalMuk(),
                'kategori_cogs' => $c?->kategori,
                'komponen_cogs' => $c?->component,
                'diposting_pada' => optional($t->diposting_pada)->translatedFormat('d M Y H:i'),
                'invoice_number' => $sudahInvoice[$t->id] ?? null,
            ];
        });

        return Inertia::render('BusinessSupport/Payable/TagihanProgress/Index', [
            'rows' => $rows,
            'filters' => ['cari' => $cari, 'status' => $status],
            'suppliers' => BsSupplier::orderBy('name')->get(['id', 'code', 'name']),
            'bisaPosting' => (bool) $request->user()?->can('ap.posting.create'),
            'ditarik_pada' => optional(BsMukTagihan::max('ditarik_pada'))
                ? \Illuminate\Support\Carbon::parse(BsMukTagihan::max('ditarik_pada'))->translatedFormat('d M Y H:i')
                : null,
            'ringkas' => [
                'belum' => BsMukTagihan::whereNull('diposting_pada')->count(),
                'sudah' => BsMukTagihan::whereNotNull('diposting_pada')->count(),
            ],
        ]);
    }


    /**
     * Rincian per UNIT RUMAH: seluruh SPK yang menyentuh rumah itu dan nilai persediaan
     * dalam prosesnya.
     *
     * BERPOROS PADA RUMAH, bukan pada tagihan yang diklik. Satu rumah disentuh beberapa SPK
     * — housing, kanopi, site instruction — dan yang kelak dipindahkan ke Barang Siap Dijual
     * adalah RUMAHNYA. Keputusan itu menuntut seluruh SPK pembentuknya terlihat sekaligus,
     * bukan hanya SPK yang kebetulan dibuka.
     *
     * Rumah yang ditampilkan dipilih dari daftar rumah milik kontrak ini; bawaannya yang
     * pertama. Rumah yang lokasinya belum dipasangkan ke unit kita tidak muncul sama sekali
     * — lihat RincianUnitSpk.
     */
    public function unit(Request $request, BsMukContract $kontrak, RincianUnitSpk $rincian): Response
    {
        abort_unless($request->user()?->can('ap.posting.view'), 403);

        $daftarUnit = $rincian->unitKontrak($kontrak);

        $unitId = $request->integer('unit') ?: (int) ($daftarUnit->first()['id'] ?? 0);
        $data = $unitId > 0 ? $rincian->untukUnit($unitId) : null;

        return Inertia::render('BusinessSupport/Payable/TagihanProgress/Unit', [
            'kontrak' => [
                'id' => $kontrak->id,
                'no_spk' => $kontrak->no_spk,
                'no_kontrak' => $kontrak->no_kontrak,
            ],
            'daftarUnit' => $daftarUnit,
            'unitId' => $unitId > 0 ? $unitId : null,
            'unit' => $data['unit'] ?? null
                ? ['id' => $data['unit']->id, 'unit_code' => $data['unit']->unit_code,
                    'cluster' => $data['unit']->cluster?->name]
                : null,
            'baris' => $data['baris'] ?? [],
            'total' => [
                'alokasi' => $data['total_alokasi'] ?? 0,
                'wip' => $data['total_wip'] ?? 0,
            ],
        ]);
    }

    /**
     * Masukkan satu tagihan ke antrean Posting Invoice SPK.
     *
     * Supplier WAJIB DIPILIH, tidak ditebak. MUK menyebut pihak dalam kontraknya sebagai
     * "pelanggan" — yaitu KITA selaku owner, bukan kontraktornya — jadi tidak ada satu pun
     * nama di data mereka yang bisa dipetakan otomatis ke supplier di sini. Menebaknya berarti
     * hutang tercatat atas nama pihak yang salah.
     */
    public function postingKeAp(Request $request, BsMukTagihan $tagihan): RedirectResponse
    {
        abort_unless($request->user()?->can('ap.posting.create'), 403);

        $v = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:bs_suppliers,id'],
            'due_date' => ['nullable', 'date'],
        ], [
            'supplier_id.required' => 'Pilih dulu kontraktor penerima pembayarannya.',
        ]);

        // Sudah pernah diposting berarti sudah ada di antrean — atau sudah jadi hutang.
        // Ditolak dengan sebutannya, bukan ditambahkan lagi: tagihan yang masuk antrean dua
        // kali menjadi dua invoice, dan dibayar dua kali.
        if ($tagihan->diposting_pada !== null) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Tagihan '.$tagihan->no_tagihan.' sudah pernah diposting ke AP pada '
                    .$tagihan->diposting_pada->translatedFormat('d M Y H:i').'.',
            ]);
        }

        if ($tagihan->nilai_tagihan <= 0) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Nilai tagihan '.$tagihan->no_tagihan.' nol atau negatif — tidak ada yang bisa dijadikan hutang.',
            ]);
        }

        $tagihan->update([
            'supplier_id' => (int) $v['supplier_id'],
            // Jatuh tempo bawaan MENGIKUTI MUK bila mereka mengirimnya. Itu tanggal yang
            // disepakati di dokumennya; menggantinya dengan "30 hari sejak hari ini" membuat
            // umur hutang di Aging berbeda dari dokumen yang dipegang kontraktor.
            'due_date' => $v['due_date'] ?? optional($tagihan->jatuh_tempo)->toDateString(),
            'diposting_oleh' => $request->user()?->id,
            'diposting_pada' => now(),
        ]);

        return back()->with('success', 'Tagihan '.$tagihan->no_tagihan.' masuk antrean Posting Invoice SPK.');
    }

    /** Tarik kembali dari antrean — selama invoice-nya BELUM terbit. */
    public function batalPosting(Request $request, BsMukTagihan $tagihan): RedirectResponse
    {
        abort_unless($request->user()?->can('ap.posting.create'), 403);

        $invoice = BsApInvoice::where('source_type', 'muk_tagihan')->where('source_id', $tagihan->id)->first();

        if ($invoice !== null) {
            // Hutangnya sudah terbit. Membatalkan di sini hanya menghapus jejaknya dari
            // antrean sementara invoice-nya tetap hidup — dan tagihan yang sama bisa masuk
            // antrean lagi lalu terbit dua kali.
            throw ValidationException::withMessages([
                'tagihan' => 'Invoice '.$invoice->invoice_number.' sudah terbit dari tagihan ini. '
                    .'Batalkan invoice-nya lebih dulu di Account Payable.',
            ]);
        }

        $tagihan->update(['diposting_oleh' => null, 'diposting_pada' => null]);

        return back()->with('success', 'Tagihan '.$tagihan->no_tagihan.' ditarik dari antrean.');
    }
}
