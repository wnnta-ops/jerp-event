<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsArCategory;
use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registrasi Invoice Piutang (AR) faithful eProperty.
 *   Dr Piutang Usaha (net = DPP+PPN−PPh) + Dr PPh Dibayar Dimuka (bila PPh dipungut pelanggan)
 *   / Cr Pendapatan (DPP, dari Kategori) + Cr PPN Keluaran (bila Dengan PPN)
 */
final class RegisterArInvoice
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{
     *   customer_id:int, category_id?:?int, income_account_id?:?int, receivable_account_id?:?int,
     *   trx_type?:string, customer_ref_no?:?string, manual_no?:?string,
     *   invoice_date:string, receive_date?:?string, due_date?:?string, term_days?:?int,
     *   description:string, transfer_detail?:?string, kurs?:float|int,
     *   dpp:float|int|string, ppn_mode?:string, ppn_pct?:float|int,
     *   pph_mode?:string, pph_pct?:float|int, pph_account_id?:?int,
     *   lines?:array<int,array{description:string,qty?:float,unit_price?:float,amount:float}>,
     *   schedules?:array<int,array{due_date:string,amount:float}>
     * } $data
     */
    public function execute(array $data, ?int $userId = null): BsArInvoice
    {
        return DB::transaction(function () use ($data, $userId): BsArInvoice {
            // Akun dari Kategori Invoice bila ada.
            $category   = ! empty($data['category_id']) ? BsArCategory::find($data['category_id']) : null;
            $incomeAcc  = $category?->income_account_id ?? ($data['income_account_id'] ?? BsAccount::where('code', config('business_support.gl_accounts.income'))->value('id'));
            $receivable = $category?->receivable_account_id ?? ($data['receivable_account_id'] ?? BsAccount::where('code', config('business_support.gl_accounts.receivable'))->value('id'));
            if (! $incomeAcc || ! $receivable) {
                throw new BusinessRuleException('Akun pendapatan / piutang belum ditentukan (pilih Kategori Invoice).', errorCode: 'AR_NO_ACCOUNT');
            }

            $dpp    = round((float) $data['dpp'], 2);
            $ppnPct = ($data['ppn_mode'] ?? 'none') === 'with' ? round((float) ($data['ppn_pct'] ?? 11), 2) : 0.0;
            $ppn    = round($dpp * $ppnPct / 100, 2);
            $total  = round($dpp + $ppn, 2);
            $pphPct = ($data['pph_mode'] ?? 'none') === 'withheld' ? round((float) ($data['pph_pct'] ?? 0), 2) : 0.0;
            $pph    = round($dpp * $pphPct / 100, 2);

            // Akun PPh dibayar dimuka (kredit pajak) — default PPh 23.
            $pphAcc = $pph > 0
                ? ($data['pph_account_id'] ?? BsAccount::where('code', 'like', '1010502%')->where('is_postable', true)->orderBy('code')->value('id'))
                : null;
            $bookPph = $pph > 0 && $pphAcc;
            $net     = $bookPph ? round($total - $pph, 2) : $total;

            // Jatuh tempo: eksplisit atau dari term (hari).
            $invDate = Carbon::parse($data['invoice_date']);
            $dueDate = ! empty($data['due_date'])
                ? $data['due_date']
                : (! empty($data['term_days']) ? $invDate->copy()->addDays((int) $data['term_days'])->toDateString() : $invDate->toDateString());

            $inv = BsArInvoice::create([
                'invoice_number'        => $this->nextNumber($data['invoice_date'], $category?->code),
                'customer_id'           => $data['customer_id'],
                'category_id'           => $category?->id,
                'customer_ref_no'       => $data['customer_ref_no'] ?? null,
                'manual_no'             => $data['manual_no'] ?? null,
                'trx_type'              => $data['trx_type'] ?? 'normal',
                'invoice_date'          => $data['invoice_date'],
                'receive_date'          => $data['receive_date'] ?? null,
                'due_date'              => $dueDate,
                'term_days'             => $data['term_days'] ?? null,
                'income_account_id'     => $incomeAcc,
                'receivable_account_id' => $receivable,
                'description'           => $data['description'],
                'transfer_detail'       => $data['transfer_detail'] ?? null,
                'kurs'                  => $data['kurs'] ?? 1,
                'dpp'                   => $dpp,
                'ppn_pct'               => $ppnPct,
                'ppn_amount'            => $ppn,
                'ppn_mode'              => $data['ppn_mode'] ?? 'none',
                'pph_mode'              => $data['pph_mode'] ?? 'none',
                'pph_pct'               => $pphPct,
                'pph_amount'            => $pph,
                'pph_account_id'        => $pphAcc,
                'total'                 => $total,
                'net_receivable'        => $net,
                'paid_amount'           => 0,
                'status'                => 'open',
                'created_by'            => $userId,
            ]);

            // Detil Tagihan.
            foreach ($data['lines'] ?? [] as $l) {
                $amount = round((float) $l['amount'], 2);
                if ($amount <= 0) {
                    continue;
                }
                $inv->lines()->create([
                    'description' => $l['description'] ?? $inv->description,
                    'qty'         => $l['qty'] ?? 1,
                    'unit_price'  => $l['unit_price'] ?? $amount,
                    'amount'      => $amount,
                ]);
            }

            // Struktur / jadwal penerimaan (default 1 termin = net pada jatuh tempo).
            $schedules = $data['schedules'] ?? [];
            if (empty($schedules)) {
                $schedules = [['due_date' => $dueDate, 'amount' => $net]];
            }
            $i = 1;
            foreach ($schedules as $s) {
                $inv->schedules()->create([
                    'installment_no' => $i++,
                    'due_date'       => $s['due_date'],
                    'amount'         => round((float) $s['amount'], 2),
                    'status'         => 'unpaid',
                ]);
            }

            // Jurnal — sisi debit: Piutang (net) + PPh dibayar dimuka; sisi kredit: Pendapatan (DPP) + PPN Keluaran.
            $jl   = [['account_id' => $receivable, 'debit' => $net, 'credit' => 0, 'description' => 'Piutang '.($inv->customer?->full_name ?? 'customer')]];
            if ($bookPph) {
                $jl[] = ['account_id' => $pphAcc, 'debit' => $pph, 'credit' => 0, 'description' => 'PPh dipungut pelanggan'];
            }
            $ppnAcc = $ppn > 0 ? BsAccount::where('code', config('business_support.gl_accounts.ppn_out'))->value('id') : null;
            if ($ppn > 0 && $ppnAcc) {
                $jl[] = ['account_id' => $incomeAcc, 'debit' => 0, 'credit' => $dpp, 'description' => $inv->description];
                $jl[] = ['account_id' => $ppnAcc, 'debit' => 0, 'credit' => $ppn, 'description' => 'PPN Keluaran'];
            } else {
                // Tanpa akun PPN → gabung ke pendapatan agar balance.
                $jl[] = ['account_id' => $incomeAcc, 'debit' => 0, 'credit' => ($ppn > 0 ? $total : $dpp), 'description' => $inv->description];
            }

            $journal = $this->journal->post([
                'journal_date' => $inv->invoice_date->toDateString(),
                'source'       => 'ar',
                'reference'    => $inv->invoice_number,
                'description'  => 'AR — '.$inv->description.' ('.($inv->customer?->full_name ?? '').')',
                'created_by'   => $userId,
            ], $jl);

            $inv->update(['journal_id' => $journal->id]);

            return $inv;
        });
    }

    /** Format eProperty: AR.INV/{KATEGORI}/MUP/{yy}/{bulan-romawi}/{seq}. */
    private function nextNumber(string $date, ?string $catCode = null): string
    {
        $d     = Carbon::parse($date);
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$d->month - 1];
        $cat   = $catCode ?: 'UMUM';
        $seq   = BsArInvoice::withTrashed()->whereYear('invoice_date', $d->year)->count() + 1;
        do {
            $number = sprintf('AR.INV/%s/MUP/%s/%s/%05d', $cat, $d->format('y'), $roman, $seq);
            $seq++;
        } while (BsArInvoice::withTrashed()->where('invoice_number', $number)->exists());

        return $number;
    }
}
