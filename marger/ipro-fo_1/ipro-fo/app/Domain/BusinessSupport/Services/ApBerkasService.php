<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApBerkasUploadFile;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsSupplier;
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
 *   'ap_berkas'  : 1 langkah, approver = Dept Head pengunggah — OTOMATIS dari atasan langsungnya
 *                  di Employee Center (bs_employees.atasan_id), approver_type 'assigned'
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

    /** Peran Dept Head (label langkah approval 'ap_berkas'); penanda tangannya sendiri = atasan pengunggah. */
    public const DEPT_HEAD_ROLE = 'DeptHead';

    /** source_type invoice hutang yang dibuat dari Upload Berkas. */
    public const INVOICE_SOURCE = 'berkas';

    public const MAX_FILES = 10;

    public function __construct(
        private readonly SubmitForApproval $submit,
        private readonly CostCenterPengguna $pengguna,
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
                $isian = $this->isian($data);
                $uploadDate = $data['upload_date'] ?? now()->toDateString();
                $berkas = BsApBerkasUpload::create([
                    ...$isian,
                    'upload_date' => $uploadDate,
                    'berkas_number' => $this->nextNumber($isian['supplier_id'], $isian['document_date'] ?? $uploadDate),
                    'dept_head_id' => $this->deptHeadUntuk($uploader)?->id,
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

    /**
     * Ubah isian (draft / ditolak). Nomor ikut diganti bila tanggal tagihan atau supplier
     * berubah — keduanya bagian dari nomor, jadi nomor lama akan menyesatkan.
     */
    public function update(BsApBerkasUpload $berkas, array $data, User $user): BsApBerkasUpload
    {
        $this->assertBisaDiubah($berkas);

        return DB::transaction(function () use ($berkas, $data): BsApBerkasUpload {
            $isian = $this->isian($data);
            $date = $isian['document_date'] ?? $berkas->upload_date?->toDateString() ?? now()->toDateString();
            if (! str_starts_with((string) $berkas->berkas_number, $this->prefix($isian['supplier_id'], $date))) {
                $isian['berkas_number'] = $this->nextNumber($isian['supplier_id'], $date);
            }
            $berkas->update($isian);

            return $berkas->refresh();
        });
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
     * Diperiksa: atasan (Dept Head) pengunggah ada, file ada, berkas wajib lengkap.
     */
    public function submit(BsApBerkasUpload $berkas, User $user): BsApBerkasUpload
    {
        $berkas = BsApBerkasUpload::query()->lockForUpdate()->findOrFail($berkas->id);
        $this->assertBisaDiubah($berkas);

        // Ditentukan ulang saat diajukan: atasan di Employee Center bisa saja berganti sejak draft dibuat.
        $uploader = User::find($berkas->uploaded_by);
        $deptHead = $uploader ? $this->deptHeadUntuk($uploader) : null;
        if (! $deptHead) {
            throw new BusinessRuleException(
                'Dept Head Anda belum diatur — isi atasan langsung Anda di Data Center › Employee Center, atau hubungi HRD.',
                errorCode: 'BERKAS_DEPT_HEAD',
            );
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
        $ulang = $berkas->status === BsApBerkasUpload::STATUS_DITOLAK;
        $approval = $this->submit->execute(BsApBerkasUpload::DOCUMENT_TYPE, $berkas, [
            'berkas_number' => $berkas->berkas_number,
            'description' => $berkas->description,
            'amount' => $berkas->amount !== null ? (float) $berkas->amount : null,
        ], [1 => (int) $deptHead->id]);

        $berkas->update([
            'dept_head_id' => $deptHead->id,
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

    /**
     * Pilihan berkas di langkah Detil Tagihan Registrasi Invoice Hutang: HANYA unggahan user itu
     * sendiri (user tidak melihat berkas satu sama lain) yang masih aktif. File diurutkan sesuai
     * checklist jenis berkas — sama dengan urutan "Lihat semua" di halaman berkas.
     *
     * @return list<array<string,mixed>>
     */
    public function pilihanInvoice(User $user): array
    {
        $urutan = BsApBerkas::query()->where('is_active', true)->orderByDesc('is_mandatory')->orderBy('id')->pluck('id')->flip();

        return BsApBerkasUpload::query()
            ->where('uploaded_by', $user->id)
            ->whereIn('status', BsApBerkasUpload::STATUS_PILIHAN_INVOICE)
            ->with(['supplier:id,name', 'deptHead:id,name', 'files.jenis:id,name'])
            ->latest('id')->get()
            ->map(fn (BsApBerkasUpload $b) => [
                'id' => $b->id,
                'number' => $b->berkas_number,
                'status' => $b->status,
                'status_label' => BsApBerkasUpload::STATUS_LABELS[$b->status] ?? $b->status,
                'supplier_id' => $b->supplier_id,
                'supplier' => $b->supplier?->name,
                'supplier_invoice_no' => $b->supplier_invoice_no,
                'description' => $b->description,
                'document_date' => $b->document_date?->toDateString(),
                'needed_date' => $b->needed_date?->toDateString(),
                'amount' => $b->amount !== null ? (float) $b->amount : null,
                'sisa' => $b->sisa(),
                'dept_head' => $b->deptHead?->name,
                'uploader' => $user->name,
                'bisa_diinvoice' => $b->bisaDiinvoice(),
                'bisa_diubah' => $b->bisaDiubah(),
                'files' => $b->files
                    ->sortBy(fn (BsApBerkasUploadFile $f) => $urutan[$f->berkas_id] ?? PHP_INT_MAX)
                    ->map(fn (BsApBerkasUploadFile $f) => [
                        'id' => $f->id,
                        'name' => $f->original_name,
                        'jenis' => $f->jenis?->name,
                        'mime' => $f->mime,
                        'url' => route('bs.ap.berkas.file', [$b->id, $f->id]),
                    ])->values()->all(),
            ])->all();
    }

    /** Berkas pilihan invoice harus unggahan user itu sendiri. */
    public function assertMilik(BsApBerkasUpload $berkas, User $user): void
    {
        if ((int) $berkas->uploaded_by !== (int) $user->id) {
            throw new BusinessRuleException(
                "Berkas {$berkas->berkas_number} bukan unggahan Anda — pilih berkas yang Anda unggah sendiri lewat Upload Berkas.",
                errorCode: 'BERKAS_BUKAN_MILIK',
            );
        }
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

    /**
     * Dept Head penanda tangan = atasan langsung pengunggah di Employee Center
     * (karyawan pengunggah → atasan_id → akun user yang NIK-nya = NIK atasan; cadangan: nama).
     * null bila belum diatur, atasannya tidak punya akun aktif, atau atasannya dirinya sendiri.
     */
    public function deptHeadUntuk(User $user): ?User
    {
        $empId = $this->pengguna->idKaryawan($user);
        $atasanId = $empId ? BsEmployee::query()->whereKey($empId)->value('atasan_id') : null;
        $atasan = $atasanId ? BsEmployee::query()->find($atasanId) : null;
        if (! $atasan) {
            return null;
        }
        $head = User::query()->where('is_active', true)->where('employee_nip', $atasan->nik)->first()
            ?? User::query()->where('is_active', true)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $atasan->name))])->first();

        return $head && (int) $head->id !== (int) $user->id ? $head : null;
    }

    /**
     * DOC-{DDMMYY tanggal tagihan}-{kode supplier}-{NNNN}; urut per tanggal tagihan + supplier.
     * Supplier belum diisi → kode "NA".
     */
    public function nextNumber(?int $supplierId, string $date): string
    {
        $prefix = $this->prefix($supplierId, $date);

        $last = BsApBerkasUpload::withTrashed()
            ->where('berkas_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('berkas_number')
            ->value('berkas_number');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function prefix(?int $supplierId, string $date): string
    {
        $code = $supplierId ? (string) BsSupplier::query()->whereKey($supplierId)->value('code') : '';
        $code = substr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)), 0, 16) ?: 'NA';

        return 'DOC-'.\Illuminate\Support\Carbon::parse($date)->format('dmy').'-'.$code.'-';
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

    /** Kolom isian yang boleh diisi dari form. Dept Head tidak dipilih — lihat deptHeadUntuk(). */
    private function isian(array $data): array
    {
        return [
            'document_date' => $data['document_date'] ?? null,
            'needed_date' => $data['needed_date'] ?? null,
            'supplier_id' => isset($data['supplier_id']) && $data['supplier_id'] !== '' ? (int) $data['supplier_id'] : null,
            'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
            'amount' => isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : null,
            'description' => $data['description'],
        ];
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
