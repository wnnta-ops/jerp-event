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
 *
 * #8 — Warkat PELUNASAN HUTANG SUPPLIER (ada baris invoice):
 *   Diserahkan (beredar) : TANPA jurnal — hutang supplier TETAP TERBUKA (masih di daftar hutang).
 *   Dicairkan            : buat KUITANSI AP status 'pending' (tanggal = TANGGAL PENCAIRAN, alat Cek/Giro)
 *                          → masuk antrian APPROVAL PENGELUARAN KAS & BANK. Saat disetujui:
 *                          jurnal JAP (Dr Hutang / Cr Bank) + payment dgn tanggal bayar = tanggal
 *                          pencairan + invoice lunas.
 *   Void (belum cair)    : cukup tandai void (tidak ada yang perlu dibalik).
 *
 * Warkat PIHAK KETIGA / non-AP (tanpa baris invoice) — tetap dua langkah:
 *   Diserahkan (beredar) : Dr [akun debit] / Cr BG-Cek Beredar (liability)
 *   Dicairkan            : Dr BG-Cek Beredar / Cr Bank
 *   Void (jika beredar)  : Dr BG-Cek Beredar / Cr Hutang Usaha (pembalik handover)
 *
 * Jurnal treasury non-AP diposting langsung (status 'posted') agar mutasi bank langsung
 * tampil di Rekening Ledger. Konservatif: jurnal HANYA terbentuk bila akun bisa diresolusi via
 * config('business_support.gl_accounts'). Bila tidak → siklus tetap jalan, jurnal dilewati.
 */
final class OutgoingChequeService
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly ApKuitansiService $kuitansi,
    ) {}

    /**
     * Diserahkan ke vendor → status beredar.
     *
     * #8: Warkat pelunasan hutang supplier (ada baris invoice) → TIDAK melunasi hutang saat beredar
     * (tidak ada jurnal, tidak ada pembayaran) — supplier tetap tampil di daftar hutang sampai warkat
     * DICAIRKAN. Warkat pihak ketiga / non-AP → Dr [akun debit] / Cr BG-Cek Beredar.
     */
    public function handover(BsOutgoingCheque $cheque, string $handoverDate, ?int $userId = null, ?int $debitAccountId = null): BsOutgoingCheque
    {
        return DB::transaction(function () use ($cheque, $handoverDate, $userId, $debitAccountId): BsOutgoingCheque {
            $cheque->update(['handover_date' => $handoverDate, 'status' => 'issued']);

            // Hindari double-posting bila sudah ada jurnal handover.
            if ($cheque->handover_journal_id) {
                return $cheque;
            }

            // #8: warkat pelunasan hutang supplier → beredar TANPA jurnal; hutang lunas saat dicairkan.
            $hasInvoiceLines = $cheque->lines()->whereNull('payment_id')->whereNotNull('ap_invoice_id')->exists();
            if ($hasInvoiceLines) {
                return $cheque;
            }

            // Generic (pihak ketiga / non-AP): Dr [akun debit] / Cr BG-Cek Beredar. Debit = akun yang dipilih
            // (mis. Beban Pokok Penjualan untuk pihak ketiga), fallback Hutang Usaha untuk warkat legacy.
            $liability = $this->resolve('outgoing_cheque_liability');
            $amount = $this->amount($cheque);
            $debit  = $debitAccountId ?? $this->resolve('payable');

            if ($amount <= 0 || ! $debit || ! $liability) {
                return $cheque; // tidak bisa diresolusi → lewati jurnal, siklus tetap jalan
            }

            $isPihakKetiga = $cheque->payee_type === 'pihak_ketiga';
            $journal = $this->journal->post([
                'journal_date' => $handoverDate,
                'source'       => 'cash',
                'status'       => 'posted', // Treasury: langsung posting.
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
     * #8: Warkat pelunasan hutang DICAIRKAN → buat KUITANSI AP status 'pending' (tanggal = tanggal
     * pencairan, alat Cek/Giro, nomor BG tercantum). Kuitansi masuk antrian APPROVAL PENGELUARAN
     * KAS & BANK; jurnal JAP + payment + pelunasan invoice terbentuk saat kuitansi DISETUJUI —
     * dengan tanggal bayar = tanggal pencairan warkat.
     *
     * @param Collection<int,\App\Domain\BusinessSupport\Models\BsOutgoingChequeLine> $lines
     */
    private function clearCreateKuitansi(BsOutgoingCheque $cheque, Collection $lines, int $bank, string $date, ?int $userId): BsOutgoingCheque
    {
        $rows = $lines
            ->filter(fn ($l) => $l->invoice && round((float) $l->amount, 2) > 0)
            ->map(fn ($l) => ['ap_invoice_id' => (int) $l->ap_invoice_id, 'amount' => round((float) $l->amount, 2)])
            ->values();
        if ($rows->isEmpty()) {
            return $cheque; // tidak ada baris valid → cukup tandai cair
        }

        $supplierId = $cheque->supplier_id ?: $lines->first()->invoice?->supplier_id;
        if (! $supplierId) {
            throw new BusinessRuleException('Supplier warkat tidak ditemukan — tidak bisa membuat kuitansi pembayaran.', errorCode: 'CHQ_NO_SUPPLIER');
        }

        // Validasi sisa hutang & pembuatan kuitansi dilakukan ApKuitansiService::create (throw bila tak cukup).
        $kui = $this->kuitansi->create([
            'kuitansi_date'   => $date, // tanggal pencairan = tanggal bayar AP
            'supplier_id'     => (int) $supplierId,
            'cash_account_id' => $bank,
            'alat_transaksi'  => 'giro',
            'bg_number'       => $cheque->cheque_number,
            'description'     => 'Pencairan '.$this->typeLabel($cheque).' '.$cheque->cheque_number.' — pelunasan hutang supplier',
            'lines'           => $rows->all(),
        ], $userId);

        $cheque->update(['clear_kuitansi_id' => $kui->id]);

        return $cheque;
    }

    /**
     * Dicairkan oleh vendor → status cair.
     *
     * #8: Warkat pelunasan hutang yang belum dilunasi → buat Kuitansi AP 'pending' (tanggal = tanggal
     * pencairan) → antrian Approval Pengeluaran Kas & Bank. Warkat legacy (sudah masuk BG-Cek Beredar
     * saat handover) / pihak ketiga → Dr BG-Cek Beredar / Cr Bank. Bank: config outgoing_cheque_bank,
     * fallback rekening penerbit warkat.
     */
    public function clear(BsOutgoingCheque $cheque, string $clearedDate, ?int $userId = null): BsOutgoingCheque
    {
        return DB::transaction(function () use ($cheque, $clearedDate, $userId): BsOutgoingCheque {
            $cheque->update([
                'cleared_date'  => $clearedDate,
                'handover_date' => $cheque->handover_date ?? $clearedDate,
                'status'        => 'cleared',
            ]);

            // Hindari double-posting bila sudah ada jurnal/kuitansi pencairan.
            if ($cheque->clear_journal_id || $cheque->clear_kuitansi_id) {
                return $cheque;
            }

            $bank = $this->resolve('outgoing_cheque_bank') ?? $cheque->bank_account_id;
            if (! $bank) {
                return $cheque; // tidak bisa diresolusi → lewati jurnal
            }

            // #8: warkat pelunasan hutang yang belum dilunasi (model baru) → kuitansi AP pending (approval).
            $unpaid = $cheque->lines()->whereNull('payment_id')->whereNotNull('ap_invoice_id')->with('invoice')->get();
            if ($unpaid->isNotEmpty()) {
                return $this->clearCreateKuitansi($cheque, $unpaid, (int) $bank, $clearedDate, $userId);
            }

            // Legacy (hutang sudah dilunasi ke BG-Cek Beredar saat handover) / pihak ketiga: Dr BG-Cek Beredar / Cr Bank.
            $amount    = $this->amount($cheque);
            $liability = $this->resolve('outgoing_cheque_liability');

            if ($amount <= 0 || ! $liability) {
                return $cheque; // tidak bisa diresolusi → lewati jurnal
            }

            $journal = $this->journal->post([
                'journal_date' => $clearedDate,
                'source'       => 'cash',
                'status'       => 'posted', // Treasury: mutasi bank langsung tampil di Rekening Ledger.
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
     * handover (mis. warkat pelunasan hutang yang belum cair, masih available, atau akun tak teresolusi)
     * → cukup tandai void tanpa jurnal.
     */
    public function void(BsOutgoingCheque $cheque, ?string $reason = null, ?int $userId = null): BsOutgoingCheque
    {
        return DB::transaction(function () use ($cheque, $reason, $userId): BsOutgoingCheque {
            // Bila warkat sudah melunasi invoice (ada baris terbayar — legacy handover-settle) → UNWIND pelunasan
            // + HAPUS jurnal serah agar saldo hutang & seluruh modul AP pulih.
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
                'status'       => 'posted', // Treasury: pembalik langsung posting.
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
     * Void warkat yang sudah melunasi invoice (legacy handover-settle): pulihkan saldo tiap invoice
     * (paid_amount turun, status open), hapus BsApPayment, lalu HAPUS struktur jurnal serah. Diblokir bila
     * periode CLOSED.
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
