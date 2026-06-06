<?php

namespace App\Modules\BO\AccountsReceivable\Services;

use App\Modules\BO\AccountsReceivable\Models\ArInvoice;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use App\Modules\BO\GeneralLedger\Support\AdHocPosting;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ArInvoiceService
{
    public function __construct(
        private readonly JurnalPostingServiceInterface $posting,
        private readonly NumeratorServiceInterface $numerator,
    ) {}

    public function nextNumber(int $companyId): string
    {
        return $this->numerator->next('INV-AR', $companyId);
    }

    /**
     * Post the receivable to the GL (debit piutang, credit pendapatan) and
     * open it for collection (status BELUM).
     */
    public function post(ArInvoice $invoice): ArInvoice
    {
        if (! in_array($invoice->status, ['DRAFT'], true)) {
            throw new RuntimeException("Invoice {$invoice->no_invoice} sudah {$invoice->status}.");
        }

        return DB::transaction(function () use ($invoice) {
            $jurnal = $this->posting->post($invoice);

            $invoice->update([
                'jurnal_id' => $jurnal->id,
                'sisa' => $invoice->nominal,
                'status' => 'BELUM',
            ]);

            return $invoice->refresh();
        });
    }

    /**
     * Record a payment against the invoice (the cash side is a separate CB BKM).
     */
    public function recordPayment(ArInvoice $invoice, float $amount): ArInvoice
    {
        if ($amount <= 0) {
            throw new RuntimeException('Nominal pembayaran harus > 0.');
        }
        if (in_array($invoice->status, ['LUNAS', 'BATAL', 'DRAFT'], true)) {
            throw new RuntimeException("Invoice {$invoice->no_invoice} berstatus {$invoice->status}, tidak menerima pembayaran.");
        }

        $bayar = (float) $invoice->nominal_bayar + $amount;
        $total = (float) $invoice->nominal + (float) $invoice->denda;
        $sisa = max(0, round($total - $bayar, 2));

        $invoice->update([
            'nominal_bayar' => $bayar,
            'sisa' => $sisa,
            'status' => $sisa <= 0 ? 'LUNAS' : 'SEBAGIAN',
        ]);

        return $invoice->refresh();
    }

    /**
     * Add a late-payment penalty: increases receivable + posts a denda journal.
     */
    public function addDenda(ArInvoice $invoice, float $denda, string $akunDenda = '4.1.03.001'): ArInvoice
    {
        if ($denda <= 0) {
            throw new RuntimeException('Denda harus > 0.');
        }

        return DB::transaction(function () use ($invoice, $denda, $akunDenda) {
            $this->posting->post(AdHocPosting::make(
                lines: [
                    JurnalLineDTO::debet($invoice->akun_piutang, $denda, 'Denda '.$invoice->no_invoice),
                    JurnalLineDTO::kredit($akunDenda, $denda, 'Pendapatan denda '.$invoice->no_invoice),
                ],
                documentType: 'DENDA-AR',
                documentNo: $invoice->no_invoice,
                companyId: (int) $invoice->company_id,
                date: now(),
                description: 'Denda keterlambatan '.$invoice->no_invoice,
            ));

            $invoice->update([
                'denda' => (float) $invoice->denda + $denda,
                'sisa' => (float) $invoice->sisa + $denda,
                'status' => $invoice->status === 'LUNAS' ? 'SEBAGIAN' : $invoice->status,
            ]);

            return $invoice->refresh();
        });
    }
}
