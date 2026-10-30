<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Upload Berkas → TTD Dept Head → Invoice Hutang → approval invoice.
 *
 * Dua approval terpisah di mesin ApprovalChain:
 *   'ap_berkas'  : 1 langkah, approver = Dept Head yang DIPILIH pengunggah (approver_type 'assigned')
 *   'ap_invoice' : Accounting → Finance → GM → Direktur → MW / BA (sama dengan Memo Internal)
 * Invoice dari berkas tidak bisa dibayar sebelum 'ap_invoice' disetujui penuh
 * (lihat BsApInvoice::assertBolehDibayar).
 */
final class ApBerkasService
{
    public const DISK = 'local';

    /** Peran yang boleh dipilih sebagai penanda tangan pertama. */
    public const DEPT_HEAD_ROLE = 'DeptHead';

    /** source_type invoice hutang yang dibuat dari Upload Berkas. */
    public const INVOICE_SOURCE = 'berkas';

    public function __construct(private readonly SubmitForApproval $submit) {}

    /**
     * @param array{upload_date:string, supplier_id?:?int, supplier_invoice_no?:?string, amount?:?float, description:string, dept_head_id:int} $data
     * @param list<array{file:UploadedFile, berkas_id:?int}> $files
     */
    public function upload(array $data, array $files, User $uploader): BsApBerkasUpload
    {
        if ($files === []) {
            throw new BusinessRuleException('Unggah minimal satu file berkas.', errorCode: 'BERKAS_EMPTY');
        }
        $deptHead = User::with('roles')->find($data['dept_head_id']);
        if (! $deptHead || ! $deptHead->hasRole(self::DEPT_HEAD_ROLE)) {
            throw new BusinessRuleException('Penanda tangan harus user ber-peran Dept Head.', errorCode: 'BERKAS_DEPT_HEAD');
        }
        if ((int) $deptHead->id === (int) $uploader->id) {
            throw new BusinessRuleException('Dept Head tidak boleh menandatangani berkas yang ia unggah sendiri.', errorCode: 'BERKAS_SELF_SIGN');
        }

        $stored = [];
        try {
            return DB::transaction(function () use ($data, $files, $uploader, $deptHead, &$stored): BsApBerkasUpload {
                $berkas = BsApBerkasUpload::create([
                    ...$data,
                    'berkas_number' => $this->nextNumber($uploader, $data['upload_date']),
                    'status' => BsApBerkasUpload::STATUS_MENUNGGU_TTD,
                    'uploaded_by' => $uploader->id,
                ]);

                foreach ($files as $i => $f) {
                    $file = $f['file'];
                    $path = $file->storeAs(
                        "ap-berkas/{$berkas->tenant_id}/{$berkas->id}",
                        ($i + 1).'-'.Str::random(8).'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()),
                        self::DISK,
                    );
                    $stored[] = $path;
                    $berkas->files()->create([
                        'berkas_id' => $f['berkas_id'] ?? null,
                        'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
                        'path' => $path,
                        'mime' => (string) $file->getMimeType(),
                        'size' => (int) $file->getSize(),
                    ]);
                }

                $approval = $this->submit->execute(BsApBerkasUpload::DOCUMENT_TYPE, $berkas, [
                    'berkas_number' => $berkas->berkas_number,
                    'description' => $berkas->description,
                    'amount' => $berkas->amount !== null ? (float) $berkas->amount : null,
                ], [1 => (int) $deptHead->id]);

                $berkas->update(['approval_id' => $approval->id]);

                return $berkas->refresh();
            });
        } catch (Throwable $e) {
            // Transaksi batal → file yang sudah tertulis jangan jadi yatim di disk.
            foreach ($stored as $p) {
                \Illuminate\Support\Facades\Storage::disk(self::DISK)->delete($p);
            }
            throw $e;
        }
    }

    /** Berkas yang boleh dijadikan invoice: sudah ditandatangani dan belum punya invoice. */
    public function assertSiapInvoice(BsApBerkasUpload $berkas): void
    {
        if ($berkas->status !== BsApBerkasUpload::STATUS_SIAP_INVOICE || $berkas->ap_invoice_id) {
            throw new BusinessRuleException(
                "Berkas {$berkas->berkas_number} belum siap dibuat invoice (status: {$berkas->status}).",
                errorCode: 'BERKAS_NOT_READY',
            );
        }
    }

    /**
     * Tautkan invoice yang baru diregistrasi ke berkasnya lalu ajukan approval invoice.
     * Dipanggil DI DALAM transaksi yang sama dengan registrasi invoice.
     */
    public function ajukanInvoice(BsApBerkasUpload $berkas, BsApInvoice $invoice): Approval
    {
        $berkas = BsApBerkasUpload::query()->lockForUpdate()->findOrFail($berkas->id);
        $this->assertSiapInvoice($berkas);

        $invoice->forceFill(['source_type' => self::INVOICE_SOURCE, 'source_id' => $berkas->id])->save();
        $berkas->update(['status' => BsApBerkasUpload::STATUS_DIINVOICE, 'ap_invoice_id' => $invoice->id]);

        return $this->submit->execute(BsApInvoice::APPROVAL_DOCUMENT_TYPE, $invoice, [
            'invoice_number' => $invoice->invoice_number,
            'berkas_number' => $berkas->berkas_number,
            'total' => (float) $invoice->total,
        ]);
    }

    /** BRK-{USER}-{MMYY}-{NNNN}; urut per pengunggah per bulan. */
    public function nextNumber(User $user, string $date): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($user->username ?: Str::before(trim($user->name), ' '))) ?: 'U'.$user->id);
        $code = substr($code, 0, 12);
        $prefix = 'BRK-'.$code.'-'.\Illuminate\Support\Carbon::parse($date)->format('my').'-';

        $last = BsApBerkasUpload::withTrashed()
            ->where('berkas_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('berkas_number')
            ->value('berkas_number');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
