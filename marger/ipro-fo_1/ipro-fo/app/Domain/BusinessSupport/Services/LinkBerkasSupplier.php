<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\ApprovalInbox\Services\InboxNotifier;
use App\Domain\BusinessSupport\Actions\RegisterApInvoice;
use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApBerkasLink;
use App\Domain\BusinessSupport\Models\BsApBerkasUpload;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Berkas tagihan di langkah Detil Tagihan Registrasi Invoice Hutang punya dua jalan:
 *
 *   Upload File   : pembuat invoice mengunggah sendiri → jadi Upload Berkas miliknya + langsung
 *                   diajukan ke TTD Dept Head (upload()).
 *   Generate Link : link tanpa login untuk supplier (buat()). Saat itu nomor invoice DIPESAN (belum
 *                   aktif ke mana-mana) dan isian wizard disimpan di link. Supplier mengunggah
 *                   dokumen (terimaDariSupplier()) → jadi Upload Berkas milik pembuat invoice +
 *                   diajukan ke TTD Dept Head, pembuat dapat notifikasi & titik di daftar invoice.
 *                   Wizard tidak bisa lanjut dari Detil Tagihan sampai dokumennya ada.
 *
 * Satu user boleh menunggu beberapa link sekaligus — tiap link membawa draft-nya sendiri.
 */
final class LinkBerkasSupplier
{
    public function __construct(
        private readonly ApBerkasService $berkas,
        private readonly InboxNotifier $notifier,
    ) {}

    /**
     * Buat link untuk isian wizard ini (minimal supplier, tanggal invoice, kategori sudah terisi).
     *
     * @param  array<string,mixed>  $data  form.data() wizard
     */
    public function buat(User $user, array $data, int $step): BsApBerkasLink
    {
        if (empty($data['supplier_id']) || empty($data['invoice_date'])) {
            throw new BusinessRuleException('Pilih supplier dan isi tanggal invoice dulu sebelum membuat link.', errorCode: 'LINK_DATA');
        }
        $kategori = ! empty($data['category_id']) ? BsApCategory::query()->whereKey((int) $data['category_id'])->value('code') : null;

        return DB::transaction(fn () => BsApBerkasLink::create([
            'token' => Str::random(48),
            'user_id' => $user->id,
            'supplier_id' => (int) $data['supplier_id'],
            'invoice_number' => app(RegisterApInvoice::class)->pesanNomor((string) $data['invoice_date'], $kategori, 'invoice'),
            'data' => $this->bersihkan($data),
            'step' => max(1, min(5, $step)),
            'status' => BsApBerkasLink::MENUNGGU,
        ]));
    }

    /** Perbarui draft wizard yang dibawa link (selama invoicenya belum disimpan). */
    public function simpanDraft(BsApBerkasLink $link, array $data, int $step): void
    {
        if ($link->aktif()) {
            // Nomor invoice dari supplier tidak tertimpa isian wizard.
            if ($link->status === BsApBerkasLink::TERISI && ! empty($link->data['supplier_invoice_no'])) {
                $data['supplier_invoice_no'] = $link->data['supplier_invoice_no'];
            }
            $link->update(['data' => $this->bersihkan($data), 'step' => max(1, min(5, $step))]);
        }
    }

    /** Link milik user yang masih menunggu / sudah terisi (belum jadi invoice). */
    public function aktifUntuk(User $user): Collection
    {
        return BsApBerkasLink::query()->where('user_id', $user->id)->whereIn('status', [BsApBerkasLink::MENUNGGU, BsApBerkasLink::TERISI])
            ->with(['supplier:id,name', 'berkas:id,berkas_number,status,submitted_at'])->latest('id')->get();
    }

    /**
     * Upload File di Detil Tagihan: file pembuat invoice sendiri → Upload Berkas (draft). Pengajuan TTD
     * Dept Head lewat tombol "Ajukan ke Dept Head" di langkah Struktur (PengajuanDeptHead).
     *
     * @param  array<string,mixed>  $data  isian wizard
     * @param  list<array{file:UploadedFile, berkas_id:?int}>  $files
     */
    public function upload(User $user, array $data, array $files): BsApBerkasUpload
    {
        if ($files === []) {
            throw new BusinessRuleException('Unggah minimal satu file berkas.', errorCode: 'BERKAS_EMPTY');
        }

        return $this->berkas->create($this->isianBerkas($data), $files, $user, false);
    }

    /**
     * Supplier mengunggah dokumen lewat link. Berkas jadi milik pembuat invoice (draft); pembuat
     * mengajukannya ke TTD Dept Head di langkah Struktur. Satu link = satu kiriman.
     *
     * @param  list<array{file:UploadedFile, berkas_id:?int}>  $files
     */
    public function terimaDariSupplier(BsApBerkasLink $link, array $files, ?string $catatan = null, ?string $noInvoice = null): BsApBerkasUpload
    {
        // Link tetap hidup sampai invoicenya disimpan / dibatalkan: supplier boleh mengunggah ULANG.
        // Unggahan baru = berkas baru yang menggantikan berkas sebelumnya (dibatalkan, keluar dari
        // inbox TTD Dept Head); semua unggahan tercatat di riwayat link.
        if (! $link->aktif()) {
            throw new BusinessRuleException('Link ini sudah tidak menerima dokumen.', errorCode: 'LINK_TUTUP');
        }
        // Berkas sudah di-TTD sampai tahap terakhir → link nonaktif bagi supplier.
        if ($link->ttdSelesai()) {
            throw new BusinessRuleException('Berkas sudah disetujui — link ini tidak menerima dokumen lagi.', errorCode: 'LINK_TUTUP');
        }
        $ulang = $link->status === BsApBerkasLink::TERISI;
        if ($files === []) {
            throw new BusinessRuleException('Unggah minimal satu file.', errorCode: 'LINK_KOSONG');
        }
        $wajib = BsApBerkas::query()->where('is_active', true)->where('is_mandatory', true)->get(['id', 'name']);
        $kurang = $wajib->reject(fn ($j) => collect($files)->contains(fn ($f) => (int) ($f['berkas_id'] ?? 0) === (int) $j->id))->pluck('name')->all();
        if ($kurang) {
            throw new BusinessRuleException('Dokumen wajib belum diunggah: '.implode(', ', $kurang).'.', errorCode: 'LINK_WAJIB');
        }

        $pembuat = User::findOrFail($link->user_id);
        // No. invoice yang diketik supplier di halaman link = No. Invoice Supplier invoice ini (aktif, dikunci di wizard).
        $noInvoice = trim((string) $noInvoice);
        $data = $link->data ?? [];
        if ($noInvoice !== '') {
            $data['supplier_invoice_no'] = mb_substr($noInvoice, 0, 80);
        }
        $isian = $this->isianBerkas($data);
        if ($catatan) {
            $isian['description'] = mb_substr($isian['description'].' — catatan supplier: '.$catatan, 0, 255);
        }
        // Halaman supplier tanpa login: pengajuan TTD (approval) dicatat atas nama pembuat invoice —
        // hanya untuk proses ini, tanpa sesi login (Auth::onceUsingId tidak menyentuh session).
        $sebelum = auth()->user();
        \Illuminate\Support\Facades\Auth::onceUsingId($pembuat->id);
        try {
            // Tetap draft — pembuat invoice mengajukannya ke TTD Dept Head di langkah Struktur.
            $berkas = $this->berkas->create($isian, $files, $pembuat, false);
            $this->berkas->log($berkas, 'diunggah_supplier', 'Lewat link berkas supplier untuk '.$link->invoice_number);
        } finally {
            $sebelum ? auth()->setUser($sebelum) : auth()->forgetUser();
        }
        $lama = $ulang && $link->berkas_upload_id ? BsApBerkasUpload::query()->find($link->berkas_upload_id) : null;
        if ($lama && $lama->id !== $berkas->id) {
            try {
                $this->berkas->cancel($lama, $pembuat, 'Diganti unggahan ulang supplier ('.$berkas->berkas_number.')');
            } catch (BusinessRuleException) {
                // sudah dipakai invoice — dibiarkan
            }
        }
        $riwayat = $link->riwayatUnggahan();
        $riwayat[] = ['at' => now()->toIso8601String(), 'berkas_id' => $berkas->id, 'berkas_number' => $berkas->berkas_number,
            'no_invoice' => $data['supplier_invoice_no'] ?? null, 'file' => count($files), 'catatan' => $catatan];
        $link->update(['data' => $data, 'berkas_upload_id' => $berkas->id, 'status' => BsApBerkasLink::TERISI, 'filled_at' => now(),
            'riwayat' => $riwayat, 'seen_at' => null]);

        $this->notifier->notify([$pembuat], 'berkas.supplier_terisi', $ulang ? 'Supplier mengunggah ulang berkas' : 'Berkas supplier masuk',
            ($ulang ? 'Supplier mengunggah ULANG dokumen di ' : 'Supplier sudah mengisi kelengkapan dokumen di ').$link->invoice_number.' (berkas '.$berkas->berkas_number.').',
            route('bs.ap.create', ['link' => $link->id]));

        return $berkas;
    }

    /** Invoice dari link tersimpan → link selesai. */
    public function pakai(BsApBerkasLink $link, BsApInvoice $invoice): void
    {
        $link->update(['status' => BsApBerkasLink::DIPAKAI, 'ap_invoice_id' => $invoice->id]);
    }

    /** Isian berkas dari wizard invoice. */
    private function isianBerkas(array $d): array
    {
        $nilai = $d['total'] ?? $d['dpp'] ?? null;

        return [
            'upload_date' => now()->toDateString(),
            'document_date' => $d['invoice_date'] ?? now()->toDateString(),
            'needed_date' => $d['due_date'] ?? null,
            'supplier_id' => $d['supplier_id'] ?? null,
            'supplier_invoice_no' => isset($d['supplier_invoice_no']) ? mb_substr((string) $d['supplier_invoice_no'], 0, 80) : null,
            'amount' => is_numeric($nilai) ? (float) $nilai : null,
            'description' => mb_substr(trim((string) ($d['description'] ?? '')) ?: 'Berkas tagihan supplier', 0, 255),
        ];
    }

    /** Simpan isian wizard tanpa kunci yang tidak relevan untuk dipulihkan. */
    private function bersihkan(array $data): array
    {
        unset($data['link_id']);

        return $data;
    }
}
