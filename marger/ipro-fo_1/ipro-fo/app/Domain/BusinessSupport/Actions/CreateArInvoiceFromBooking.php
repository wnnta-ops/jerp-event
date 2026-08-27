<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsArInvoice;
use App\Domain\BusinessSupport\Models\BsBookedRevenue;
use App\Domain\Sales\Booking\Models\Booking;
use Illuminate\Support\Carbon;

/**
 * Bentuk Invoice Piutang otomatis dari satu record Booked Revenue.
 *
 * PENTING — TIDAK memposting jurnal sendiri. Booking sudah memposting jurnal manual lengkap
 * (termasuk baris Debit Piutang Usaha). Kalau invoice memposting jurnal lagi, Piutang tercatat
 * dua kali. Jadi invoice ini murni record penagihan (untuk Aging / Kartu / Kuitansi / Rekonsiliasi),
 * dengan journal_id menunjuk ke jurnal booking sebagai acuan — bukan jurnal baru.
 *
 * Nilai mengikuti Booked Revenue apa adanya (agar "rincian invoice = sudah booked revenue"):
 *   Total = harga − diskon (nett, termasuk PPN) ; DPP = Nilai Revenue ; PPN = Total − DPP.
 * Porsi yang tertutup pencairan KPR (UM Penjualan) dikurangkan terpisah lewat Rekonsiliasi Piutang,
 * bukan di invoice ini — sama seperti tampilan Booked Revenue.
 *
 * Idempoten: satu Booked Revenue → satu invoice. Bila sudah ada, kembalikan yang lama.
 */
final class CreateArInvoiceFromBooking
{
    public function execute(BsBookedRevenue $br, ?int $userId = null): BsArInvoice
    {
        $existing = BsArInvoice::where('booked_revenue_id', $br->id)->first();
        if ($existing) {
            return $existing;
        }

        $br->loadMissing('unit:id,unit_code');

        $total = round((float) $br->gross_price - (float) $br->discount, 2);
        $dpp   = round((float) $br->amount, 2);
        $ppn   = round($total - $dpp, 2);
        $date  = $br->booking_date?->toDateString() ?? Carbon::now()->toDateString();

        return BsArInvoice::create([
            'invoice_number'        => $this->nextNumber($date),
            'invoice_date'          => $date,
            'due_date'              => $date,
            'customer_id'           => $br->customer_id,
            'booking_id'            => $this->resolveBookingId($br),
            'booked_revenue_id'     => $br->id,
            'trx_type'              => 'normal',
            'description'           => trim('Booked Revenue '.$br->br_number.($br->unit?->unit_code ? ' — '.$br->unit->unit_code : '')),
            'income_account_id'     => $br->income_account_id,
            'receivable_account_id' => $br->receivable_account_id,
            'dpp'                   => $dpp,
            'ppn_pct'               => 11,
            'ppn_amount'            => $ppn,
            'ppn_mode'              => $ppn > 0 ? 'with' : 'none',
            'total'                 => $total,
            'net_receivable'        => $total,
            'paid_amount'           => 0,
            'status'                => 'open',
            // Acuan ke jurnal booking; invoice TIDAK memposting jurnal sendiri (hindari piutang ganda).
            'journal_id'            => $br->booking_journal_id,
            'created_by'            => $userId,
        ]);
    }

    /**
     * No. SPR (booking) dari booking TERAKHIR unit MILIK CUSTOMER INI. BsBookedRevenue menyimpan
     * unit_id + customer_id, bukan booking_id. Filter customer_id wajib: satu unit bisa punya
     * booking dari beberapa customer (re-sale / realokasi) — tanpa filter ini invoice customer A
     * bisa tertaut SPR customer B (mis-link yang justru dijaga linkSpr()).
     */
    private function resolveBookingId(BsBookedRevenue $br): ?int
    {
        if (! $br->unit_id) {
            return null;
        }

        return Booking::query()
            ->where('unit_id', $br->unit_id)
            ->where('customer_id', $br->customer_id)
            ->whereNull('deleted_at')
            ->latest('id')
            ->value('id');
    }

    /** Nomor invoice — pola sama dengan penomoran AR lama, segmen kategori 'BR' (dari Booked Revenue). */
    private function nextNumber(string $date): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('AR.INV', Carbon::parse($date), [], fn () => $this->nextNumberLama($date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date): string
    {
        $d     = Carbon::parse($date);
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$d->month - 1];
        $seq   = BsArInvoice::withTrashed()->whereYear('invoice_date', $d->year)->count() + 1;
        do {
            $number = sprintf('AR.INV/BR/MUP/%s/%s/%05d', $d->format('y'), $roman, $seq);
            $seq++;
        } while (BsArInvoice::withTrashed()->where('invoice_number', $number)->exists());

        return $number;
    }
}
