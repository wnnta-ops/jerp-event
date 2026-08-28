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
                'source'             => 'manual',
                'created_by'         => $userId,
            ]);

            // Entri manual → jurnal terima langsung diposting.
            return $this->postReceiveJournal($giro, $userId);
        });
    }

    /**
     * Posting jurnal PENERIMAAN untuk giro Di Tangan yang BELUM di-jurnal (mis. kiriman
     * FO Bilyet Giro). Dr Piutang Giro / Cr Piutang Usaha (lawan). Idempotent.
     */
    public function postReceiveJournal(BsGiro $giro, ?int $userId = null): BsGiro
    {
        return DB::transaction(function () use ($giro, $userId): BsGiro {
            if ($giro->receive_journal_id) {
                throw new BusinessRuleException('Jurnal penerimaan giro sudah diposting.', errorCode: 'GIRO_JOURNALED');
            }
            $amount = round((float) $giro->amount, 2);
            $giroAccount = $giro->giro_account_id ?: BsAccount::where('code', config('business_support.gl_accounts.giro_in'))->value('id');
            $counter = $giro->counter_account_id ?: BsAccount::where('code', config('business_support.gl_accounts.receivable'))->value('id');
            if (! $giroAccount || ! $counter) {
                throw new BusinessRuleException('Akun Piutang Giro / Piutang Usaha belum diset.', errorCode: 'GIRO_NO_ACCOUNT');
            }

            $journal = $this->journal->post([
                'journal_date' => $giro->receive_date->toDateString(),
                'source'       => 'cash',
                'status'       => 'posted', // Treasury: penerimaan giro langsung posting → Piutang Giro terlihat di ledger.
                'reference'    => $giro->giro_number,
                'description'  => 'Terima Cek/Giro '.$giro->bank_name.' — '.$giro->drawer,
                'created_by'   => $userId,
            ], [
                ['account_id' => $giroAccount, 'debit' => $amount, 'credit' => 0, 'description' => 'Cek/Giro di tangan'],
                ['account_id' => $counter, 'debit' => 0, 'credit' => $amount, 'description' => 'Piutang dialihkan ke giro'],
            ]);

            $giro->update([
                'giro_account_id'    => $giroAccount,
                'counter_account_id' => $counter,
                'receive_journal_id' => $journal->id,
            ]);

            return $giro;
        });
    }

    /** Kliring — giro cair ke bank. @param array{settled_date:string, cash_account_id:int} $data */
    public function clear(BsGiro $giro, array $data, ?int $userId = null): BsGiro
    {
        return $this->settle($giro, 'cleared', $data['settled_date'], (int) $data['cash_account_id'], $userId);
    }

    /** Tolakan — giro ditolak, piutang dipulihkan. @param array{settled_date:string, bounce_reason?:?string} $data */
    public function bounce(BsGiro $giro, array $data, ?int $userId = null): BsGiro
    {
        // Dipotong di sini juga (bukan hanya di validasi request) agar pemanggil non-web
        // — job / sinkronisasi FO — tidak bisa membuat update gagal karena alasan kepanjangan.
        $reason = $this->cut(trim((string) ($data['bounce_reason'] ?? '')));

        return $this->settle($giro, 'bounced', $data['settled_date'], null, $userId, $reason !== '' ? $reason : null);
    }

    private function settle(BsGiro $giro, string $status, string $date, ?int $cashAccountId, ?int $userId, ?string $reason = null): BsGiro
    {
        return DB::transaction(function () use ($giro, $status, $date, $cashAccountId, $userId, $reason): BsGiro {
            if ($giro->status !== 'in_hand') {
                throw new BusinessRuleException('Cek/Giro sudah diproses.', errorCode: 'GIRO_DONE');
            }
            if (! $giro->receive_journal_id) {
                throw new BusinessRuleException('Posting GL penerimaan dulu (menu Penerimaan Cek Giro) sebelum kliring/tolak.', errorCode: 'GIRO_NOT_POSTED');
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
                // Alasan tolakan ikut ke jurnal agar terbaca dari General Ledger, bukan hanya dari layar giro.
                $lines = [
                    ['account_id' => $giro->counter_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Piutang dipulihkan (giro ditolak)'],
                    ['account_id' => $giro->giro_account_id, 'debit' => 0, 'credit' => $amount, 'description' => $this->cut('Giro ditolak'.($reason !== null ? ': '.$reason : ''))],
                ];
                $desc = $this->cut('Tolakan Cek/Giro '.$giro->giro_number.($reason !== null ? ' — '.$reason : ''));
            }

            $journal = $this->journal->post([
                'journal_date' => $date,
                'source'       => 'cash',
                'status'       => 'posted', // Treasury: kliring/tolakan giro langsung posting → mutasi bank tercatat di Rekening Ledger.
                'reference'    => $giro->giro_number,
                'description'  => $desc,
                'created_by'   => $userId,
            ], $lines);

            $giro->update([
                'status'            => $status,
                'settled_date'      => $date,
                'cash_account_id'   => $cashAccountId,
                'settle_journal_id' => $journal->id,
                'bounce_reason'     => $status === 'bounced' ? $reason : null,
            ]);

            return $giro;
        });
    }

    /** bs_giros.bounce_reason & description jurnal sama-sama VARCHAR(255) — potong agar alasan panjang tidak gagal simpan. */
    private function cut(string $text, int $max = 255): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1).'…';
    }

    private function nextNumber(string $date): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('GIRO', Carbon::parse($date), [], fn () => $this->nextNumberLama($date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date): string
    {
        $d     = Carbon::parse($date);
        $count = BsGiro::withTrashed()->whereYear('receive_date', $d->year)->count() + 1;

        return sprintf('GIRO/%s/%04d', $d->format('y').$d->format('m'), $count);
    }
}
