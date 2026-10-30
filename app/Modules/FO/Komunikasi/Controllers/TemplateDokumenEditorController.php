<?php

namespace App\Modules\FO\Komunikasi\Controllers;

use App\Filament\Fo\Resources\TemplateDokumenResource;
use App\Http\Controllers\Controller;
use App\Modules\FO\Komunikasi\Models\TemplateDokumen;
use App\Modules\FO\Komunikasi\Support\DokumenBawaan;
use App\Modules\FO\Komunikasi\Support\EditorDokumen;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Editor visual Template Dokumen SPR/SPRS — dokumen dirender per halaman dengan template
 * cetak yang sama, teks aturannya diedit langsung di halaman. Data customer/unit = contoh.
 *
 * Tanpa {templateDokumen} = membuat versi baru; dengan = mengubah versi yang BELUM berlaku
 * (versi yang sudah berlaku terkunci, sama dengan formulir Filament).
 */
class TemplateDokumenEditorController extends Controller
{
    public function tampil(Request $request, ?TemplateDokumen $templateDokumen = null): mixed
    {
        $this->izinkan($templateDokumen);

        if ($t = $templateDokumen) {
            $meta = ['dokumen' => $t->dokumen, 'lokasi_id' => $t->lokasi_id, 'tahap_id' => $t->tahap_id,
                'berlaku_mulai' => $t->berlaku_mulai->toDateString(), 'catatan' => (string) $t->catatan];
            $isi = array_replace(DokumenBawaan::untuk($t->dokumen), (array) $t->isi);
        } else {
            // ?dari=<id> = "Versi baru dari ini"; ?dokumen/lokasi_id/tahap_id = pilihan di bilah atas.
            $dari = TemplateDokumen::find($request->integer('dari'));
            $dokumen = $dari?->dokumen ?? ($request->query('dokumen') === DokumenBawaan::SPRS ? DokumenBawaan::SPRS : DokumenBawaan::SPR);
            $lokasiId = $dari ? $dari->lokasi_id : ($request->integer('lokasi_id') ?: null);
            $tahapId = $dari ? $dari->tahap_id : ($lokasiId ? ($request->integer('tahap_id') ?: null) : null);
            $meta = ['dokumen' => $dokumen, 'lokasi_id' => $lokasiId, 'tahap_id' => $tahapId,
                'berlaku_mulai' => today()->addDay()->toDateString(), 'catatan' => ''];
            $isi = $dari
                ? array_replace(DokumenBawaan::untuk($dokumen), (array) $dari->isi)
                : TemplateDokumenResource::isiAwal($dokumen, $lokasiId, $tahapId);
        }

        return $this->render($templateDokumen, $meta, $isi);
    }

    /** aksi=muat → tampilkan ulang (setelah tambah/hapus/geser); aksi=simpan → simpan versi. */
    public function kirim(Request $request, ?TemplateDokumen $templateDokumen = null): mixed
    {
        $this->izinkan($templateDokumen);
        $t = $templateDokumen;

        $dokumen = $t?->dokumen ?? ($request->input('dokumen') === DokumenBawaan::SPRS ? DokumenBawaan::SPRS : DokumenBawaan::SPR);
        $lokasiId = $t ? $t->lokasi_id : ($request->integer('lokasi_id') ?: null);
        $tahapId = $t ? $t->tahap_id : ($lokasiId ? ($request->integer('tahap_id') ?: null) : null);
        if ($tahapId && ! Tahap::query()->whereKey($tahapId)->where('lokasi_id', $lokasiId)->exists()) {
            $tahapId = null;
        }
        $meta = ['dokumen' => $dokumen, 'lokasi_id' => $lokasiId, 'tahap_id' => $tahapId,
            'berlaku_mulai' => (string) $request->input('berlaku_mulai'), 'catatan' => trim((string) $request->input('catatan'))];
        $isi = EditorDokumen::bersihkan($dokumen, (array) json_decode((string) $request->input('isi'), true));

        if ($request->input('aksi') !== 'simpan') {
            return $this->render($t, $meta, $isi, gulir: $request->integer('gulir'));
        }

        // Versi baru hanya berlaku ke depan — dokumen yang sudah ditandatangani tak ikut berubah.
        $tgl = rescue(fn () => \Illuminate\Support\Carbon::parse($meta['berlaku_mulai'])->startOfDay(), null, false);
        if (! $tgl || $tgl->lt(today())) {
            return $this->render($t, $meta, $isi, 'Tanggal berlaku wajib diisi dan tidak boleh mundur — versi baru hanya berlaku ke depan.', $request->integer('gulir'));
        }

        DB::transaction(function () use (&$t, $meta, $isi, $tgl) {
            $data = ['berlaku_mulai' => $tgl->toDateString(), 'catatan' => $meta['catatan'] ?: null, 'isi' => $isi];
            if ($t) {
                $t->update($data);
            } else {
                $t = TemplateDokumen::create($data + [
                    'dokumen' => $meta['dokumen'], 'lokasi_id' => $meta['lokasi_id'], 'tahap_id' => $meta['tahap_id'],
                    'versi' => TemplateDokumen::versiBerikutnya($meta['dokumen'], $meta['lokasi_id'], $meta['tahap_id']),
                    'created_by' => Auth::id(),
                ]);
            }
        });

        Notification::make()->success()->title('Template '.$t->dokumen.' v'.$t->versi.' disimpan')
            ->body('Berlaku mulai '.$t->berlaku_mulai->translatedFormat('d F Y').'.')->send();

        return redirect(TemplateDokumenResource::getUrl('index', panel: 'fo'));
    }

    private function izinkan(?TemplateDokumen $t): void
    {
        abort_unless($t ? TemplateDokumenResource::canEdit($t) : TemplateDokumenResource::canCreate(), 403,
            $t ? 'Versi ini sudah berlaku (terkunci) atau Anda tidak berhak mengubahnya.' : 'Anda tidak berhak membuat versi Template Dokumen.');
    }

    /** Render dokumen cetak dalam mode editor dengan data contoh dari cluster/tahap yang sama. */
    private function render(?TemplateDokumen $t, array $meta, array $isi, ?string $galat = null, int $gulir = 0): mixed
    {
        $diUnit = fn ($q) => $q->when($meta['lokasi_id'], fn ($q) => $q->where('lokasi_id', $meta['lokasi_id']))
            ->when($meta['tahap_id'], fn ($q) => $q->where('tahap_id', $meta['tahap_id']));

        $editor = $meta + [
            'record' => $t,
            'galat' => $galat,
            'gulir' => $gulir,
            'lokasi' => Lokasi::query()->orderBy('nama')->pluck('nama', 'id')->all(),
            'tahap' => Tahap::query()->orderBy('kode')->get(['id', 'lokasi_id', 'kode', 'nama'])
                ->map(fn ($x) => ['id' => $x->id, 'lokasi_id' => $x->lokasi_id, 'label' => trim($x->kode.($x->nama && $x->nama !== $x->kode ? ' — '.$x->nama : ''))])->all(),
            'urlKirim' => route('fo.template-dokumen.editor.kirim', $t),
            'urlBaru' => route('fo.template-dokumen.editor'),
            'urlFormulir' => $t ? TemplateDokumenResource::getUrl('edit', ['record' => $t], panel: 'fo') : TemplateDokumenResource::getUrl('create', panel: 'fo'),
            'urlKembali' => TemplateDokumenResource::getUrl('index', panel: 'fo'),
        ];

        if ($meta['dokumen'] === DokumenBawaan::SPRS) {
            $reg = NupRegistration::query()->whereNotNull('urutan')
                ->when($meta['lokasi_id'], fn ($q) => $q->whereHas('unit', $diUnit))->latest('id')->first()
                ?? NupRegistration::query()->whereNotNull('urutan')->latest('id')->first();
            abort_unless($reg, 404, 'Belum ada NUP yang disetujui untuk contoh data SPRS.');
            $reg->load(['customer', 'agent', 'event']);
            $kontrak = $reg->reservasi_id
                ? Kontrak::with(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks'])
                    ->where('reservasi_id', $reg->reservasi_id)->orderByDesc('id')->first()
                : null;
            $editor['contoh'] = $reg->no_nup ?: $reg->nama_pendaftar;

            // QR tidak ditampilkan di editor — membuatnya bisa menerbitkan kode customer.
            return view('pdf.fo.sprs', ['reg' => $reg, 'kontrak' => $kontrak, 'tts' => null, 'qrSvg' => null, 'custCode' => null,
                'isiDokumen' => $isi, 'editor' => $editor]);
        }

        $kontrak = Kontrak::query()->when($meta['lokasi_id'], fn ($q) => $q->whereHas('unit', $diUnit))->latest('id')->first()
            ?? Kontrak::query()->latest('id')->first();
        abort_unless($kontrak, 404, 'Belum ada kontrak untuk contoh data SPR.');
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks', 'tagihan']);
        $editor['contoh'] = $kontrak->no_kontrak;

        return view('pdf.fo.spr', ['kontrak' => $kontrak, 'nup' => null, 'isiDokumen' => $isi, 'editor' => $editor]);
    }
}
