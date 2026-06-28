<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Finance\Kuitansi\Models\Kuitansi;
use App\Domain\Finance\Memo\Actions\CreateMemo;
use App\Domain\Finance\Tts\Actions\CreateTts;
use App\Domain\Finance\Tts\Actions\PostTts;
use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Enums\PaymentMethod;
use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\DTOs\CustomerDto;
use App\Domain\Master\Customer\Enums\IdType;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Actions\CancelBooking;
use App\Domain\Sales\Booking\Actions\CreateBooking;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Pricing\Models\PaymentScheme;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Skenario: Customer baru → Booking dengan diskon Rp 10 juta → bayar DP →
 * pembatalan → refund (pending → approved → paid via memo).
 *
 * Run: php artisan trial:cancellation
 */
class TrialCancellationCommand extends Command
{
    protected $signature   = 'trial:cancellation {--name=Messi} {--discount=10000000} {--dp=50000000} {--penalty=5000000}';
    protected $description = 'Transaksi dengan diskon → register → pembatalan → refund (end-to-end).';

    private int $stepNo = 0;

    public function handle(): int
    {
        $admin = User::where('email', 'admin@mup.local')->first();
        if (! $admin) {
            $this->error('User admin@mup.local tidak ditemukan.');
            return self::FAILURE;
        }
        auth()->login($admin);
        app()->instance('tenant.id', $admin->tenant_id);

        $name     = (string) $this->option('name');
        $discount = (float) $this->option('discount');
        $dp       = (float) $this->option('dp');
        $penalty  = (float) $this->option('penalty');
        $suffix   = now()->format('ymdHis');
        $rp       = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

        $this->line(str_repeat('━', 68));
        $this->info("SKENARIO PEMBATALAN & REFUND — Customer: {$name}");
        $this->line(str_repeat('━', 68));

        try {
            // 1. Register customer
            $customer = $this->step("Register Customer {$name}", fn () => app(CreateCustomer::class)->execute(new CustomerDto(
                fullName:    $name,
                idType:      IdType::KTP,
                idNumber:    '3173'.$suffix,
                phone:       '+62813'.substr($suffix, -8),
                email:       'messi.'.$suffix.'@example.com',
                addressLine: 'Jl. Rosario No. 10',
                city:        'Jakarta',
                province:    'DKI Jakarta',
            )));
            $this->ok("Customer #{$customer->id} · {$customer->code} · {$customer->full_name}");

            // 2. Unit
            $unit = $this->step('Pilih unit AVAILABLE', fn () => Unit::where('status', 'available')->first());
            if (! $unit) {
                throw new \RuntimeException('Tidak ada unit available.');
            }
            $this->ok("Unit #{$unit->id} · {$unit->unit_code} · {$rp($unit->current_price)}");

            // 3. Booking dengan diskon Rp 10 juta (fixed amount)
            $scheme = PaymentScheme::where('code', 'KPR-70')->first() ?? PaymentScheme::first();
            $booking = $this->step("Booking dengan diskon {$rp($discount)}", fn () => app(CreateBooking::class)->execute(new BookingDto(
                projectId:       $unit->project_id,
                customerId:      $customer->id,
                unitId:          $unit->id,
                paymentSchemeId: $scheme->id,
                bookingDate:     now()->toDateString(),
                discountPct:     0,
                discountAmount:  $discount,
                bookingSource:   'direct',
                notes:           "Booking {$name} diskon {$rp($discount)}",
            )));
            $unit->refresh();
            $this->ok("Booking #{$booking->id} · {$booking->booking_number} · status={$booking->status->value}");
            $this->dim("  Harga dasar: {$rp($booking->base_price)} · diskon: {$rp($booking->discount_amount)} · PPN: {$rp($booking->ppn_amount)} · TOTAL: {$rp($booking->total_price)}");
            $this->dim("  Unit sekarang: {$unit->status->value} · approval diskon: ".($booking->status->value === 'draft' ? 'PERLU' : 'tidak perlu (diskon nominal)'));

            // 4. Bayar DP via TTS + Kuitansi
            $tts = $this->step("Bayar DP {$rp($dp)} (transfer) + Kuitansi", function () use ($customer, $booking, $dp) {
                $t = app(CreateTts::class)->execute(new TtsDto(
                    customerId: $customer->id, bookingId: $booking->id, amount: $dp,
                    paymentMethod: PaymentMethod::Transfer, receivedDate: now()->toDateString(),
                    referenceNo: 'TRX-MSI-1', notes: 'DP awal',
                ));
                app(PostTts::class)->execute($t);
                return $t->fresh();
            });
            $kw = Kuitansi::where('tts_receipt_id', $tts->id)->first();
            $this->ok("TTS {$tts->tts_number} posted".($kw ? " → Kuitansi {$kw->kuitansi_number}" : ''));
            $paidNow = (float) DB::table('payment_schedules')->where('booking_id', $booking->id)->sum('paid_amount');
            $this->dim("  Total dibayar (teralokasi ke termin): {$rp($paidNow)}");

            // 5. PEMBATALAN
            $this->step("Batalkan booking (penalti {$rp($penalty)})", fn () => app(CancelBooking::class)->execute(
                $booking, "Customer {$name} membatalkan — pindah kota", $penalty,
            ));
            $booking->refresh();
            $unit->refresh();
            $this->ok("Booking status: {$booking->status->value} · Unit kembali: {$unit->status->value}");

            $cancel = DB::table('cancellation_requests')->where('booking_id', $booking->id)->latest('id')->first();
            $this->dim("  Cancellation #{$cancel->id}: dibayar {$rp($cancel->paid_to_date)} − penalti {$rp($cancel->penalty_amount)} = refund {$rp($cancel->refund_amount)}");

            // 6. REFUND otomatis dibuat (hasil fix)
            $refund = $this->step('Cek permintaan refund (auto-dibuat saat batal)', fn () => DB::table('refunds')
                ->where('refundable_type', 'booking')->where('refundable_id', $booking->id)->latest('id')->first());
            if (! $refund) {
                throw new \RuntimeException('BUG: refund tidak terbuat saat pembatalan.');
            }
            $this->ok("Refund {$refund->refund_number} · {$rp($refund->amount)} · status={$refund->status}");

            // 7. Approve refund (sesuai RefundController@approve)
            $this->step('Approve refund (Finance)', function () use ($refund) {
                DB::table('refunds')->where('id', $refund->id)->update(['status' => 'approved', 'updated_at' => now()]);
                return true;
            });
            $this->ok('Refund status → approved');

            // 8. Bayar refund via memo (sesuai RefundController@pay)
            $memo = $this->step('Bayar refund → terbitkan Memo keluar', function () use ($refund) {
                $r = DB::table('refunds')->where('id', $refund->id)->first();
                $memo = app(CreateMemo::class)->execute([
                    'type'           => 'outgoing',
                    'memo_date'      => now()->toDateString(),
                    'amount'         => $r->amount,
                    'description'    => "Refund {$r->refund_number}",
                    'reference_type' => $r->refundable_type,
                    'reference_id'   => $r->refundable_id,
                    'status'         => 'approved',
                ]);
                DB::table('refunds')->where('id', $refund->id)->update([
                    'status' => 'paid', 'paid_at' => now()->toDateString(), 'memo_id' => $memo->id, 'updated_at' => now(),
                ]);
                return $memo;
            });
            $finalRefund = DB::table('refunds')->where('id', $refund->id)->first();
            $this->ok("Memo {$memo->memo_number} ({$rp($memo->amount)}) · Refund status → {$finalRefund->status}");

            // Summary
            $this->line('');
            $this->line(str_repeat('━', 68));
            $this->info('RINGKASAN');
            $this->line(str_repeat('━', 68));
            $this->table(['Tahap', 'Hasil'], [
                ['Customer',        "{$customer->code} · {$customer->full_name}"],
                ['Booking',         "{$booking->booking_number} · diskon {$rp($booking->discount_amount)}"],
                ['Total kontrak',   $rp($booking->total_price)],
                ['DP dibayar',      $rp($paidNow)],
                ['Pembatalan',      "status booking = {$booking->status->value}"],
                ['Unit',            "{$unit->unit_code} → {$unit->status->value} (kembali tersedia)"],
                ['Penalti',         $rp($penalty)],
                ['Refund',          "{$finalRefund->refund_number} · {$rp($finalRefund->amount)} · {$finalRefund->status}"],
                ['Memo refund',     $memo->memo_number],
            ]);

            $this->line('');
            $this->info("✅ SKENARIO {$name} SELESAI — register → diskon → bayar → batal → refund lunas.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line('');
            $this->error("✗ BUG pada langkah #{$this->stepNo}:");
            $this->line("  <fg=red>".get_class($e).': '.$e->getMessage()."</>");
            $this->line("  <fg=gray>{$e->getFile()}:{$e->getLine()}</>");

            return self::FAILURE;
        }
    }

    /** @template T  @param callable():T $fn  @return T */
    private function step(string $msg, callable $fn): mixed
    {
        $this->stepNo++;
        $this->line('');
        $this->line("<comment>[{$this->stepNo}]</comment> {$msg}");

        return $fn();
    }

    private function ok(string $msg): void
    {
        $this->line("  <fg=green>✓</> {$msg}");
    }

    private function dim(string $msg): void
    {
        $this->line("  <fg=gray>{$msg}</>");
    }
}
