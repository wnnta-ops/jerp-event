<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsApKuitansi;
use App\Domain\BusinessSupport\Models\BsApKuitansiLine;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Kuitansi Hutang — voucher pembayaran hutang ke supplier (multi-invoice + void).
 *   Bayar : Dr Hutang Supplier (per invoice) / Cr Kas/Bank (total)
 *           Invoice yang menarik Memo Urgent yang SUDAH DIBAYAR wajib memotong uang mukanya:
 *           Dr Hutang / Cr Uang Muka (akun kuitansi memo) / Cr Kas/Bank (sisanya). `total` = kas saja.
 *   Void  : HAPUS jurnal pembayaran asli (bukan jurnal balik) + pulihkan saldo invoice & hapus payment.
 */
final class ApKuitansiService
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{kuitansi_date:string, supplier_id:int, cash_account_id:int, alat_transaksi?:string,
     *              kasir?:?string, manual_no?:?string, description?:?string,
     *              lines:array<int,array{ap_invoice_id:int, amount:float|int|string}>} $data
     */
    /**
     * Registrasi Pengeluaran Kas & Bank (AP) — kini lewat APPROVAL dahulu (#159 parity):
     * hanya VALIDASI + buat kuitansi status 'pending' + baris invoice. Jurnal JAP, payment,
     * & pengurangan saldo invoice TIDAK diposting di sini — terbentuk saat disetujui (approve()).
     */
    public function create(array $data, ?int $userId = null): BsApKuitansi
    {
        return DB::transaction(function () use ($data, $userId): BsApKuitansi {
            $valid = [];
            $total = 0.0;
            $adaUm = false;
            $memoBayar = app(PembayaranMemoUrgent::class);
            foreach ($data['lines'] as $l) {
                $amount = round((float) $l['amount'], 2);
                if ($amount <= 0) {
                    continue;
                }
                $inv = BsApInvoice::findOrFail($l['ap_invoice_id']);
                $inv->assertBolehDibayar();
                if ((int) $inv->supplier_id !== (int) $data['supplier_id']) {
                    throw new BusinessRuleException('Invoice tidak sesuai supplier.', errorCode: 'KUI_SUPPLIER');
                }
                if ($amount > (float) $inv->outstanding + 0.01) {
                    throw new BusinessRuleException("Nominal melebihi sisa hutang invoice {$inv->invoice_number}.", errorCode: 'KUI_OVER');
                }
                // Uang muka Memo Urgent yang sudah dibayar WAJIB dipotong lebih dulu (cegah bayar dobel).
                $um = 0.0;
                $umKui = null;
                if ($ada = $memoBayar->uangMukaInvoice($inv)) {
                    [$umKui, $sisaUm] = $ada;
                    $um = round(min($sisaUm, (float) $inv->outstanding), 2);
                    if ($amount + 0.001 < $um) {
                        throw new BusinessRuleException("Invoice {$inv->invoice_number}: uang muka Memo Urgent Rp ".number_format($um, 0, ',', '.')
                            .' wajib dipotong — nilai pelunasan minimal sebesar itu.', errorCode: 'KUI_UM_WAJIB');
                    }
                    $adaUm = true;
                }
                $valid[] = [(int) $inv->id, $amount, $um, $umKui?->id];
                $total += $amount - $um;
            }
            $total = round($total, 2);
            if ($total <= 0 && ! $adaUm) {
                throw new BusinessRuleException('Tidak ada invoice yang dibayar.', errorCode: 'KUI_EMPTY');
            }

            $kui = BsApKuitansi::create([
                'kuitansi_number' => $this->nextNumber($data['kuitansi_date']),
                'kuitansi_date'   => $data['kuitansi_date'],
                'supplier_id'     => $data['supplier_id'],
                'cash_account_id' => $data['cash_account_id'],
                'alat_transaksi'  => $data['alat_transaksi'] ?? 'transfer',
                'kasir'           => $data['kasir'] ?? null,
                'manual_no'       => $data['manual_no'] ?? null,
                'bg_number'       => $data['bg_number'] ?? null,
                'description'     => $data['description'] ?? null,
                'total'           => $total,
                'status'          => 'pending', // menunggu persetujuan — jurnal terbit saat disetujui
                'created_by'      => $userId,
            ]);
            foreach ($valid as [$invId, $amount, $um, $umKuiId]) {
                $kui->lines()->create(['ap_invoice_id' => $invId, 'amount' => $amount, 'um_amount' => $um, 'um_kuitansi_id' => $umKuiId, 'payment_id' => null]);
            }

            return $kui;
        });
    }

    /**
     * Setujui kuitansi 'pending' → posting jurnal JAP + payment + kurangi saldo invoice; status → 'active'.
     * Sisa hutang di-RE-VALIDASI (bisa berubah sejak registrasi).
     */
    public function approve(BsApKuitansi $kui, int $userId): BsApKuitansi
    {
        return DB::transaction(function () use ($kui, $userId): BsApKuitansi {
            if ($kui->status !== 'pending') {
                throw new BusinessRuleException('Hanya kuitansi berstatus menunggu yang bisa disetujui.', errorCode: 'KUI_NOT_PENDING');
            }
            $kui->load('lines.invoice');

            $jl = [];
            $rows = [];
            foreach ($kui->lines as $line) {
                $inv = $line->invoice;
                if (! $inv) {
                    throw new BusinessRuleException('Invoice baris kuitansi tidak ditemukan.', errorCode: 'KUI_INV_MISSING');
                }
                // Kuitansi bisa dibuat sebelum aturan ini ada — periksa lagi saat disetujui.
                $inv->assertBolehDibayar();
                $amount = round((float) $line->amount, 2);
                if ($amount > (float) $inv->outstanding + 0.01) {
                    throw new BusinessRuleException("Sisa hutang invoice {$inv->invoice_number} tidak cukup (mungkin sudah dibayar) — tolak & registrasi ulang.", errorCode: 'KUI_OVER');
                }
                $jl[]   = ['account_id' => $inv->payable_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Pelunasan '.$inv->invoice_number];
                if ((float) $line->um_amount > 0) {
                    $umKui = BsApKuitansi::find($line->um_kuitansi_id);
                    if (! $umKui || $umKui->status !== 'active'
                        || app(PembayaranMemoUrgent::class)->sisaUangMuka($umKui, (int) $kui->id) + 0.001 < (float) $line->um_amount) {
                        throw new BusinessRuleException("Uang muka Memo Urgent untuk invoice {$inv->invoice_number} sudah tidak tersedia — tolak & registrasi ulang.", errorCode: 'KUI_UM_HILANG');
                    }
                    $jl[] = ['account_id' => $umKui->advance_account_id, 'debit' => 0, 'credit' => (float) $line->um_amount,
                        'description' => 'Potong uang muka '.$umKui->kuitansi_number.' — '.$inv->invoice_number];
                }
                $rows[] = [$line, $inv, $amount];
            }
            if ((float) $kui->total > 0) {
                $jl[] = ['account_id' => $kui->cash_account_id, 'debit' => 0, 'credit' => (float) $kui->total, 'description' => 'Pembayaran hutang supplier'];
            }

            $journal = $this->journal->post([
                'journal_date' => $kui->kuitansi_date->toDateString(),
                'source'       => 'ap',
                'reference'    => 'KUI',
                // Keterangan kuitansi bisa > 255 (gabungan deskripsi banyak invoice); kolom
                // deskripsi jurnal 255 → dipotong. Keterangan lengkap tetap di kuitansinya.
                'description'  => mb_strimwidth('Kuitansi Hutang — '.($kui->description ?? 'pembayaran supplier'), 0, 255, '…'),
                'created_by'   => $userId,
            ], $jl);

            foreach ($rows as [$line, $inv, $amount]) {
                $pay = $inv->payments()->create([
                    'payment_date'    => $kui->kuitansi_date->toDateString(),
                    'cash_account_id' => $kui->cash_account_id,
                    'amount'          => $amount,
                    'reference'       => $kui->kuitansi_number,
                    'journal_id'      => $journal->id,
                    'created_by'      => $userId,
                ]);
                $line->update(['payment_id' => $pay->id]);
                $newPaid = round((float) $inv->paid_amount + $amount, 2);
                $inv->update(['paid_amount' => $newPaid, 'status' => $newPaid >= $inv->payable_amount - 0.01 ? 'paid' : 'open']);
            }

            $kui->update(['status' => 'active', 'journal_id' => $journal->id, 'approved_by' => $userId, 'approved_at' => now()]);

            return $kui;
        });
    }

    /** Tolak kuitansi 'pending' — tidak ada jurnal/payment terbentuk. */
    public function reject(BsApKuitansi $kui, int $userId): BsApKuitansi
    {
        if ($kui->status !== 'pending') {
            throw new BusinessRuleException('Hanya kuitansi berstatus menunggu yang bisa ditolak.', errorCode: 'KUI_NOT_PENDING');
        }
        $kui->update(['status' => 'rejected', 'approved_by' => $userId, 'approved_at' => now()]);

        return $kui;
    }

    /**
     * Void kuitansi = HAPUS jurnal pembayaran ASLI (hilang dari General Ledger), BUKAN posting jurnal balik.
     * Pulihkan saldo invoice & hapus payment. Diblokir bila periode jurnal sudah CLOSED.
     */
    public function void(BsApKuitansi $kui, ?int $userId = null): BsApKuitansi
    {
        return DB::transaction(function () use ($kui): BsApKuitansi {
            if ($kui->status !== 'active') {
                throw new BusinessRuleException('Kuitansi sudah void.', errorCode: 'KUI_VOID');
            }
            // Kuitansi Memo Urgent yang uang mukanya sudah dipotong ke invoice: void kuitansi invoice itu dulu.
            $dipotong = BsApKuitansiLine::query()->where('um_kuitansi_id', $kui->id)->where('um_amount', '>', 0)
                ->whereIn('kuitansi_id', BsApKuitansi::query()->select('id')->whereIn('status', ['pending', 'active']))->exists();
            if ($dipotong) {
                throw new BusinessRuleException('Uang muka memo ini sudah dipotong ke kuitansi invoice — void/tolak kuitansi invoice itu dulu.', errorCode: 'KUI_UM_TERPAKAI');
            }
            $kui->load('lines.invoice');

            // Pulihkan saldo invoice & hapus payment terkait.
            foreach ($kui->lines as $l) {
                $inv = $l->invoice;
                if ($inv) {
                    $newPaid = round((float) $inv->paid_amount - (float) $l->amount, 2);
                    $inv->update(['paid_amount' => max(0, $newPaid), 'status' => 'open']);
                    if ($l->payment_id) {
                        $inv->payments()->where('id', $l->payment_id)->delete();
                    }
                }
            }

            // HAPUS jurnal pembayaran asli (Struktur Jurnal Pembayaran) — bukan jurnal balik.
            if ($kui->journal_id) {
                $journal = BsJournal::find($kui->journal_id);
                if ($journal) {
                    $period = BsFiscalPeriod::where('tenant_id', $journal->tenant_id)
                        ->where('year', $journal->period_year)->where('month', $journal->period_month)->first();
                    if ($period && $period->status === 'closed') {
                        throw new BusinessRuleException("Periode {$journal->period_year}/{$journal->period_month} sudah CLOSED — kuitansi tidak bisa di-void.", errorCode: 'KUI_PERIOD_CLOSED');
                    }
                    $journal->lines()->delete();
                    $journal->delete();
                }
            }

            $kui->update(['status' => 'void', 'void_journal_id' => null, 'voided_at' => now()->toDateString()]);

            return $kui;
        });
    }

    /** AP.KUI/MUP/yy/[bulan-romawi]/00001 (juga dipakai kuitansi Memo Urgent). */
    public function nextNumber(string $date): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('AP.KUI', \Illuminate\Support\Carbon::parse($date), [], fn () => $this->nextNumberLama($date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date): string
    {
        $d = Carbon::parse($date);
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$d->month - 1];
        $seq = BsApKuitansi::withTrashed()->whereYear('kuitansi_date', $d->year)->count() + 1;
        do {
            $number = sprintf('AP.KUI/MUP/%s/%s/%05d', $d->format('y'), $roman, $seq);
            $seq++;
        } while (BsApKuitansi::withTrashed()->where('kuitansi_number', $number)->exists());

        return $number;
    }
}
