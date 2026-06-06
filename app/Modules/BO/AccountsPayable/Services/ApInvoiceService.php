<?php

namespace App\Modules\BO\AccountsPayable\Services;

use App\Modules\BO\AccountsPayable\Models\ApInvoice;
use App\Modules\BO\CashBank\Models\CbVoucher;
use App\Modules\BO\CashBank\Services\CbVoucherService;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ApInvoiceService
{
    public function __construct(
        private readonly JurnalPostingServiceInterface $posting,
        private readonly NumeratorServiceInterface $numerator,
        private readonly CbVoucherService $cashBank,
    ) {}

    public function nextNumber(int $companyId): string
    {
        return $this->numerator->next('INV-AP', $companyId);
    }

    public function approve(ApInvoice $invoice): ApInvoice
    {
        if ($invoice->status !== 'DRAFT') {
            throw new RuntimeException("Invoice {$invoice->no_invoice} sudah {$invoice->status}.");
        }

        $invoice->update([
            'status' => 'APPROVED',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
        ]);

        return $invoice->refresh();
    }

    /**
     * Post the payable to the GL (debit beban/PPN, credit hutang + PPh).
     */
    public function post(ApInvoice $invoice): ApInvoice
    {
        if (! in_array($invoice->status, ['DRAFT', 'APPROVED'], true)) {
            throw new RuntimeException("Invoice {$invoice->no_invoice} berstatus {$invoice->status}, tidak bisa diposting.");
        }

        return DB::transaction(function () use ($invoice) {
            $total = $invoice->computeTotal();
            $jurnal = $this->posting->post($invoice);

            $invoice->update([
                'total' => $total,
                'sisa' => $total,
                'jurnal_id' => $jurnal->id,
                'status' => 'POSTED',
            ]);

            return $invoice->refresh();
        });
    }

    /**
     * Pay (partially) the payable by issuing a CB cash-out voucher (BKK) that
     * debits hutang usaha and credits the bank.
     */
    public function pay(ApInvoice $invoice, float $amount, string $akunKas, ?int $bankId = null): ApInvoice
    {
        if ($amount <= 0) {
            throw new RuntimeException('Nominal pembayaran harus > 0.');
        }
        if (! in_array($invoice->status, ['POSTED', 'SEBAGIAN'], true)) {
            throw new RuntimeException("Invoice {$invoice->no_invoice} berstatus {$invoice->status}, tidak bisa dibayar.");
        }

        return DB::transaction(function () use ($invoice, $amount, $akunKas, $bankId) {
            $voucher = CbVoucher::create([
                'jenis' => 'BKK',
                'no_voucher' => $this->cashBank->nextNumber('BKK', (int) $invoice->company_id),
                'tgl' => now()->toDateString(),
                'company_id' => $invoice->company_id,
                'bank_id' => $bankId,
                'akun_kas' => $akunKas,
                'akun_lawan' => $invoice->akun_hutang,
                'nominal' => $amount,
                'pihak' => $invoice->supplier?->nama,
                'status' => 'DRAFT',
                'keterangan' => 'Pembayaran '.$invoice->no_invoice,
            ]);
            $this->cashBank->post($voucher);

            $dibayar = (float) $invoice->dibayar + $amount;
            $sisa = max(0, round((float) $invoice->total - $dibayar, 2));

            $invoice->update([
                'dibayar' => $dibayar,
                'sisa' => $sisa,
                'status' => $sisa <= 0 ? 'LUNAS' : 'SEBAGIAN',
            ]);

            return $invoice->refresh();
        });
    }
}
