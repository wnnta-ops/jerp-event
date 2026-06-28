<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsArMemorial;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Memorial Piutang — penyesuaian non-tunai atas piutang customer.
 *   Penambahan : Dr Piutang Customer / Cr Akun Lawan   (piutang bertambah)
 *   Penghapusan: Dr Akun Lawan / Cr Piutang Customer   (piutang berkurang / write-off bad debt)
 */
final class ArMemorialService
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{memorial_date:string, customer_id:int, ar_invoice_id?:?int, trx_type:string,
     *              counter_account_id:int, amount:float|int|string, description:string,
     *              kasir?:?string, manual_no?:?string, is_writeoff?:bool} $data
     */
    public function create(array $data, ?int $userId = null): BsArMemorial
    {
        return DB::transaction(function () use ($data, $userId): BsArMemorial {
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new BusinessRuleException('Nilai memorial harus lebih dari 0.', errorCode: 'MEMO_ZERO');
            }
            $type = $data['trx_type'];
            if (! in_array($type, ['penambahan', 'penghapusan'], true)) {
                throw new BusinessRuleException('Tipe memorial tidak valid.', errorCode: 'MEMO_TYPE');
            }

            $inv        = ! empty($data['ar_invoice_id']) ? BsArInvoice::find($data['ar_invoice_id']) : null;
            $receivable = $inv?->receivable_account_id ?? BsAccount::where('code', config('business_support.gl_accounts.receivable'))->value('id');
            if (! $receivable) {
                throw new BusinessRuleException('Akun piutang belum ditentukan.', errorCode: 'MEMO_NO_RECEIVABLE');
            }
            if ($type === 'penghapusan' && $inv && $amount > (float) $inv->outstanding + 0.01) {
                throw new BusinessRuleException("Nilai melebihi sisa piutang invoice {$inv->invoice_number}.", errorCode: 'MEMO_OVER');
            }

            // Jurnal.
            $jl = $type === 'penambahan'
                ? [
                    ['account_id' => $receivable, 'debit' => $amount, 'credit' => 0, 'description' => 'Tambah piutang'],
                    ['account_id' => $data['counter_account_id'], 'debit' => 0, 'credit' => $amount, 'description' => $data['description']],
                ]
                : [
                    ['account_id' => $data['counter_account_id'], 'debit' => $amount, 'credit' => 0, 'description' => $data['description']],
                    ['account_id' => $receivable, 'debit' => 0, 'credit' => $amount, 'description' => 'Hapus/kurangi piutang'],
                ];

            $journal = $this->journal->post([
                'journal_date' => $data['memorial_date'],
                'source'       => 'ar',
                'reference'    => 'MEMO',
                'description'  => 'Memorial Piutang ('.BsArMemorial::TYPES[$type].') — '.$data['description'],
                'created_by'   => $userId,
            ], $jl);

            $memo = BsArMemorial::create([
                'memorial_number'       => $this->nextNumber($data['memorial_date']),
                'memorial_date'         => $data['memorial_date'],
                'customer_id'           => $data['customer_id'],
                'ar_invoice_id'         => $inv?->id,
                'trx_type'              => $type,
                'counter_account_id'    => $data['counter_account_id'],
                'receivable_account_id' => $receivable,
                'amount'                => $amount,
                'description'           => $data['description'],
                'kasir'                 => $data['kasir'] ?? null,
                'manual_no'             => $data['manual_no'] ?? null,
                'is_writeoff'           => $data['is_writeoff'] ?? false,
                'journal_id'            => $journal->id,
                'created_by'            => $userId,
            ]);

            // Sesuaikan invoice bila terkait.
            if ($inv) {
                if ($type === 'penghapusan') {
                    $newPaid = round((float) $inv->paid_amount + $amount, 2);
                    $inv->update(['paid_amount' => $newPaid, 'status' => $newPaid >= $inv->receivable_amount - 0.01 ? 'paid' : 'open']);
                } else {
                    $inv->update(['net_receivable' => round((float) $inv->receivable_amount + $amount, 2), 'status' => 'open']);
                }
            }

            return $memo;
        });
    }

    private function nextNumber(string $date): string
    {
        $d   = Carbon::parse($date);
        $seq = BsArMemorial::withTrashed()->whereYear('memorial_date', $d->year)->count() + 1;
        do {
            $number = sprintf('AR.MEMO/MUP/%s/%05d', $d->format('y'), $seq);
            $seq++;
        } while (BsArMemorial::withTrashed()->where('memorial_number', $number)->exists());

        return $number;
    }
}
