<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApBerkasUploadFile;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\Foundation\Audit\Models\AuditLog;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Upload Berkas: draft → TTD Dept Head → Invoice Hutang (boleh beberapa) → approval invoice.
 *
 * Dua approval di mesin ApprovalChain:
 *   'ap_berkas'  : 1 langkah, approver = Dept Head yang DIPILIH pengunggah (approver_type 'assigned')
 *   'ap_invoice' : Accounting → Finance → GM → Direktur → MW / BA (sama dengan Memo Internal)
 * Invoice dari berkas tidak bisa dibayar sebelum 'ap_invoice' disetujui penuh
 * (BsApInvoice::assertBolehDibayar).
 *
 * Peristiwa (diajukan, dibatalkan, ditutup, file ditambah/dihapus, invoice dibuat) dicatat ke
 * audit_logs; perubahan isian dicatat otomatis oleh HasAuditTrail.
 */
final class ApBerkasService
{
    public const DISK = 'local';

    /** Peran yang boleh dipilih sebagai penanda tangan pertama. */
    public const DEPT_HEAD_ROLE = 'DeptHead';

    /** source_type invoice hutang yang dibuat dari Upload Berkas. */
    public const INVOICE_SOURCE = 'berkas';

    public const MAX_FILES = 10;

    public function __construct(
        private readonly SubmitForApproval $submit,
        private readonly BudgetGuardService $budget,
    ) {}

    /**
     * Buat berkas (draft). $ajukan = langsung minta TTD Dept Head.
     *
     * @param  array<string, mixed>  $data  isian form (lihat ApBerkasController::rules)
     * @param  list<array{file:UploadedFile, berkas_id:?int}>  $files
     */
    public function create(array $data, array $files, User $uploader, bool $ajukan = false): BsApBerkasUpload
    {
        $stored = [];
        try {
            return DB::transaction(function () use ($data, $files, $uploader, $ajukan, &$stored): BsApBerkasUpload {
                $berkas = BsApBerkasUpload::create([
                    ...$this->isian($data),
                    'upload_date' => $data['upload_date'] ?? now()->toDateString(),
                    'berkas_number' => $this->nextNumber($uploader, $data['upload_date'] ?? now()->toDateString()),
                    'status' => BsApBerkasUpload::STATUS_DRAFT,
                    'uploaded_by' => $uploader->id,
                ]);
                $stored = $this->simpanFiles($berkas, $files);

                return $ajukan ? $this->submit($berkas, $uploader) : $berkas->refresh();
            });
        } catch (Throwable $e) {
            $this->hapusFileDisk($stored);
            throw $e;
        }
    }

    /** Kompatibel dengan v1: buat lalu langsung ajukan. */
    public function upload(array $data, array $files, User $uploader): BsApBerkasUpload
    {
        if ($files === []) {
            throw new BusinessRuleException('Unggah minimal satu file berkas.', errorCode: 'BERKAS_EMPTY');
        }

        return $this->create($data, $files, $uploader, true);
    }

    /** Ubah isian (draft / ditolak). */
    public function update(BsApBerkasUpload $berkas, array $data, User $user): BsApBerkasUpload
    {
        $this->assertBisaDiubah($berkas);
        $berkas->update($this->isian($data));

        return $berkas->refresh();
    }

    /** @param list<array{file:UploadedFile, berkas_id:?int}> $files */
    public function addFiles(BsApBerkasUpload $berkas, array $files, User $user): void
    {
        $this->assertBisaDiubah($berkas);
        if ($berkas->files()->count() + count($files) > self::MAX_FILES) {
            throw new BusinessRuleException('Maksimal '.self::MAX_FILES.' file per berkas.', errorCode: 'BERKAS_TOO_MANY');
        }
        $stored = [];
        try {
            DB::transaction(function () use ($berkas, $files, &$stored): void {
                $stored = $this->simpanFiles($berkas, $files);
            });
        } catch (Throwable $e) {
            $this->hapusFileDisk($stored);
            throw $e;
        }
        $this->log($berkas, 'file_ditambah', implode(', ', array_map(fn ($f) => $f['file']->getClientOriginalName(), $files)));
    }

    public function removeFile(BsApBerkasUpload $berkas, BsApBerkasUploadFile $file, User $user): void
    {
        $this->assertBisaDiubah($berkas);
        abort_unless((int) $file->berkas_upload_id === (int) $berkas->id, 404);
        $path = $file->path;
        $name = $file->original_name;
        $file->delete();
        Storage::disk(self::DISK)->delete($path);
        $this->log($berkas, 'file_dihapus', $name);
    }

    /**
     * Ajukan (atau ajukan ulang setelah ditolak) ke TTD Dept Head.
     * Diperiksa: Dept Head sah, file ada, berkas wajib lengkap, anggaran cukup.
     */
    public function submit(BsApBerkasUpload $berkas, User $user): BsApBerkasUpload
    {
        $berkas = BsApBerkasUpload::query()->lockForUpdate()->findOrFail($berkas->id);
        $this->assertBisaDiubah($berkas);

        $deptHead = User::with('roles')->find($berkas->dept_head_id);
        if (! $deptHead || ! $deptHead->hasRole(self::DEPT_HEAD_ROLE)) {
            throw new BusinessRuleException('Penanda tangan harus user ber-peran Dept Head.', errorCode: 'BERKAS_DEPT_HEAD');
        }
        if ((int) $deptHead->id === (int) $berkas->uploaded_by) {
            throw new BusinessRuleException('Dept Head tidak boleh menandatangani berkas yang ia unggah sendiri.', errorCode: 'BERKAS_SELF_SIGN');
        }
        if (! $berkas->files()->exists()) {
            throw new BusinessRuleException('Unggah minimal satu file berkas.', errorCode: 'BERKAS_EMPTY');
        }
        if ($kurang = $this->berkasWajibKurang($berkas)) {
            throw new BusinessRuleException('Berkas wajib belum lengkap: '.implode(', ', $kurang).'.', errorCode: 'BERKAS_WAJIB');
        }
        $this->cekAnggaran($berkas);

        $ulang = $berkas->status === BsApBerkasUpload::STATUS_DITOLAK;
        $approval = $this->submit->execute(BsApBerkasUpload::DOCUMENT_TYPE, $berkas, [
            'berkas_number' => $berkas->berkas_number,
            'description' => $berkas->description,
            'amount' => $berkas->amount !== null ? (float) $berkas->amount : null,
        ], [1 => (int) $deptHead->id]);

        $berkas->update([
            'approval_id' => $approval->id,
            'status' => BsApBerkasUpload::STATUS_MENUNGGU_TTD,
            'submitted_at' => now(),
        ]);
        $this->log($berkas, $ulang ? 'diajukan_ulang' : 'diajukan', 'TTD: '.$deptHead->name);

        return $berkas->refresh();
    }

    /** Batal dengan alasan — selama belum ada invoice dari berkas ini. */
    public function cancel(BsApBerkasUpload $berkas, User $user, string $reason): BsApBerkasUpload
    {
        return DB::transaction(function () use ($berkas, $user, $reason): BsApBerkasUpload {
            $berkas = BsApBerkasUpload::query()->lockForUpdate()->findOrFail($berkas->id);
            $boleh = [BsApBerkasUpload::STATUS_DRAFT, BsApBerkasUpload::STATUS_MENUNGGU_TTD, BsApBerkasUpload::STATUS_SIAP_INVOICE, BsApBerkasUpload::STATUS_DITOLAK];
            if (! in_array($berkas->status, $boleh, true) || $berkas->invoices()->exists()) {
                throw new BusinessRuleException('Berkas yang sudah dibuat invoice tidak bisa dibatalkan — gunakan Tutup.', errorCode: 'BERKAS_NO_CANCEL');
            }
            // TTD yang masih menunggu ikut dibatalkan supaya hilang dari inbox Dept Head.
            if ($berkas->approval_id) {
                Approval::query()->whereKey($berkas->approval_id)->whereIn('status', ['pending', 'in_review'])
                    ->update(['status' => 'cancelled', 'completed_at' => now()]);
            }
            $berkas->update([
                'status' => BsApBerkasUpload::STATUS_DIBATALKAN,
                'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason,
            ]);
            $this->log($berkas, 'dibatalkan', $reason);

            return $berkas->refresh();
        });
    }

    /** Tutup parsial: berkas yang baru sebagian diinvoice; sisanya tidak akan diinvoice. */
    public function close(BsApBerkasUpload $berkas, User $user, string $reason): BsApBerkasUpload
    {
        return DB::transaction(function () use ($berkas, $user, $reason): BsApBerkasUpload {
            $berkas = BsApBerkasUpload::query()->lockForUpdate()->findOrFail($berkas->id);
            if ($berkas->status !== BsApBerkasUpload::STATUS_SEBAGIAN) {
                throw new BusinessRuleException('Hanya berkas yang sebagian diinvoice yang bisa ditutup.', errorCode: 'BERKAS_NO_CLOSE');
            }
            $sisa = $berkas->sisa();
            $berkas->update([
                'status' => BsApBerkasUpload::STATUS_DITUTUP,
                'closed_at' => now(), 'closed_by' => $user->id, 'close_reason' => $reason,
            ]);
            $this->log($berkas, 'ditutup', $reason.($sisa !== null ? ' · sisa Rp '.number_format($sisa, 0, ',', '.') : ''));

            return $berkas->refresh();
        });
    }

    /** Hapus (soft delete, tetap bisa dilihat) — draft, ditolak, atau dibatalkan. */
    public function delete(BsApBerkasUpload $berkas, User $user): void
    {
        $boleh = [BsApBerkasUpload::STATUS_DRAFT, BsApBerkasUpload::STATUS_DITOLAK, BsApBerkasUpload::STATUS_DIBATALKAN];
        if (! in_array($berkas->status, $boleh, true)) {
            throw new BusinessRuleException('Hanya berkas draft, ditolak, atau dibatalkan yang bisa dihapus.', errorCode: 'BERKAS_NO_DELETE');
        }
        $this->log($berkas, 'dihapus', null);
        $berkas->delete();
    }

    /** Berkas yang boleh dijadikan invoice: sudah ditandatangani dan masih ada sisa. */
    public function assertSiapInvoice(BsApBerkasUpload $berkas): void
    {
        if (! $berkas->bisaDiinvoice()) {
            throw new BusinessRuleException(
                "Berkas {$berkas->berkas_number} belum siap dibuat invoice (status: ".(BsApBerkasUpload::STATUS_LABELS[$berkas->status] ?? $berkas->status).').',
                errorCode: 'BERKAS_NOT_READY',
            );
        }
    }

    /**
     * Tautkan invoice yang baru diregistrasi ke berkasnya lalu ajukan approval invoice.
     * Dipanggil DI DALAM transaksi yang sama dengan registrasi invoice. Satu berkas boleh
     * diinvoice beberapa kali sampai nominalnya habis.
     */
    public function ajukanInvoice(BsApBerkasUpload $berkas, BsApInvoice $invoice): Approval
    {
        $berkas = BsApBerkasUpload::query()->lockForUpdate()->findOrFail($berkas->id);
        $this->assertSiapInvoice($berkas);

        $sisaSebelum = $berkas->sisa();
        if ($sisaSebelum !== null && (float) $invoice->total > $sisaSebelum + 0.01) {
            throw new BusinessRuleException(
                'Nilai invoice (Rp '.number_format((float) $invoice->total, 0, ',', '.').') melebihi sisa berkas (Rp '.number_format($sisaSebelum, 0, ',', '.').').',
                errorCode: 'BERKAS_OVER',
            );
        }

        $invoice->forceFill(['source_type' => self::INVOICE_SOURCE, 'source_id' => $berkas->id])->save();
        $sisa = $berkas->sisa();
        $berkas->update([
            'status' => $sisa === null || $sisa <= 0.01 ? BsApBerkasUpload::STATUS_DIINVOICE : BsApBerkasUpload::STATUS_SEBAGIAN,
            'ap_invoice_id' => $invoice->id,
        ]);
        $this->log($berkas, 'diinvoice', $invoice->invoice_number);

        return $this->submit->execute(BsApInvoice::APPROVAL_DOCUMENT_TYPE, $invoice, [
            'invoice_number' => $invoice->invoice_number,
            'berkas_number' => $berkas->berkas_number,
            'total' => (float) $invoice->total,
        ]);
    }

    /** Jenis berkas wajib (master bs_ap_berkas.is_mandatory) yang belum ada filenya. */
    public function berkasWajibKurang(BsApBerkasUpload $berkas): array
    {
        $ada = $berkas->files()->whereNotNull('berkas_id')->pluck('berkas_id')->all();

        return BsApBerkas::query()->where('is_active', true)->where('is_mandatory', true)
            ->whereNotIn('id', $ada)->orderBy('id')->pluck('name')->all();
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

    /**
     * Hitung PPN & total dari subtotal, diskon, tarif, dan "harga sudah termasuk PPN".
     *   normal   : PPN = (subtotal − diskon) × %, total = (subtotal − diskon) + PPN
     *   inklusif : subtotal sudah termasuk PPN → total = subtotal − diskon, PPN = total × %/(100+%)
     *
     * @return array{ppn_amount: float, amount: ?float}
     */
    public static function hitungTotal(?float $subtotal, float $discount, float $ppnPct, bool $inclusive): array
    {
        if ($subtotal === null) {
            return ['ppn_amount' => 0.0, 'amount' => null];
        }
        $net = max(0.0, $subtotal - $discount);
        if ($inclusive) {
            return ['ppn_amount' => round($net * $ppnPct / (100 + $ppnPct)), 'amount' => round($net, 2)];
        }
        $ppn = round($net * $ppnPct / 100);

        return ['ppn_amount' => $ppn, 'amount' => round($net + $ppn, 2)];
    }

    // ── internal ─────────────────────────────────────────────────────────────────

    private function assertBisaDiubah(BsApBerkasUpload $berkas): void
    {
        if (! $berkas->bisaDiubah()) {
            throw new BusinessRuleException(
                'Berkas berstatus '.(BsApBerkasUpload::STATUS_LABELS[$berkas->status] ?? $berkas->status).' tidak bisa diubah.',
                errorCode: 'BERKAS_LOCKED',
            );
        }
    }

    /** Kolom isian yang boleh diisi dari form, dengan PPN & total dihitung server. */
    private function isian(array $data): array
    {
        $subtotal = isset($data['subtotal']) && $data['subtotal'] !== '' ? (float) $data['subtotal'] : null;
        $discount = (float) ($data['discount'] ?? 0);
        $ppnPct = (float) ($data['ppn_pct'] ?? 0);
        $inclusive = (bool) ($data['tax_inclusive'] ?? false);
        if ($discount > 0 && ($subtotal === null || $discount > $subtotal + 0.01)) {
            throw new BusinessRuleException('Diskon tidak boleh melebihi subtotal.', errorCode: 'BERKAS_DISKON');
        }
        $total = self::hitungTotal($subtotal, $discount, $ppnPct, $inclusive);

        return [
            'jenis' => $data['jenis'] ?? null,
            'document_date' => $data['document_date'] ?? null,
            'needed_date' => $data['needed_date'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'ppn_pct' => $ppnPct,
            'tax_inclusive' => $inclusive,
            'ppn_amount' => $total['ppn_amount'],
            // Tanpa rincian pajak boleh isi nominal langsung (kompatibel v1).
            'amount' => $total['amount'] ?? (isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : null),
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'expense_account_id' => $data['expense_account_id'] ?? null,
            'description' => $data['description'],
            'dept_head_id' => $data['dept_head_id'],
        ];
    }

    /** Cek anggaran saat diajukan bila akun beban + cost center + nominal terisi. */
    private function cekAnggaran(BsApBerkasUpload $berkas): void
    {
        if (! $berkas->expense_account_id || ! $berkas->cost_center_id || $berkas->amount === null) {
            return;
        }
        $dpp = $berkas->subtotal !== null
            ? max(0.0, (float) $berkas->subtotal - (float) $berkas->discount - ($berkas->tax_inclusive ? (float) $berkas->ppn_amount : 0))
            : (float) $berkas->amount;
        $this->budget->assertWithinBudget(
            [['account_id' => $berkas->expense_account_id, 'cost_center_id' => $berkas->cost_center_id, 'debit' => $dpp, 'credit' => 0]],
            null,
            ($berkas->document_date ?? $berkas->upload_date ?? now())->toDateString(),
        );
    }

    /** @return list<string> path yang tertulis (untuk dibersihkan bila transaksi gagal) */
    private function simpanFiles(BsApBerkasUpload $berkas, array $files): array
    {
        $stored = [];
        $urut = (int) $berkas->files()->count();
        foreach ($files as $f) {
            $file = $f['file'];
            $urut++;
            $path = $file->storeAs(
                "ap-berkas/{$berkas->tenant_id}/{$berkas->id}",
                $urut.'-'.Str::random(8).'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()),
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

        return $stored;
    }

    private function hapusFileDisk(array $paths): void
    {
        foreach ($paths as $p) {
            Storage::disk(self::DISK)->delete($p);
        }
    }

    /** Peristiwa ke audit_logs (timeline di halaman berkas). */
    public function log(BsApBerkasUpload $berkas, string $event, ?string $notes): void
    {
        AuditLog::create([
            'tenant_id' => $berkas->tenant_id,
            'user_id' => auth()->id(),
            'event' => 'berkas.'.$event,
            'auditable_type' => $berkas->getMorphClass(),
            'auditable_id' => $berkas->id,
            'old_values' => null,
            'new_values' => $notes !== null ? ['catatan' => $notes] : null,
            'context' => null,
            'created_at' => now(),
        ]);
    }
}
