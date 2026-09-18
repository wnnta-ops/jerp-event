<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsMukContract;
use App\Domain\BusinessSupport\Services\MukApi;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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

        $rows = $q->paginate(25)->withQueryString()->through(fn (BsMukContract $k) => [
            'id' => $k->id,
            'muk_id' => $k->muk_id,
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
        ]);

        return Inertia::render('BusinessSupport/Payable/MukKontrak/Index', [
            'kontrak' => $rows,
            'filters' => ['cari' => $cari, 'status' => $status],
            'ringkas' => $this->ringkas(),
            'ditarik_pada' => optional(BsMukContract::max('ditarik_pada'))
                ? \Illuminate\Support\Carbon::parse(BsMukContract::max('ditarik_pada'))->translatedFormat('d M Y H:i')
                : null,
        ]);
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
