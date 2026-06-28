<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsArPayment;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Penerimaan piutang (Account Receivable) + jurnal GL otomatis.
 *   Dr Kas/Bank  /  Cr Piutang Usaha
 */
final class PayArInvoice
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{payment_date:string, cash_account_id:int, amount:float|int|string, reference?:?string} $data
     */
    public function execute(BsArInvoice $inv, array $data, ?int $userId = null): BsArPayment
    {
        return DB::transaction(function () use ($inv, $data, $userId): BsArPayment {
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0) {
                throw new BusinessRuleException('Nominal penerimaan harus lebih dari 0.', errorCode: 'AR_PAY_ZERO');
            }
            if ($amount > (float) $inv->outstanding + 0.01) {
                throw new BusinessRuleException(
                    'Nominal ('.number_format($amount, 2).') melebihi sisa piutang ('.number_format((float) $inv->outstanding, 2).').',
                    errorCode: 'AR_PAY_OVER',
                );
            }

            $journal = $this->journal->post([
                'journal_date' => $data['payment_date'],
                'source'       => 'ar',
                'reference'    => $inv->invoice_number,
                'description'  => 'Penerimaan AR '.$inv->invoice_number,
                'created_by'   => $userId,
            ], [
                ['account_id' => $data['cash_account_id'], 'debit' => $amount, 'credit' => 0, 'description' => 'Kas/Bank masuk'],
                ['account_id' => $inv->receivable_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Pelunasan piutang '.($inv->customer?->full_name ?? '')],
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
                'status'      => $newPaid >= $inv->receivable_amount - 0.01 ? 'paid' : 'open',
            ]);

            return $pay;
        });
    }
}
