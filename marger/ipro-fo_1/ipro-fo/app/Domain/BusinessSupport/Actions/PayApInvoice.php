<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApPayment;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Pelunasan hutang (Account Payable) + jurnal GL otomatis.
 *   Dr Hutang Usaha  /  Cr Kas/Bank
 */
final class PayApInvoice
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{payment_date:string, cash_account_id:int, amount:float|int|string, reference?:?string} $data
     */
    public function execute(BsApInvoice $inv, array $data, ?int $userId = null): BsApPayment
    {
        return DB::transaction(function () use ($inv, $data, $userId): BsApPayment {
            $inv->assertBolehDibayar(); // invoice dari Upload Berkas: tunggu approval penuh
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new BusinessRuleException('Nominal pembayaran harus lebih dari 0.', errorCode: 'AP_PAY_ZERO');
            }
            if ($amount > (float) $inv->outstanding + 0.01) {
                throw new BusinessRuleException(
                    'Nominal ('.number_format($amount, 2).') melebihi sisa hutang ('.number_format((float) $inv->outstanding, 2).').',
                    errorCode: 'AP_PAY_OVER',
                );
            }

            $journal = $this->journal->post([
                'journal_date' => $data['payment_date'],
                'source'       => 'ap',
                'reference'    => $inv->invoice_number,
                'description'  => 'Pelunasan AP '.$inv->invoice_number,
                'created_by'   => $userId,
            ], [
                ['account_id' => $inv->payable_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Pelunasan hutang '.($inv->supplier?->name ?? '')],
                ['account_id' => $data['cash_account_id'], 'debit' => 0, 'credit' => $amount, 'description' => 'Kas/Bank keluar'],
            ]);

            $pay = $inv->payments()->create([
                'payment_date'    => $data['payment_date'],
                'cash_account_id' => $data['cash_account_id'],
                'amount'          => $amount,
                'reference'       => $data['reference'] ?? null,
                'journal_id'      => $journal->id,
                'created_by'      => $userId,
            ]);

            $newPaid = round((float) $inv->paid_amount + $amount, 2);
            $inv->update([
                'paid_amount' => $newPaid,
                'status'      => $newPaid >= $inv->payable_amount - 0.01 ? 'paid' : 'open',
            ]);

            return $pay;
        });
    }
}
