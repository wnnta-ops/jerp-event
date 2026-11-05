<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApBerkasUploadFile;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * File Kelengkapan Berkas di rincian Invoice Hutang (seperti unggahan berkas di pengajuan).
 * Invoice dari Upload Berkas → file masuk ke berkas itu (ikut tampil di berkas, cetak & Approval
 * Inbox). Invoice tanpa berkas → disimpan sebagai lampiran invoice (berkas_upload_id kosong).
 * Hanya file yang ditambahkan dari rincian invoice yang boleh dihapus di sana — file berkas
 * asli & TTD-nya tidak tersentuh.
 */
class KelengkapanBerkasInvoice
{
    public const MAX_FILES = 20;

    public function __construct(private readonly ApBerkasService $berkas) {}

    public function berkasUntuk(BsApInvoice $invoice): ?BsApBerkasUpload
    {
        return $invoice->source_type === ApBerkasService::INVOICE_SOURCE && $invoice->source_id
            ? BsApBerkasUpload::find($invoice->source_id)
            : null;
    }

    /** Semua file: milik berkas invoice + lampiran invoice tanpa berkas. */
    public function files(BsApInvoice $invoice): \Illuminate\Support\Collection
    {
        $berkas = $this->berkasUntuk($invoice);

        return BsApBerkasUploadFile::query()->with('jenis:id,name')
            ->where(fn ($q) => $berkas
                ? $q->where('berkas_upload_id', $berkas->id)->orWhere('ap_invoice_id', $invoice->id)
                : $q->where('ap_invoice_id', $invoice->id))
            ->orderBy('id')->get();
    }

    /** Untuk layar: file + boleh-hapus (hanya tambahan dari rincian invoice ini). */
    public function ringkas(BsApInvoice $invoice): array
    {
        $users = [];

        return $this->files($invoice)->map(function (BsApBerkasUploadFile $f) use ($invoice, &$users) {
            $oleh = $f->uploaded_by ? ($users[$f->uploaded_by] ??= DB::table('users')->where('id', $f->uploaded_by)->value('name')) : null;

            return [
                'id' => $f->id,
                'name' => $f->original_name,
                'jenis' => $f->labelJenis(),
                'size' => (int) $f->size,
                'url' => route('bs.ap.kelengkapan-file.show', [$invoice->id, $f->id]),
                'tambahan' => (int) $f->ap_invoice_id === (int) $invoice->id,
                'oleh' => $oleh,
                'at' => $f->created_at?->format('d-m-Y H:i'),
            ];
        })->values()->all();
    }

    /** @param  list<array{file:\Illuminate\Http\UploadedFile, berkas_id:int, keterangan:?string}>  $files */
    public function tambah(BsApInvoice $invoice, array $files, User $user): void
    {
        if ($invoice->batal()) {
            throw new BusinessRuleException('Invoice sudah dibatalkan — tidak bisa menambah file.', errorCode: 'INVOICE_BATAL');
        }
        if ($this->files($invoice)->count() + count($files) > self::MAX_FILES) {
            throw new BusinessRuleException('Maksimal '.self::MAX_FILES.' file kelengkapan per invoice.', errorCode: 'BERKAS_TOO_MANY');
        }
        $berkas = $this->berkasUntuk($invoice);
        $folder = $berkas ? "ap-berkas/{$berkas->tenant_id}/{$berkas->id}" : "ap-invoice-berkas/{$invoice->tenant_id}/{$invoice->id}";
        $stored = [];
        try {
            DB::transaction(function () use ($invoice, $files, $user, $berkas, $folder, &$stored): void {
                foreach ($files as $f) {
                    $file = $f['file'];
                    $path = $file->storeAs($folder, 'k-'.Str::random(12).'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()), ApBerkasService::DISK);
                    $stored[] = $path;
                    BsApBerkasUploadFile::create([
                        'berkas_upload_id' => $berkas?->id,
                        'ap_invoice_id' => $invoice->id,
                        'berkas_id' => $f['berkas_id'] ?? null,
                        'keterangan' => $f['keterangan'] ?? null,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                        'path' => $path,
                        'mime' => (string) $file->getMimeType(),
                        'size' => (int) $file->getSize(),
                        'uploaded_by' => $user->id,
                    ]);
                }
            });
        } catch (Throwable $e) {
            foreach ($stored as $p) {
                Storage::disk(ApBerkasService::DISK)->delete($p);
            }
            throw $e;
        }
        if ($berkas) {
            $this->berkas->log($berkas, 'file_ditambah', 'Kelengkapan dari invoice '.$invoice->invoice_number.': '
                .implode(', ', array_map(fn ($f) => $f['file']->getClientOriginalName(), $files)));
        }
    }

    public function hapus(BsApInvoice $invoice, BsApBerkasUploadFile $file): void
    {
        if ((int) $file->ap_invoice_id !== (int) $invoice->id) {
            throw new BusinessRuleException('Hanya file yang ditambahkan dari rincian invoice ini yang bisa dihapus.', errorCode: 'BERKAS_LOCKED');
        }
        $berkas = $file->berkas_upload_id ? BsApBerkasUpload::find($file->berkas_upload_id) : null;
        $path = $file->path;
        $name = $file->original_name;
        $file->delete();
        Storage::disk(ApBerkasService::DISK)->delete($path);
        if ($berkas) {
            $this->berkas->log($berkas, 'file_dihapus', 'Kelengkapan dari invoice '.$invoice->invoice_number.': '.$name);
        }
    }

    /** File milik invoice ini (lewat berkasnya atau lampiran langsung)? */
    public function milikInvoice(BsApInvoice $invoice, BsApBerkasUploadFile $file): bool
    {
        if ((int) $file->ap_invoice_id === (int) $invoice->id) {
            return true;
        }
        $berkas = $this->berkasUntuk($invoice);

        return $berkas !== null && (int) $file->berkas_upload_id === (int) $berkas->id;
    }
}
