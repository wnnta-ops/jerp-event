<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsCashTransaction;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cash Book → jurnal GL otomatis.
 *
 * Sisi kas selalu 1 rekening; sisi lawan boleh dipecah ke >1 akun (#15 split transaction).
 *   Kas Masuk (in)      : Dr Kas/Bank (total)        / Cr tiap akun lawan
 *   Kas Keluar (out)    : Dr tiap akun lawan         / Cr Kas/Bank (total)
 *   Transfer (transfer) : Dr tiap rekening tujuan    / Cr Kas/Bank sumber (total)
 *
 * Nominal header = jumlah seluruh baris. counter_account_id header = akun baris pertama (kompat lama).
 */
final class RecordCashTransaction
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{trx_type:string, trx_date:string, cash_account_id:int,
     *              lines?:array<int,array{account_id:int,amount:float|int|string,description?:?string}>,
     *              counter_account_id?:int, amount?:float|int|string,
     *              payee?:?string, description:string, reference?:?string, auto_post?:bool} $data
     */
    public function execute(array $data, ?int $userId = null): BsCashTransaction
    {
        return DB::transaction(function () use ($data, $userId): BsCashTransaction {
            $type  = $data['trx_type'];
            $lines = $this->normalizeLines($data);

            $total = round(array_sum(array_column($lines, 'amount')), 2);

            $trx = BsCashTransaction::create([
                'trx_number'         => $this->nextNumber($type, $data['trx_date']),
                'trx_type'           => $type,
                'trx_date'           => $data['trx_date'],
                'cash_account_id'    => $data['cash_account_id'],
                'counter_account_id' => $lines[0]['account_id'],   // kompat: akun lawan utama
                'amount'             => $total,
                'payee'              => $data['payee'] ?? null,
                'description'        => $data['description'],
                'reference'          => $data['reference'] ?? null,
                // CB-3: hanya BKK (Pengeluaran Kas & Bank) butuh approval. Kas Kecil keluar pakai auto_post → langsung berjurnal.
                'approval_status'    => ($type === 'out' && empty($data['auto_post'])) ? 'pending' : 'approved',
                'created_by'         => $userId,
            ]);

            $this->syncLines($trx, $lines);

            // #159: Pengeluaran (out) jurnalnya DITUNDA sampai disetujui (approval) — KECUALI auto_post (Kas Kecil).
            // 'in' & 'transfer' selalu langsung berjurnal saat simpan.
            if ($type !== 'out' || ! empty($data['auto_post'])) {
                $journal = $this->journal->post(
                    $this->journalHeader($trx, $type, $userId),
                    $this->journalLines($trx, $lines, $type, $total),
                );

                $trx->update(['journal_id' => $journal->id]);
            }

            return $trx->load('lines');
        });
    }

    /**
     * Edit transaksi kas (#16): perbarui field + rincian baris, ganti jurnal lama dengan baru.
     * Diblokir bila periode jurnal lama sudah closed.
     */
    public function update(BsCashTransaction $trx, array $data, ?int $userId = null): BsCashTransaction
    {
        return DB::transaction(function () use ($trx, $data, $userId): BsCashTransaction {
            $type  = $data['trx_type'];
            $lines = $this->normalizeLines($data);
            $total = round(array_sum(array_column($lines, 'amount')), 2);

            // Hapus jurnal lama (blokir bila periodenya sudah closed).
            if ($trx->journal_id) {
                $old = BsJournal::find($trx->journal_id);
                if ($old) {
                    $period = BsFiscalPeriod::where('tenant_id', $old->tenant_id)
                        ->where('year', $old->period_year)->where('month', $old->period_month)->first();
                    if ($period && $period->status === 'closed') {
                        throw new BusinessRuleException("Periode {$old->period_year}/{$old->period_month} sudah CLOSED — transaksi tidak bisa diedit.", errorCode: 'PERIOD_CLOSED');
                    }
                    $old->lines()->delete();
                    $old->delete();
                }
            }

            $trx->update([
                'trx_type'           => $type,
                'trx_date'           => $data['trx_date'],
                'cash_account_id'    => $data['cash_account_id'],
                'counter_account_id' => $lines[0]['account_id'],
                'amount'             => $total,
                'payee'              => $data['payee'] ?? null,
                'description'        => $data['description'],
                'reference'          => $data['reference'] ?? null,
            ]);

            $this->syncLines($trx, $lines);

            // #159: Pengeluaran (out) jurnalnya DITUNDA sampai disetujui ulang.
            // Jurnal lama (bila ada) sudah dihapus di atas; biarkan journal_id null.
            if ($type !== 'out') {
                $journal = $this->journal->post(
                    $this->journalHeader($trx, $type, $userId, edit: true),
                    $this->journalLines($trx, $lines, $type, $total),
                );

                $trx->update(['journal_id' => $journal->id]);
            } else {
                $trx->update(['journal_id' => null]);
            }

            return $trx->load('lines');
        });
    }

    /**
     * Posting jurnal GL untuk transaksi (#159): dipanggil saat approval Pengeluaran (out).
     * Idempoten: bila sudah ada journal_id, tidak posting ulang.
     */
    public function postJournal(BsCashTransaction $trx, ?int $userId = null): BsCashTransaction
    {
        return DB::transaction(function () use ($trx, $userId): BsCashTransaction {
            if ($trx->journal_id) {
                return $trx; // sudah berjurnal
            }

            $type  = $trx->trx_type;
            $lines = $trx->lines()->get()->map(fn ($l) => [
                'account_id'  => (int) $l->account_id,
                'amount'      => round((float) $l->amount, 2),
                'description' => $l->description,
            ])->all();
            $total = round((float) array_sum(array_column($lines, 'amount')), 2);

            $journal = $this->journal->post(
                $this->journalHeader($trx, $type, $userId),
                $this->journalLines($trx, $lines, $type, $total),
            );

            $trx->update(['journal_id' => $journal->id]);

            return $trx;
        });
    }

    /**
     * Validasi + bentuk rincian baris akun lawan.
     * Menerima format multi-baris (lines[]) maupun lama (counter_account_id + amount).
     *
     * @return array<int,array{account_id:int,amount:float,description:?string}>
     */
    private function normalizeLines(array $data): array
    {
        $type    = $data['trx_type'];
        $cashId  = (int) $data['cash_account_id'];

        if (! array_key_exists($type, BsCashTransaction::TYPES)) {
            throw new BusinessRuleException('Tipe transaksi kas tidak valid.', errorCode: 'CASH_TYPE_INVALID');
        }

        // Kumpulkan baris mentah.
        $raw = $data['lines'] ?? null;
        if (! is_array($raw) || $raw === []) {
            // Kompat lama: 1 baris dari counter_account_id + amount.
            $raw = [['account_id' => $data['counter_account_id'] ?? null, 'amount' => $data['amount'] ?? 0, 'description' => null]];
        }

        $lines = [];
        foreach ($raw as $r) {
            $accId  = (int) ($r['account_id'] ?? 0);
            $amount = round((float) ($r['amount'] ?? 0), 2);
            if ($accId <= 0 || $amount <= 0) {
                continue; // lewati baris kosong
            }
            if ($accId === $cashId) {
                throw new BusinessRuleException('Akun lawan tidak boleh sama dengan rekening kas/bank.', errorCode: 'CASH_SAME_ACCOUNT');
            }
            $lines[] = [
                'account_id'  => $accId,
                'amount'      => $amount,
                'description' => isset($r['description']) ? (is_string($r['description']) ? trim($r['description']) : null) : null,
            ];
        }

        if ($lines === []) {
            throw new BusinessRuleException('Minimal 1 baris akun lawan dengan nominal > 0.', errorCode: 'CASH_NO_LINES');
        }

        return array_values($lines);
    }

    /** Simpan ulang rincian baris (hapus lama, buat baru). */
    private function syncLines(BsCashTransaction $trx, array $lines): void
    {
        $trx->lines()->delete();
        foreach ($lines as $l) {
            $trx->lines()->create([
                'account_id'  => $l['account_id'],
                'amount'      => $l['amount'],
                'description' => $l['description'] ?: null,
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function journalHeader(BsCashTransaction $trx, string $type, ?int $userId, bool $edit = false): array
    {
        return [
            'journal_date' => $trx->trx_date->toDateString(),
            'source'       => 'cash',
            'reference'    => $trx->trx_number,
            'description'  => BsCashTransaction::TYPES[$type].' — '.$trx->description.($edit ? ' (edit)' : ''),
            'created_by'   => $userId,
        ];
    }

    /**
     * Bentuk baris jurnal: 1 baris kas (total) + N baris akun lawan.
     *
     * @return array<int,array<string,mixed>>
     */
    private function journalLines(BsCashTransaction $trx, array $lines, string $type, float $total): array
    {
        $cashIsDebit = $type === 'in';   // masuk → kas didebit; keluar/transfer → kas dikredit (sumber).

        $rows = [[
            'account_id'  => $trx->cash_account_id,
            'debit'       => $cashIsDebit ? $total : 0,
            'credit'      => $cashIsDebit ? 0 : $total,
            'description' => $trx->description,
        ]];

        foreach ($lines as $l) {
            $rows[] = [
                'account_id'  => $l['account_id'],
                'debit'       => $cashIsDebit ? 0 : $l['amount'],
                'credit'      => $cashIsDebit ? $l['amount'] : 0,
                'description' => $l['description'] ?: ($trx->payee ?: $trx->description),
            ];
        }

        return $rows;
    }

    private function nextNumber(string $type, string $date): string
    {
        // Cabang default 'CB' tidak punya baris master — untuk itu tetap cara lama.
        $code = match ($type) { 'in' => 'BKM', 'out' => 'BKK', 'transfer' => 'TRF', default => null };

        if ($code === null) {
            return $this->nextNumberLama($type, $date);
        }

        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next($code, Carbon::parse($date), [], fn () => $this->nextNumberLama($type, $date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $type, string $date): string
    {
        $prefix = match ($type) { 'in' => 'BKM', 'out' => 'BKK', 'transfer' => 'TRF', default => 'CB' };
        $d      = Carbon::parse($date);
        $count  = BsCashTransaction::withTrashed()->where('trx_type', $type)->whereYear('trx_date', $d->year)->count() + 1;

        return sprintf('%s/%s/%04d', $prefix, $d->format('y').$d->format('m'), $count);
    }
}
