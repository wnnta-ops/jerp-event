<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApMemorial;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Memorial Hutang — penyesuaian non-tunai atas hutang supplier.
 *   Penambahan : Dr Akun Lawan / Cr Hutang Supplier   (hutang bertambah)
 *   Penghapusan: Dr Hutang Supplier / Cr Akun Lawan   (hutang berkurang / write-off)
 */
final class ApMemorialService
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{memorial_date:string, supplier_id:int, ap_invoice_id?:?int, trx_type:string,
     *              counter_account_id:int, amount:float|int|string, description:string,
     *              kasir?:?string, manual_no?:?string, is_writeoff?:bool} $data
     */
    public function create(array $data, ?int $userId = null): BsApMemorial
    {
        return DB::transaction(function () use ($data, $userId): BsApMemorial {
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new BusinessRuleException('Nilai memorial harus lebih dari 0.', errorCode: 'MEMO_ZERO');
            }
            $type = $data['trx_type'];
            if (! in_array($type, ['penambahan', 'penghapusan'], true)) {
                throw new BusinessRuleException('Tipe memorial tidak valid.', errorCode: 'MEMO_TYPE');
            }

            $inv     = ! empty($data['ap_invoice_id']) ? BsApInvoice::find($data['ap_invoice_id']) : null;
            $payable = $inv?->payable_account_id ?? BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id');
            if (! $payable) {
                throw new BusinessRuleException('Akun hutang belum ditentukan.', errorCode: 'MEMO_NO_PAYABLE');
            }
            if ($type === 'penghapusan' && $inv && $amount > (float) $inv->outstanding + 0.01) {
                throw new BusinessRuleException("Nilai melebihi sisa hutang invoice {$inv->invoice_number}.", errorCode: 'MEMO_OVER');
            }

            // Jurnal.
            $jl = $type === 'penambahan'
                ? [
                    ['account_id' => $data['counter_account_id'], 'debit' => $amount, 'credit' => 0, 'description' => $data['description']],
                    ['account_id' => $payable, 'debit' => 0, 'credit' => $amount, 'description' => 'Tambah hutang'],
                ]
                : [
                    ['account_id' => $payable, 'debit' => $amount, 'credit' => 0, 'description' => 'Hapus/kurangi hutang'],
                    ['account_id' => $data['counter_account_id'], 'debit' => 0, 'credit' => $amount, 'description' => $data['description']],
                ];

            $journal = $this->journal->post([
                'journal_date' => $data['memorial_date'],
                'source'       => 'ap',
                'reference'    => 'MEMO',
                'description'  => 'Memorial Hutang ('.BsApMemorial::TYPES[$type].') — '.$data['description'],
                'created_by'   => $userId,
            ], $jl);

            $memo = BsApMemorial::create([
                'memorial_number'    => $this->nextNumber($data['memorial_date']),
                'memorial_date'      => $data['memorial_date'],
                'supplier_id'        => $data['supplier_id'],
                'ap_invoice_id'      => $inv?->id,
                'trx_type'           => $type,
                'counter_account_id' => $data['counter_account_id'],
                'payable_account_id' => $payable,
                'amount'             => $amount,
                'description'        => $data['description'],
                'kasir'              => $data['kasir'] ?? null,
                'manual_no'          => $data['manual_no'] ?? null,
                'is_writeoff'        => $data['is_writeoff'] ?? false,
                'journal_id'         => $journal->id,
                'created_by'         => $userId,
            ]);

            // Sesuaikan invoice bila terkait.
            if ($inv) {
                if ($type === 'penghapusan') {
                    $newPaid = round((float) $inv->paid_amount + $amount, 2);
                    $inv->update(['paid_amount' => $newPaid, 'status' => $newPaid >= $inv->payable_amount - 0.01 ? 'paid' : 'open']);
                } else {
                    $inv->update(['net_payable' => round((float) $inv->payable_amount + $amount, 2), 'status' => 'open']);
                }
            }

            return $memo;
        });
    }

    private function nextNumber(string $date): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('AP.MEMO', \Illuminate\Support\Carbon::parse($date), [], fn () => $this->nextNumberLama($date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date): string
    {
        $d   = Carbon::parse($date);
        $seq = BsApMemorial::withTrashed()->whereYear('memorial_date', $d->year)->count() + 1;
        do {
            $number = sprintf('AP.MEMO/MUP/%s/%05d', $d->format('y'), $seq);
            $seq++;
        } while (BsApMemorial::withTrashed()->where('memorial_number', $number)->exists());

        return $number;
    }
}
