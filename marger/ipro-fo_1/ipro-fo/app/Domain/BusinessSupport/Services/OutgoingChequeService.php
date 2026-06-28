<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsOutgoingCheque;
use App\Exceptions\BusinessRuleException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * #153 — Jurnal otomatis BG/Cek KELUAR (warkat perusahaan ke vendor/supplier).
 * Cermin dari GiroService (cek/giro masuk), arah berlawanan:
 *
 *   Diserahkan (beredar) : Dr Hutang Usaha            / Cr BG-Cek Beredar (liability)
 *   Dicairkan            : Dr BG-Cek Beredar          / Cr Bank
 *   Void (jika beredar)  : Dr BG-Cek Beredar          / Cr Hutang Usaha   (pembalik handover)
 *
 * Konservatif: jurnal HANYA terbentuk bila semua akun bisa diresolusi via
 * config('business_support.gl_accounts'). Bila tidak bisa → siklus tetap jalan,
 * jurnal dilewati (tidak melempar error). Tautan jurnal disimpan agar tidak double-posting.
 */
final class OutgoingChequeService
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * Diserahkan ke vendor → status beredar.
     * Dr Hutang Usaha / Cr BG-Cek Beredar. Mengakui warkat sebagai liabilitas beredar.
     */
    public function handover(BsOutgoingCheque $cheque, string $handoverDate, ?int $userId = null, ?int $debitAccountId = null): BsOutgoingCheque
    {
        return DB::transaction(function () use ($cheque, $handoverDate, $userId, $debitAccountId): BsOutgoingCheque {
            $cheque->update(['handover_date' => $handoverDate, 'status' => 'issued']);

            // Hindari double-posting bila sudah ada jurnal handover.
            if ($cheque->handover_journal_id) {
                return $cheque;
            }

            $liability = $this->resolve('outgoing_cheque_liability');

            // Bila warkat mereferensikan invoice hutang → LUNASI tiap invoice (sinkron Invoice Hutang
            // & Kartu Hutang): Dr Hutang (per invoice) / Cr BG-Cek Beredar (total) + BsApPayment per invoice.
            $lines = $liability
                ? $cheque->lines()->whereNull('payment_id')->with('invoice')->get()
                : new Collection();
            if ($lines->isNotEmpty()) {
                return $this->handoverSettleLines($cheque, $lines, $liability, $handoverDate, $userId);
            }

            // Generic: Dr [akun debit] / Cr BG-Cek Beredar. Debit = akun yang dipilih (mis. Beban Pokok
            // Penjualan untuk pihak ketiga), fallback Hutang Usaha untuk warkat hutang/legacy.
            $amount = $this->amount($cheque);
            $debit  = $debitAccountId ?? $this->resolve('payable');

            if ($amount <= 0 || ! $debit || ! $liability) {
                return $cheque; // tidak bisa diresolusi → lewati jurnal, siklus tetap jalan
            }

            $isPihakKetiga = $cheque->payee_type === 'pihak_ketiga';
            $journal = $this->journal->post([
                'journal_date' => $handoverDate,
                'source'       => 'cash',
                'reference'    => $cheque->cheque_number,
                'description'  => 'Serah '.$this->typeLabel($cheque).' '.$cheque->cheque_number.($cheque->payee ? ' — '.$cheque->payee : ''),
                'created_by'   => $userId,
            ], [
                ['account_id' => $debit, 'debit' => $amount, 'credit' => 0, 'description' => $isPihakKetiga ? 'Pembayaran via '.$this->typeLabel($cheque) : 'Pelunasan hutang via '.$this->typeLabel($cheque)],
                ['account_id' => $liability, 'debit' => 0, 'credit' => $amount, 'description' => $this->typeLabel($cheque).' beredar'],
            ]);

            $cheque->update(['handover_journal_id' => $journal->id]);

            return $cheque;
        });
    }

    /**
     * Pelunasan multi-invoice saat warkat diserahkan: Dr Hutang (per invoice, akun payable invoice) /
     * Cr BG-Cek Beredar (total). Tiap invoice memperoleh BsApPayment + saldo berkurang (status paid/open),
     * sehingga kartu hutang & seluruh modul AP ikut tersinkron. Kas riil keluar saat warkat DICAIRKAN.
     *
     * @param Collection<int,\App\Domain\BusinessSupport\Models\BsOutgoingChequeLine> $lines
     */
    private function handoverSettleLines(BsOutgoingCheque $cheque, Collection $lines, int $liability, string $date, ?int $userId): BsOutgoingCheque
    {
        $jl    = [];
        $rows  = [];
        $total = 0.0;
        foreach ($lines as $line) {
            $inv = $line->invoice;
            if (! $inv) {
                throw new BusinessRuleException('Invoice baris BG/Cek tidak ditemukan.', errorCode: 'CHQ_INV_MISSING');
            }
            $amount = round((float) $line->amount, 2);
            if ($amount <= 0) {
                continue;
            }
            if ($amount > (float) $inv->outstanding + 0.01) {
                throw new BusinessRuleException("Sisa hutang invoice {$inv->invoice_number} tidak cukup (mungkin sudah dibayar).", errorCode: 'CHQ_OVER');
            }
            $jl[]   = ['account_id' => $inv->payable_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Pelunasan '.$inv->invoice_number];
            $rows[] = [$line, $inv, $amount];
            $total += $amount;
        }

        if (! $rows) {
            return $cheque; // tidak ada baris valid → cukup tandai beredar
        }
        $total  = round($total, 2);
        $jl[]   = ['account_id' => $liability, 'debit' => 0, 'credit' => $total, 'description' => $this->typeLabel($cheque).' beredar'];

        $journal = $this->journal->post([
            'journal_date' => $date,
            'source'       => 'ap',
            'reference'    => $cheque->cheque_number,
            'description'  => 'Serah '.$this->typeLabel($cheque).' '.$cheque->cheque_number.' — pelunasan hutang supplier',
            'created_by'   => $userId,
        ], $jl);

        foreach ($rows as [$line, $inv, $amount]) {
            $pay = $inv->payments()->create([
                'payment_date'    => $date,
                'cash_account_id' => $liability, // offset BG-Cek Beredar; kas riil keluar saat dicairkan
                'amount'          => $amount,
                'reference'       => $cheque->cheque_number,
                'journal_id'      => $journal->id,
                'created_by'      => $userId,
            ]);
            $line->update(['payment_id' => $pay->id]);
            $newPaid = round((float) $inv->paid_amount + $amount, 2);
            $inv->update(['paid_amount' => $newPaid, 'status' => $newPaid >= $inv->payable_amount - 0.01 ? 'paid' : 'open']);
        }

        $cheque->update(['handover_journal_id' => $journal->id]);

        return $cheque;
    }

    /**
     * Dicairkan oleh vendor → status cair.
     * Dr BG-Cek Beredar / Cr Bank. Bank: config outgoing_cheque_bank, fallback rekening penerbit warkat.
     */
    public function clear(BsOutgoingCheque $cheque, string $clearedDate, ?int $userId = null): BsOutgoingCheque
    {
        return DB::transaction(function () use ($cheque, $clearedDate, $userId): BsOutgoingCheque {
            $cheque->update([
                'cleared_date'  => $clearedDate,
                'handover_date' => $cheque->handover_date ?? $clearedDate,
                'status'        => 'cleared',
            ]);

            // Hindari double-posting bila sudah ada jurnal pencairan.
            if ($cheque->clear_journal_id) {
                return $cheque;
            }

            $amount    = $this->amount($cheque);
            $liability = $this->resolve('outgoing_cheque_liability');
            $bank      = $this->resolve('outgoing_cheque_bank') ?? $cheque->bank_account_id;

            if ($amount <= 0 || ! $liability || ! $bank) {
                return $cheque; // tidak bisa diresolusi → lewati jurnal
            }

            $journal = $this->journal->post([
                'journal_date' => $clearedDate,
                'source'       => 'cash',
                'reference'    => $cheque->cheque_number,
                'description'  => 'Cair '.$this->typeLabel($cheque).' '.$cheque->cheque_number,
                'created_by'   => $userId,
            ], [
                ['account_id' => $liability, 'debit' => $amount, 'credit' => 0, 'description' => $this->typeLabel($cheque).' dicairkan'],
                ['account_id' => (int) $bank, 'debit' => 0, 'credit' => $amount, 'description' => 'Bank keluar'],
            ]);

            $cheque->update(['clear_journal_id' => $journal->id]);

            return $cheque;
        });
    }

    /**
     * Void (batalkan warkat). Bila warkat sempat beredar & punya jurnal handover yang belum dibalik,
     * post pembalik: Dr BG-Cek Beredar / Cr Hutang Usaha (memulihkan hutang). Bila tidak ada jurnal
     * handover (mis. masih available / akun tak teresolusi) → cukup tandai void tanpa jurnal.
     */
    public function void(BsOutgoingCheque $cheque, ?string $reason = null, ?int $userId = null): BsOutgoingCheque
    {
        return DB::transaction(function () use ($cheque, $reason, $userId): BsOutgoingCheque {
            // Bila warkat sudah melunasi invoice (ada baris terbayar) → UNWIND pelunasan + HAPUS jurnal serah
            // (struktur jurnal dihapus, bukan jurnal balik) agar saldo hutang & seluruh modul AP pulih.
            $settled = $cheque->lines()->whereNotNull('payment_id')->with('invoice')->get();
            if ($settled->isNotEmpty()) {
                return $this->voidSettledLines($cheque, $settled, $reason);
            }

            $needsReversal = $cheque->handover_journal_id && ! $cheque->void_journal_id;

            $cheque->update([
                'status'      => 'void',
                'void_date'   => now()->toDateString(),
                'void_reason' => $reason,
            ]);

            if (! $needsReversal) {
                return $cheque;
            }

            $amount    = $this->amount($cheque);
            $payable   = $this->resolve('payable');
            $liability = $this->resolve('outgoing_cheque_liability');

            if ($amount <= 0 || ! $payable || ! $liability) {
                return $cheque; // tidak bisa diresolusi → lewati pembalik
            }

            $journal = $this->journal->post([
                'journal_date' => now()->toDateString(),
                'source'       => 'cash',
                'reference'    => $cheque->cheque_number,
                'description'  => 'Void '.$this->typeLabel($cheque).' '.$cheque->cheque_number.' (pembalik penyerahan)',
                'created_by'   => $userId,
            ], [
                ['account_id' => $liability, 'debit' => $amount, 'credit' => 0, 'description' => 'Pembatalan '.$this->typeLabel($cheque).' beredar'],
                ['account_id' => $payable, 'debit' => 0, 'credit' => $amount, 'description' => 'Hutang dipulihkan (warkat void)'],
            ]);

            $cheque->update(['void_journal_id' => $journal->id]);

            return $cheque;
        });
    }

    /**
     * Void warkat yang sudah melunasi invoice: pulihkan saldo tiap invoice (paid_amount turun, status open),
     * hapus BsApPayment, lalu HAPUS struktur jurnal serah (bukan jurnal balik). Diblokir bila periode CLOSED.
     *
     * @param Collection<int,\App\Domain\BusinessSupport\Models\BsOutgoingChequeLine> $settled
     */
    private function voidSettledLines(BsOutgoingCheque $cheque, Collection $settled, ?string $reason): BsOutgoingCheque
    {
        // Tolak bila periode jurnal serah sudah CLOSED.
        $journal = $cheque->handover_journal_id ? BsJournal::find($cheque->handover_journal_id) : null;
        if ($journal) {
            $period = BsFiscalPeriod::where('tenant_id', $journal->tenant_id)
                ->where('year', $journal->period_year)->where('month', $journal->period_month)->first();
            if ($period && $period->status === 'closed') {
                throw new BusinessRuleException("Periode {$journal->period_year}/{$journal->period_month} sudah CLOSED — BG/Cek tidak bisa di-void.", errorCode: 'CHQ_PERIOD_CLOSED');
            }
        }

        // Pulihkan saldo invoice & hapus payment.
        foreach ($settled as $line) {
            $inv = $line->invoice;
            if ($inv) {
                $newPaid = round((float) $inv->paid_amount - (float) $line->amount, 2);
                $inv->update(['paid_amount' => max(0, $newPaid), 'status' => 'open']);
                if ($line->payment_id) {
                    $inv->payments()->where('id', $line->payment_id)->delete();
                }
            }
            $line->update(['payment_id' => null]);
        }

        // HAPUS struktur jurnal serah (hilang dari General Ledger) — bukan jurnal balik.
        if ($journal) {
            $journal->lines()->delete();
            $journal->delete();
        }

        $cheque->update([
            'status'              => 'void',
            'void_date'           => now()->toDateString(),
            'void_reason'         => $reason,
            'handover_journal_id' => null,
            'void_journal_id'     => null,
        ]);

        return $cheque;
    }

    private function amount(BsOutgoingCheque $cheque): float
    {
        return round((float) ($cheque->amount ?? 0), 2);
    }

    private function typeLabel(BsOutgoingCheque $cheque): string
    {
        return $cheque->cheque_type === 'bg' ? 'Bilyet Giro' : 'Cek';
    }

    /** Resolusi kode akun GL → id. Mengembalikan null bila key kosong / akun tak ditemukan. */
    private function resolve(string $key): ?int
    {
        $code = config('business_support.gl_accounts.'.$key);
        if (! is_string($code) || trim($code) === '') {
            return null;
        }

        $id = BsAccount::where('code', $code)->value('id');

        return $id ? (int) $id : null;
    }
}
