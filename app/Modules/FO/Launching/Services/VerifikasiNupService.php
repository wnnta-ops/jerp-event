<?php

namespace App\Modules\FO\Launching\Services;

use App\Modules\FO\Launching\Jobs\KirimKonfirmasiNupJob;
use App\Modules\FO\Launching\Jobs\KirimKwitansiUtjJob;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use App\Modules\FO\Shared\Integrations\Xendit\XenditGatewayInterface;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Drives the "NUP → Verifikasi Data" workflow: UTJ payment via Xendit, then the
 * verification team's decision (approve/pending/reject/refund). Only an APPROVE
 * opens unit selection — it creates a draft reservation from the priority unit.
 */
class VerifikasiNupService
{
    public function __construct(
        private readonly XenditGatewayInterface $xendit,
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    /**
     * Create (or recreate) the Xendit UTJ invoice for a registration.
     */
    public function createXenditInvoice(NupRegistration $reg, ?float $amount = null): NupRegistration
    {
        $utj = $amount ?? (float) $reg->utj_amount;
        if ($utj <= 0) {
            throw new RuntimeException('Nominal UTJ harus > 0 untuk membuat invoice Xendit.');
        }

        // Referensi pembayaran khusus Xendit — BUKAN no_nup (no_nup terbit saat approval).
        // Memakai penomoran KWI (00400/KWI/6/2026) meneruskan bentuk sistem lama, dan
        // nomor yang SAMA dipakai sebagai external_id sehingga kwitansi, dashboard
        // Xendit, dan pencocokan webhook semuanya merujuk satu nomor.
        // FORWARD-ONLY: yang sudah punya external_id tetap memakai nilai lamanya —
        // menggantinya akan memutus pencocokan webhook untuk invoice yang sudah terbit.
        $externalId = $reg->xendit_external_id ?: $this->numerator->next('KWI');

        $invoice = $this->xendit->createInvoice(
            externalId: $externalId,
            amount: $utj,
            payerEmail: (string) $reg->email,
            description: 'UTJ Pendaftaran NUP — '.$reg->nama_pendaftar.' (Ref '.$externalId.')',
        );

        $reg->update([
            'utj_amount' => $utj,
            'xendit_external_id' => $externalId,
            'xendit_invoice_id' => $invoice->id,
            'xendit_invoice_url' => $invoice->invoiceUrl,
            'xendit_status' => $invoice->status,
            'verif_status' => NupRegistration::VERIF_MENUNGGU_BAYAR,
        ]);

        return $reg->refresh();
    }

    public function expireXendit(NupRegistration $reg): NupRegistration
    {
        if ($reg->xendit_invoice_id) {
            $this->xendit->expireInvoice($reg->xendit_invoice_id);
        }
        $reg->update(['xendit_status' => 'EXPIRED']);

        return $reg->refresh();
    }

    /**
     * Mark UTJ as paid (from webhook or manual confirmation) and auto-post the
     * cash receipt to the GL via the FOBO bridge. Idempotent.
     */
    public function markPaid(NupRegistration $reg): NupRegistration
    {
        if ($reg->isUtjPaid()) {
            return $reg; // already paid — no-op
        }

        $reg = DB::transaction(function () use ($reg) {
            $reg->update([
                'xendit_status' => 'PAID',
                'xendit_paid_at' => now(),
                'tgl_payment' => now(),
                'booking_fee_paid' => $reg->utj_amount,
                'verif_status' => NupRegistration::VERIF_PAID,
            ]);

            // FOBO: post UTJ cash receipt to the BO General Ledger. Pembayaran terjadi
            // sebelum approval (no_nup belum terbit), jadi referensi = xendit_external_id.
            $paymentRef = $reg->xendit_external_id ?: $reg->no_nup;
            FoFinancialEvent::dispatch(
                jenis: 'KAS_MASUK',
                sourceModul: 'FO.NUP',
                sourceNo: $paymentRef,
                amount: (float) $reg->utj_amount,
                keterangan: 'UTJ Pendaftaran NUP '.$paymentRef.' — '.$reg->nama_pendaftar,
                meta: ['nup_registration_id' => $reg->id],
            );

            return $reg->refresh();
        });

        // Kwitansi UTJ ke customer (WA + email). DI LUAR transaksi dengan sengaja:
        // notifikasi tidak boleh ikut membatalkan pencatatan pembayaran bila gagal,
        // dan job tidak boleh jalan sebelum datanya benar-benar tersimpan.
        // Aman dari kiriman ganda: markPaid() sudah no-op bila status PAID.
        KirimKwitansiUtjJob::dispatch($reg->id);

        return $reg;
    }

    /**
     * Approve the registration → opens unit selection and registers the customer.
     * Pilihan unit is only a non-binding text preference, so a draft reservation
     * is created only when the verifier assigns a real available unit ($unitId).
     * Requires UTJ PAID, unless a manual approval with a mandatory note is used
     * (e.g. payment confirmed on the Xendit dashboard but not yet synced).
     */
    public function approve(NupRegistration $reg, ?string $note = null, bool $manual = false, ?int $unitId = null, ?string $rekomendasiUnit = null): NupRegistration
    {
        if ($reg->verif_status === NupRegistration::VERIF_APPROVED) {
            throw new RuntimeException('NUP '.$reg->no_nup.' sudah di-approve.');
        }
        if (in_array($reg->verif_status, [NupRegistration::VERIF_REJECTED, NupRegistration::VERIF_REFUNDED], true)) {
            throw new RuntimeException('NUP '.$reg->no_nup.' berstatus '.$reg->verif_status.', tidak bisa di-approve.');
        }

        if (! $reg->isUtjPaid()) {
            if (! $manual) {
                throw new RuntimeException('UTJ belum PAID. Gunakan approve manual disertai alasan bila pembayaran sudah dicek langsung di Xendit.');
            }
            if (trim((string) $note) === '') {
                throw new RuntimeException('Approve manual wajib menyertakan alasan/catatan.');
            }
        }

        $reg = DB::transaction(function () use ($reg, $note, $manual, $unitId, $rekomendasiUnit) {
            $customer = $this->ensureCustomer($reg);
            $reservasi = $unitId !== null ? $this->createDraftReservasi($reg, $customer, $unitId) : null;

            // Terbitkan urutan FIFO per event (skip null urutan yang belum approved).
            $urutan = $reg->urutan ?? (int) (NupRegistration::withoutGlobalScopes()
                ->where('nup_event_id', $reg->nup_event_id)
                ->whereNotNull('urutan')
                ->lockForUpdate()
                ->max('urutan') ?? 0) + 1;

            // Terbitkan No NUP global (cross-event) — format ringkas NUP-{seq:5},
            // tanpa tanggal. Hanya kalau belum ada (idempotent untuk re-approve scenario).
            $noNup = $reg->no_nup ?: $this->numerator->next('NUP', companyId: null);

            $reg->update([
                'verif_status' => NupRegistration::VERIF_APPROVED,
                'no_nup' => $noNup,
                'urutan' => $urutan,
                'customer_id' => $customer->id,
                'reservasi_id' => $reservasi?->id,
                'rekomendasi_unit' => $rekomendasiUnit ?: $reg->rekomendasi_unit,
                'verified_by' => Auth::id(),
                'verified_at' => now(),
                'is_manual_approve' => $manual,
                'verif_note' => $note,
            ]);

            return $reg->refresh();
        });

        // Kirim konfirmasi (email + WA stub) berisi nomor urut NUP yang baru terbit.
        KirimKonfirmasiNupJob::dispatch($reg->id);

        return $reg;
    }

    public function pending(NupRegistration $reg, ?string $note = null): NupRegistration
    {
        $this->assertActionable($reg, 'pending');
        $reg->update(['verif_status' => NupRegistration::VERIF_PENDING, 'verif_note' => $note]);

        return $reg->refresh();
    }

    public function reject(NupRegistration $reg, string $note): NupRegistration
    {
        $this->assertActionable($reg, 'reject');
        if (trim($note) === '') {
            throw new RuntimeException('Reject wajib menyertakan alasan.');
        }
        $reg->update([
            'verif_status' => NupRegistration::VERIF_REJECTED,
            'verif_note' => $note,
            'verified_by' => Auth::id(),
            'verified_at' => now(),
        ]);

        return $reg->refresh();
    }

    public function refund(NupRegistration $reg, string $note): NupRegistration
    {
        if (trim($note) === '') {
            throw new RuntimeException('Refund wajib menyertakan alasan.');
        }

        if ($reg->isUtjPaid() && $reg->xendit_invoice_id) {
            $this->xendit->refund($reg->xendit_invoice_id, (float) $reg->utj_amount, $note);
        }

        $reg->update([
            'verif_status' => NupRegistration::VERIF_REFUNDED,
            'verif_note' => $note,
            'verified_by' => Auth::id(),
            'verified_at' => now(),
        ]);

        return $reg->refresh();
    }

    private function assertActionable(NupRegistration $reg, string $action): void
    {
        if (in_array($reg->verif_status, [NupRegistration::VERIF_REFUNDED], true)) {
            throw new RuntimeException('NUP '.$reg->no_nup.' sudah REFUNDED, tidak bisa di-'.$action.'.');
        }
    }

    private function ensureCustomer(NupRegistration $reg): Customer
    {
        $projectId = $reg->project_pref_id ?: $reg->event?->project_id;

        if ($reg->customer_id && ($existing = Customer::find($reg->customer_id))) {
            if (empty($existing->project_id) && $projectId) {
                $existing->update(['project_id' => $projectId]);
            }

            return $existing;
        }

        return Customer::create([
            'code' => $this->numerator->next('CUST'),
            'project_id' => $projectId,
            'full_name' => $reg->nama_pendaftar,
            'nik' => $reg->nik,
            'phone' => $reg->phone,
            'mobile' => $reg->phone,
            'email' => $reg->email,
        ]);
    }

    private function createDraftReservasi(NupRegistration $reg, Customer $customer, int $unitId): Reservasi
    {
        $unit = Unit::find($unitId);

        return Reservasi::create([
            'no_reservasi' => $this->numerator->next('RSV'),
            'customer_id' => $customer->id,
            'unit_id' => $unitId,
            'tgl_reservasi' => now()->toDateString(),
            'tgl_kadaluarsa' => now()->addDays(7)->toDateString(),
            'harga_jual' => (float) ($unit->harga_jual ?? 0),
            'booking_fee' => (float) $reg->utj_amount,
            'status' => 'AKTIF',
            'keterangan' => 'Draft dari approval NUP '.$reg->no_nup,
        ]);
    }
}
