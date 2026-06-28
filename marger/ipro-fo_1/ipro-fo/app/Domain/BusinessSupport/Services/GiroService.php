<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsGiro;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cek/Giro Masuk.
 *   Penerimaan : Dr Piutang Giro   / Cr Piutang Usaha (lawan)
 *   Kliring    : Dr Bank           / Cr Piutang Giro
 *   Tolakan    : Dr Piutang Usaha  / Cr Piutang Giro
 */
final class GiroService
{
    public function __construct(private readonly JournalService $journal) {}

    /** @param array{receive_date:string, due_date:string, bank_name:string, cheque_number?:?string, drawer:string, amount:float|int|string, counter_account_id:int} $data */
    public function receive(array $data, ?int $userId = null): BsGiro
    {
        return DB::transaction(function () use ($data, $userId): BsGiro {
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new BusinessRuleException('Nilai giro harus lebih dari 0.', errorCode: 'GIRO_ZERO');
            }
            $giroAccount = BsAccount::where('code', config('business_support.gl_accounts.giro_in'))->value('id');
            if (! $giroAccount) {
                throw new BusinessRuleException('Akun Piutang Giro belum diset.', errorCode: 'GIRO_NO_ACCOUNT');
            }

            $giro = BsGiro::create([
                'giro_number'        => $this->nextNumber($data['receive_date']),
                'receive_date'       => $data['receive_date'],
                'due_date'           => $data['due_date'],
                'bank_name'          => $data['bank_name'],
                'cheque_number'      => $data['cheque_number'] ?? null,
                'drawer'             => $data['drawer'],
                'amount'             => $amount,
                'giro_account_id'    => $giroAccount,
                'counter_account_id' => $data['counter_account_id'],
                'status'             => 'in_hand',
                'created_by'         => $userId,
            ]);

            $journal = $this->journal->post([
                'journal_date' => $giro->receive_date->toDateString(),
                'source'       => 'cash',
                'reference'    => $giro->giro_number,
                'description'  => 'Terima Cek/Giro '.$giro->bank_name.' — '.$giro->drawer,
                'created_by'   => $userId,
            ], [
                ['account_id' => $giroAccount, 'debit' => $amount, 'credit' => 0, 'description' => 'Cek/Giro di tangan'],
                ['account_id' => $giro->counter_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Piutang dialihkan ke giro'],
            ]);

            $giro->update(['receive_journal_id' => $journal->id]);

            return $giro;
        });
    }

    /** Kliring — giro cair ke bank. @param array{settled_date:string, cash_account_id:int} $data */
    public function clear(BsGiro $giro, array $data, ?int $userId = null): BsGiro
    {
        return $this->settle($giro, 'cleared', $data['settled_date'], (int) $data['cash_account_id'], $userId);
    }

    /** Tolakan — giro ditolak, piutang dipulihkan. @param array{settled_date:string} $data */
    public function bounce(BsGiro $giro, array $data, ?int $userId = null): BsGiro
    {
        return $this->settle($giro, 'bounced', $data['settled_date'], null, $userId);
    }

    private function settle(BsGiro $giro, string $status, string $date, ?int $cashAccountId, ?int $userId): BsGiro
    {
        return DB::transaction(function () use ($giro, $status, $date, $cashAccountId, $userId): BsGiro {
            if ($giro->status !== 'in_hand') {
                throw new BusinessRuleException('Cek/Giro sudah diproses.', errorCode: 'GIRO_DONE');
            }
            $amount = round((float) $giro->amount, 2);

            if ($status === 'cleared') {
                if (! $cashAccountId) {
                    throw new BusinessRuleException('Rekening bank tujuan wajib diisi untuk kliring.', errorCode: 'GIRO_NO_BANK');
                }
                $lines = [
                    ['account_id' => $cashAccountId, 'debit' => $amount, 'credit' => 0, 'description' => 'Giro cair'],
                    ['account_id' => $giro->giro_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Pelunasan giro'],
                ];
                $desc = 'Kliring Cek/Giro '.$giro->giro_number;
            } else {
                $lines = [
                    ['account_id' => $giro->counter_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Piutang dipulihkan (giro ditolak)'],
                    ['account_id' => $giro->giro_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Giro ditolak'],
                ];
                $desc = 'Tolakan Cek/Giro '.$giro->giro_number;
            }

            $journal = $this->journal->post([
                'journal_date' => $date,
                'source'       => 'cash',
                'reference'    => $giro->giro_number,
                'description'  => $desc,
                'created_by'   => $userId,
            ], $lines);

            $giro->update([
                'status'            => $status,
                'settled_date'      => $date,
                'cash_account_id'   => $cashAccountId,
                'settle_journal_id' => $journal->id,
            ]);

            return $giro;
        });
    }

    private function nextNumber(string $date): string
    {
        $d     = Carbon::parse($date);
        $count = BsGiro::withTrashed()->whereYear('receive_date', $d->year)->count() + 1;

        return sprintf('GIRO/%s/%04d', $d->format('y').$d->format('m'), $count);
    }
}
