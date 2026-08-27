<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Actions\CreateArInvoiceFromBooking;
use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBookedRevenue;
use App\Domain\Master\Unit\Models\Unit;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Booked Revenue — pendapatan ter-booking per customer & unit.
 *
 * Booking memposting SATU jurnal manual lengkap dan, sekaligus (atomik), membentuk Invoice
 * Piutang otomatis per unit (lihat CreateArInvoiceFromBooking) — invoice tidak dibuat manual
 * lagi. Invoice tidak memposting jurnal sendiri (jurnal booking sudah mencatat Piutang).
 */
final class BookedRevenueService
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly CreateArInvoiceFromBooking $arInvoice,
    ) {}

    /**
     * Buat 1 record Booked Revenue (TANPA posting jurnal).
     * Nilai Revenue (DPP) dihitung otoritatif: (Harga − Diskon/Free DP) ÷ 1,11.
     *
     * @param array{booking_date:string, customer_id:int, unit_id:int, gross_price?:float|int|string, discount?:float|int|string, cost_center_id?:?int, reference?:?string, description?:?string} $data
     */
    private function makeRecord(array $data, ?int $userId): BsBookedRevenue
    {
        $unit = Unit::findOrFail($data['unit_id']);

        // Harga (gross, termasuk PPN): pakai input bila ada, jika tidak ambil dari unit.
        $gross    = round((float) ($data['gross_price'] ?? ($unit->current_price ?: $unit->base_price)), 2);
        $discount = round((float) ($data['discount'] ?? 0), 2);
        if ($discount < 0) {
            throw new BusinessRuleException('Diskon/Free DP tidak boleh negatif.', errorCode: 'BR_DISCOUNT_NEG');
        }
        if ($discount > $gross) {
            throw new BusinessRuleException('Diskon/Free DP tidak boleh melebihi harga unit.', errorCode: 'BR_DISCOUNT_OVER');
        }

        // Nilai Revenue (DPP) = (Harga − Diskon/Free DP) ÷ 1,11, DIBULATKAN ke rupiah penuh.
        // Nilai penjualan tidak dicatat dalam sen; pembulatan di sini disamakan dengan layar
        // (Form.vue revenueOf) supaya angka yang dilihat operator = angka yang tersimpan.
        $amount = round(($gross - $discount) / 1.11);
        if ($amount <= 0) {
            throw new BusinessRuleException('Nilai revenue harus lebih dari 0 (cek harga & diskon).', errorCode: 'BR_ZERO');
        }

        return BsBookedRevenue::create([
            'br_number'             => $this->nextNumber($data['booking_date']),
            'booking_date'          => $data['booking_date'],
            'customer_id'           => $data['customer_id'],
            'unit_id'               => $unit->id,
            'project_id'            => $unit->project_id,
            'gross_price'           => $gross,
            'discount'              => $discount,
            'amount'                => $amount,
            'receivable_account_id' => $this->account('receivable'),
            'deferred_account_id'   => $this->account('unearned_revenue'),
            'income_account_id'     => $this->account('income'),
            'cost_center_id'        => $data['cost_center_id'] ?? null,
            'reference'             => $data['reference'] ?? null,
            'description'           => $data['description'] ?? null,
            'status'                => 'booked',
            'created_by'            => $userId,
        ]);
    }

    /**
     * Booking 1 unit dengan jurnal OTOMATIS (Dr Piutang / Cr Pendapatan Diterima Dimuka).
     * Dipakai bila jurnal tidak diisi manual.
     */
    public function book(array $data, ?int $userId = null): BsBookedRevenue
    {
        return DB::transaction(function () use ($data, $userId): BsBookedRevenue {
            $br      = $this->makeRecord($data, $userId);
            $amount  = (float) $br->amount;
            $journal = $this->journal->post([
                'journal_date'   => $br->booking_date->toDateString(),
                'source'         => 'memorial',
                'reference'      => $br->br_number,
                'description'    => 'Booked Revenue — '.$this->label($br),
                'cost_center_id' => $br->cost_center_id,
                'created_by'     => $userId,
            ], [
                ['account_id' => $br->receivable_account_id, 'debit' => $amount, 'credit' => 0, 'description' => 'Piutang booked revenue', 'cost_center_id' => $br->cost_center_id],
                ['account_id' => $br->deferred_account_id, 'debit' => 0, 'credit' => $amount, 'description' => 'Pendapatan diterima dimuka', 'cost_center_id' => $br->cost_center_id],
            ]);
            $br->update(['booking_journal_id' => $journal->id]);

            return $br;
        });
    }

    /**
     * Booking massal: 1 customer, banyak unit, dengan SATU jurnal MANUAL untuk seluruh transaksi.
     * Atomik. Tiap unit → 1 record; semua record terhubung ke jurnal manual yang sama.
     * Baris jurnal divalidasi oleh JournalService (balance Dr=Cr, ≥2 baris, periode terbuka).
     *
     * @param array{booking_date:string, customer_id:int, cost_center_id?:?int, reference?:?string, description?:?string} $header
     * @param array<int,array{unit_id:int|string, discount?:float|int|string}> $units
     * @param array<int,array{account_id:int|string, debit:float|int|string, credit:float|int|string}> $journalLines
     * @return array<int,BsBookedRevenue>
     */
    public function bookMany(array $header, array $units, array $journalLines, ?int $userId = null): array
    {
        // Dedup unit (jaga-jaga bila satu unit ter-checklist ganda).
        $byUnit = [];
        foreach ($units as $u) {
            $byUnit[(int) $u['unit_id']] = $u;
        }
        if ($byUnit === []) {
            throw new BusinessRuleException('Pilih minimal 1 unit untuk di-booking.', errorCode: 'BR_NO_UNIT');
        }

        return DB::transaction(function () use ($header, $byUnit, $journalLines, $userId): array {
            $cc      = $header['cost_center_id'] ?? null;
            $records = [];
            foreach ($byUnit as $unitId => $u) {
                $records[] = $this->makeRecord([
                    'booking_date'   => $header['booking_date'],
                    'customer_id'    => (int) $header['customer_id'],
                    'unit_id'        => $unitId,
                    'discount'       => $u['discount'] ?? 0,
                    'cost_center_id' => $cc,
                    'reference'      => $header['reference'] ?? null,
                    'description'    => $header['description'] ?? null,
                ], $userId);
            }

            // Jurnal MANUAL (satu untuk seluruh booking).
            $lines = array_map(fn (array $l): array => [
                'account_id'     => (int) $l['account_id'],
                'debit'          => round((float) ($l['debit'] ?? 0), 2),
                'credit'         => round((float) ($l['credit'] ?? 0), 2),
                'cost_center_id' => $cc,
            ], $journalLines);

            $first   = $records[0];
            $extra   = count($records) - 1;
            $journal = $this->journal->post([
                'journal_date'   => $header['booking_date'],
                'source'         => 'memorial',
                'reference'      => $first->br_number.($extra > 0 ? ' +'.$extra : ''),
                'description'    => 'Booked Revenue — '.$this->label($first).($extra > 0 ? ' (+'.$extra.' unit)' : ''),
                'cost_center_id' => $cc,
                'created_by'     => $userId,
            ], $lines);

            foreach ($records as $r) {
                $r->update(['booking_journal_id' => $journal->id]);
                // Invoice Piutang otomatis per unit — atomik dgn booking, TANPA jurnal baru
                // (jurnal booking di atas sudah mencatat Piutang). Idempoten di dalam action.
                $this->arInvoice->execute($r->refresh(), $userId);
            }

            return $records;
        });
    }

    // Akui Pendapatan & Batalkan DIHAPUS: jurnal manual saat booking sudah langsung mengkredit
    // Penjualan Usaha (recognize lama jadi redundan), dan booking bersifat tidak bisa dikembalikan
    // (tanpa jalur cancel). Rincian tetap bisa diedit metadata-nya lewat BookedRevenueController@update.

    /** Akun GL dari konfigurasi (kode → id), gagal jelas bila belum diset. */
    private function account(string $key): int
    {
        $code = config("business_support.gl_accounts.{$key}");
        $id   = $code ? BsAccount::where('code', $code)->value('id') : null;
        if (! $id) {
            throw new BusinessRuleException("Akun GL '{$key}' (kode {$code}) belum tersedia di Chart of Account.", errorCode: 'BR_NO_ACCOUNT');
        }

        return (int) $id;
    }

    private function label(BsBookedRevenue $br): string
    {
        $cust = $br->customer?->full_name ?? ('Customer #'.$br->customer_id);
        $unit = $br->unit?->unit_code ?? ('Unit #'.$br->unit_id);

        return $cust.' / '.$unit;
    }

    private function nextNumber(string $date): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('BR', Carbon::parse($date), [], fn () => $this->nextNumberLama($date));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date): string
    {
        $d   = Carbon::parse($date);
        $seq = BsBookedRevenue::withTrashed()->whereYear('booking_date', $d->year)->count() + 1;

        return sprintf('BR/%s/%04d', $d->format('y').$d->format('m'), $seq);
    }
}
