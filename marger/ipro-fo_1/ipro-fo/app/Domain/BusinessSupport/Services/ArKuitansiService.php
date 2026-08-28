<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsArKuitansi;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kuitansi Piutang — voucher penerimaan piutang dari customer (multi-invoice + void).
 *   Terima : Dr Kas/Bank (total) / Cr Piutang Usaha (per invoice)
 *   Void   : Dr Piutang Usaha / Cr Kas/Bank (membatalkan penerimaan)
 */
final class ArKuitansiService
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{kuitansi_date:string, customer_id:int, cash_account_id:int, alat_transaksi?:string,
     *              kasir?:?string, manual_no?:?string, description?:?string,
     *              lines:array<int,array{ar_invoice_id:int, amount:float|int|string}>} $data
     */
    public function create(array $data, ?int $userId = null): BsArKuitansi
    {
        return DB::transaction(function () use ($data, $userId): BsArKuitansi {
            $invoices = [];
            $jl = [];
            $total = 0.0;
            foreach ($data['lines'] as $l) {
                $amount = round((float) $l['amount'], 2);
                if ($amount <= 0) {
                    continue;
                }
                $inv = BsArInvoice::findOrFail($l['ar_invoice_id']);
                if ((int) $inv->customer_id !== (int) $data['customer_id']) {
                    throw new BusinessRuleException('Invoice tidak sesuai customer.', errorCode: 'KUI_CUSTOMER');
                }
                if ($amount > (float) $inv->outstanding + 0.01) {
                    throw new BusinessRuleException("Nominal melebihi sisa piutang invoice {$inv->invoice_number}.", errorCode: 'KUI_OVER');
                }
                $invoices[] = [$inv, $amount];
                $jl[] = ['account_id' => $inv->receivable_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Pelunasan '.$inv->invoice_number];
                $total += $amount;
            }
            $total = round($total, 2);
            if ($total <= 0) {
                throw new BusinessRuleException('Tidak ada invoice yang diterima.', errorCode: 'KUI_EMPTY');
            }
            // Sisi debit: kas/bank menerima total.
            array_unshift($jl, ['account_id' => $data['cash_account_id'], 'debit' => $total, 'credit' => 0, 'description' => 'Penerimaan piutang customer']);

            $journal = $this->journal->post([
                'journal_date' => $data['kuitansi_date'],
                'source'       => 'ar',
                'reference'    => 'KUI',
                'description'  => 'Kuitansi Piutang — '.($data['description'] ?? 'penerimaan customer'),
                'created_by'   => $userId,
            ], $jl);

            $kui = BsArKuitansi::create([
                'kuitansi_number' => $this->nextNumber($data['kuitansi_date']),
                'kuitansi_date'   => $data['kuitansi_date'],
                'customer_id'     => $data['customer_id'],
                'cash_account_id' => $data['cash_account_id'],
                'alat_transaksi'  => $data['alat_transaksi'] ?? 'transfer',
                'kasir'           => $data['kasir'] ?? null,
                'manual_no'       => $data['manual_no'] ?? null,
                'description'     => $data['description'] ?? null,
                'total'           => $total,
                'status'          => 'active',
                'journal_id'      => $journal->id,
                'created_by'      => $userId,
            ]);

            foreach ($invoices as [$inv, $amount]) {
                $pay = $inv->payments()->create([
                    'payment_date'    => $data['kuitansi_date'],
                    'cash_account_id' => $data['cash_account_id'],
                    'amount'          => $amount,
                    'reference'       => $kui->kuitansi_number,
                    'journal_id'      => $journal->id,
                    'created_by'      => $userId,
                ]);
                $kui->lines()->create(['ar_invoice_id' => $inv->id, 'amount' => $amount, 'payment_id' => $pay->id]);

                $newPaid = round((float) $inv->paid_amount + $amount, 2);
                $inv->update(['paid_amount' => $newPaid, 'status' => $newPaid >= $inv->receivable_amount - 0.01 ? 'paid' : 'open']);
            }

            return $kui;
        });
    }

    public function void(BsArKuitansi $kui, ?int $userId = null): BsArKuitansi
    {
        return DB::transaction(function () use ($kui, $userId): BsArKuitansi {
            if ($kui->status !== 'active') {
                throw new BusinessRuleException('Kuitansi sudah void.', errorCode: 'KUI_VOID');
            }
            $kui->load('lines.invoice');

            $jl = [['account_id' => $kui->cash_account_id, 'debit' => 0, 'credit' => (float) $kui->total, 'description' => 'Pembatalan '.$kui->kuitansi_number]];
            foreach ($kui->lines as $l) {
                $inv = $l->invoice;
                $jl[] = ['account_id' => $inv->receivable_account_id, 'debit' => (float) $l->amount, 'credit' => 0, 'description' => 'Kembalikan piutang '.$inv->invoice_number];
                // Kembalikan saldo invoice & hapus payment.
                $newPaid = round((float) $inv->paid_amount - (float) $l->amount, 2);
                $inv->update(['paid_amount' => max(0, $newPaid), 'status' => 'open']);
                if ($l->payment_id) {
                    $inv->payments()->where('id', $l->payment_id)->delete();
                }
            }

            $journal = $this->journal->post([
                'journal_date' => now()->toDateString(),
                'source'       => 'ar',
                'reference'    => $kui->kuitansi_number,
                'description'  => 'VOID Kuitansi Piutang '.$kui->kuitansi_number,
                'created_by'   => $userId,
            ], $jl);

            $kui->update(['status' => 'void', 'void_journal_id' => $journal->id, 'voided_at' => now()->toDateString()]);

            return $kui;
        });
    }

    /** AR.KUI/MUP/yy/[bulan-romawi]/00001 */
    private function nextNumber(string $date): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('AR.KUI', Carbon::parse($date), [], fn () => $this->nextNumberLama($date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date): string
    {
        $d = Carbon::parse($date);
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$d->month - 1];
        $seq = BsArKuitansi::withTrashed()->whereYear('kuitansi_date', $d->year)->count() + 1;
        do {
            $number = sprintf('AR.KUI/MUP/%s/%s/%05d', $d->format('y'), $roman, $seq);
            $seq++;
        } while (BsArKuitansi::withTrashed()->where('kuitansi_number', $number)->exists());

        return $number;
    }
}
