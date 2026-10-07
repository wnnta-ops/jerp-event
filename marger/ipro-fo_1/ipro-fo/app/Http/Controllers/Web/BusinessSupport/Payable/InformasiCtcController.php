<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Services\BarisSpkMuk;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Informasi CTC — nilai tiap SPK berikut berapa yang sudah dan belum tertagih (Lampiran 2).
 *
 * BACA-SAJA. Tidak ada satu pun angka di sini yang berasal dari layar ini sendiri: nilai
 * SPK dan yang sudah tertagih datang dari salinan kontrak MUK, Lokasi & Tahap dari pemetaan
 * Setup Project. Semuanya disusun BarisSpkMuk — definisi yang SAMA dengan sub modul Kontrak
 * SPK (MUK), supaya satu kontrak tidak pernah menunjukkan dua angka berbeda di dua layar.
 *
 * ── "BELUM TERTAGIH" DI SINI BUKAN YANG DI LAYAR KONTRAK SPK ──
 *
 * Di sini: NILAI SPK dikurangi yang sudah tertagih — sisa kontraknya, itulah arti CTC.
 * Di layar Kontrak SPK: OPNAME dikurangi yang sudah tertagih — berapa yang sudah dikerjakan
 * tetapi belum ditagihkan, yang dipakai menyiapkan tagihan berikutnya.
 *
 * Keduanya benar untuk pertanyaannya masing-masing dan angkanya memang berbeda. Alasannya
 * ditulis juga di BarisSpkMuk supaya tidak ada yang "memperbaiki" salah satunya agar cocok.
 */
class InformasiCtcController extends Controller
{
    public function index(Request $request, BarisSpkMuk $baris): Response
    {
        // Izin menumpang ap.posting.view, sama dengan Kontrak SPK (MUK): orang yang sama yang
        // menyiapkan invoice SPK-lah yang perlu melihat angka ini.
        abort_unless($request->user()?->can('ap.posting.view'), 403);

        $cari = trim((string) $request->input('cari'));

        $q = BsMukContract::query()
            ->when($cari !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('no_spk', 'like', "%{$cari}%")
                ->orWhere('no_kontrak', 'like', "%{$cari}%")
                ->orWhere('proyek_nama', 'like', "%{$cari}%")
                ->orWhere('pelanggan_nama', 'like', "%{$cari}%")))
            ->orderBy('no_spk');

        $petaProyek = $baris->petaProyek();

        // Total dihitung dari SALINAN yang diambil SEBELUM paginate(). paginate() memasang
        // LIMIT/OFFSET pada builder-nya sendiri; menyalinnya sesudah itu membuat agregat
        // ber-OFFSET, dan mulai halaman kedua barisnya terlewat sehingga seluruh total
        // terbaca nol — tanpa galat apa pun.
        $total = $this->total(clone $q);

        $rows = $q->paginate(50)->withQueryString()->through(
            fn (BsMukContract $k) => $baris->kolom($k, $petaProyek->get((string) $k->proyek_kode)) + [
                'id' => $k->id,
                'muk_id' => $k->muk_id,
                'no_kontrak' => $k->no_kontrak,
                'pelanggan' => $k->pelanggan_nama,
                'status' => $k->status,
            ]
        );

        return Inertia::render('BusinessSupport/Payable/InformasiCtc/Index', [
            'rows' => $rows,
            'filters' => ['cari' => $cari],
            // Total atas SELURUH baris yang tersaring, bukan halaman yang sedang terbuka.
            // Total satu halaman adalah angka yang tidak menjawab pertanyaan siapa pun, dan
            // tampak seperti total keseluruhan.
            'total' => $total,
            'ditarik_pada' => optional(BsMukContract::max('ditarik_pada'))
                ? \Illuminate\Support\Carbon::parse(BsMukContract::max('ditarik_pada'))->translatedFormat('d M Y H:i')
                : null,
        ]);
    }

    /**
     * Total seluruh baris tersaring.
     *
     * Dijumlahkan di DATABASE, bukan dengan memuat seluruh kontrak ke memori. Nilai SPK
     * (Tagihan) sementara sama dengan DPP karena PPN & PPh 4(2) belum dikirim MUK —
     * penjelasannya di BarisSpkMuk, dan layar mengatakannya.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<BsMukContract>  $q
     * @return array<string, float|int>
     */
    private function total($q): array
    {
        $a = $q->reorder()->selectRaw(
            'COUNT(*) n, SUM(nilai_kontrak) dpp, SUM(kum_ditagih_neto) sudah'
        )->first();

        $dpp = (float) ($a->dpp ?? 0);
        $sudah = (float) ($a->sudah ?? 0);

        return [
            'jumlah' => (int) ($a->n ?? 0),
            'spk_dpp' => $dpp,
            'spk_tagihan' => $dpp,
            'sudah_tertagih' => $sudah,
            // Dijumlahkan dari SELISIH TOTAL, bukan dari kolom belum_tertagih tiap baris:
            // baris yang ditagih melebihi nilainya dijepit ke nol di layar, dan menjumlahkan
            // angka yang sudah dijepit memberi total yang tidak sama dengan DPP − tertagih.
            'belum_tertagih' => $dpp - $sudah,
        ];
    }
}
