<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsApBerkasLink;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\BusinessSupport\Services\LinkBerkasSupplier;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Berkas tagihan langsung dari langkah Detil Tagihan Registrasi Invoice Hutang:
 * "Upload File" (pembuat invoice mengunggah) atau "Generate Link" (supplier mengunggah lewat link).
 * Dipanggil lewat axios dari wizard — jawabannya JSON.
 */
class ApInvoiceBerkasController extends Controller
{
    public function __construct(private readonly LinkBerkasSupplier $svc) {}

    /** Upload File: jadi Upload Berkas milik user + langsung diajukan ke TTD Dept Head. */
    public function upload(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('ap.invoice.create') && $request->user()->can('ap.berkas.upload'), 403);
        $v = $request->validate([
            'data' => ['required', 'string'], // form.data() wizard (JSON — multipart tidak membawa objek bersarang dengan rapi)
            'files' => ['required', 'array', 'min:1', 'max:'.ApBerkasService::MAX_FILES],
            'files.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'files.*.berkas_id' => ['required', 'integer', \Illuminate\Validation\Rule::in(\App\Domain\BusinessSupport\Models\BsApBerkas::jenisTetap()->pluck('id')->all())],
            'files.*.keterangan' => ['nullable', 'string', 'max:100'],
        ], [
            'files.required' => 'Unggah minimal satu file.',
            'files.*.file.mimes' => 'File harus PDF, JPG, atau PNG.',
            'files.*.file.max' => 'Ukuran tiap file maksimal 10 MB.',
            'files.*.berkas_id.required' => 'Pilih jenis dokumen untuk tiap file.',
        ]);

        try {
            $berkas = $this->svc->upload($request->user(), (array) json_decode($v['data'], true),
                \App\Domain\BusinessSupport\Models\BsApBerkas::rapikanUnggahan($v['files']));
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $berkas->id, 'number' => $berkas->berkas_number, 'status' => $berkas->status]);
    }

    /** Generate Link: pesan nomor invoice + simpan draft wizard di link. */
    public function buatLink(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('ap.invoice.create'), 403);
        $v = $request->validate(['data' => ['required', 'array'], 'step' => ['required', 'integer', 'min:1', 'max:5']]);

        try {
            $link = $this->svc->buat($request->user(), $v['data'], (int) $v['step']);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->ringkas($link));
    }

    /** Status link (dipanggil "Muat ulang" / berkala dari wizard). */
    public function status(Request $request, BsApBerkasLink $link): JsonResponse
    {
        $this->milik($request, $link);

        return response()->json($this->ringkas($link->load('berkas:id,berkas_number,status')));
    }

    /** Simpan draft wizard ke link (Simpan Draft selagi menunggu supplier). */
    public function simpanDraft(Request $request, BsApBerkasLink $link): JsonResponse
    {
        $this->milik($request, $link);
        $v = $request->validate(['data' => ['required', 'array'], 'step' => ['required', 'integer', 'min:1', 'max:5']]);
        $this->svc->simpanDraft($link, $v['data'], (int) $v['step']);

        return response()->json(['ok' => true]);
    }

    /** Tambah file ke berkas link yang sudah di-TTD penuh (link nonaktif bagi supplier). */
    public function tambahFile(Request $request, BsApBerkasLink $link, ApBerkasService $berkas): JsonResponse
    {
        $this->milik($request, $link);
        $v = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.ApBerkasService::MAX_FILES],
            'files.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'files.*.berkas_id' => ['required', 'integer', \Illuminate\Validation\Rule::in(\App\Domain\BusinessSupport\Models\BsApBerkas::jenisTetap()->pluck('id')->all())],
            'files.*.keterangan' => ['nullable', 'string', 'max:100'],
        ], [
            'files.required' => 'Pilih minimal satu file.',
            'files.*.file.mimes' => 'File harus PDF, JPG, atau PNG.',
            'files.*.file.max' => 'Ukuran tiap file maksimal 10 MB.',
            'files.*.berkas_id.required' => 'Pilih jenis dokumen untuk tiap file.',
        ]);
        $link->load('berkas');
        if (! $link->berkas || ! $link->ttdSelesai()) {
            return response()->json(['message' => 'Selama berkas belum selesai di-TTD, file diunggah supplier lewat link.'], 422);
        }
        try {
            $berkas->tambahFileSesudahTtd($link->berkas, \App\Domain\BusinessSupport\Models\BsApBerkas::rapikanUnggahan($v['files']), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Batalkan link (nomor invoice yang dipesan ikut hangus). */
    public function batal(Request $request, BsApBerkasLink $link): JsonResponse
    {
        $this->milik($request, $link);
        if ($link->aktif()) {
            $link->update(['status' => BsApBerkasLink::BATAL]);
        }

        return response()->json(['ok' => true]);
    }

    private function milik(Request $request, BsApBerkasLink $link): void
    {
        abort_unless((int) $link->user_id === (int) $request->user()?->id, 403);
    }

    private function ringkas(BsApBerkasLink $link): array
    {
        $link->loadMissing('berkas:id,berkas_number,status');

        return [
            'id' => $link->id, 'url' => $link->url(), 'invoice_number' => $link->invoice_number, 'status' => $link->status,
            'filled_at' => $link->filled_at?->toIso8601String(),
            'berkas_upload_id' => $link->berkas_upload_id, 'berkas_number' => $link->berkas?->berkas_number,
            // No. invoice yang diketik supplier (ada setelah link terisi) → No. Invoice Supplier di wizard.
            'supplier_invoice_no' => $link->status === 'menunggu' ? null : ($link->data['supplier_invoice_no'] ?? null),
            // Riwayat unggahan supplier (link bisa dipakai mengunggah ulang).
            'riwayat' => array_reverse($link->riwayatUnggahan()),
            // false = berkas sudah di-TTD sampai tahap terakhir → link nonaktif bagi supplier.
            'menerima' => $link->menerimaUnggahan(),
        ];
    }
}
