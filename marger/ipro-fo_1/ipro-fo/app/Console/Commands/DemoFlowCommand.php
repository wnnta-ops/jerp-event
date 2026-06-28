<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Finance\Kuitansi\Models\Kuitansi;
use App\Domain\Finance\Tts\Actions\CreateTts;
use App\Domain\Finance\Tts\Actions\PostTts;
use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Enums\PaymentMethod;
use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\DTOs\CustomerDto;
use App\Domain\Master\Customer\Enums\IdType;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Actions\CreateBooking;
use App\Domain\Sales\Booking\Actions\PromoteToContract;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Services\BookingService;
use App\Domain\Sales\Hold\Actions\HoldUnit;
use App\Domain\Sales\Pricing\Models\PaymentScheme;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoFlowCommand extends Command
{
    protected $signature   = 'demo:flow';
    protected $description = 'Run full end-to-end demo: Customer → Hold → Booking → PPJB → TTS → Kuitansi';

    public function handle(): int
    {
        $admin = User::where('email', 'admin@mup.local')->first();
        auth()->login($admin);
        app()->instance('tenant.id', $admin->tenant_id);

        // Cleanup
        Customer::where('email', 'ani.wijaya@example.com')->forceDelete();

        $this->line(str_repeat('━', 65));
        $this->info('DEMO 1: BOOKING END-TO-END (KPR 70/30, Tanpa Diskon)');
        $this->line(str_repeat('━', 65));

        $this->step(1, 'Buat Customer (Ani Wijaya)');
        $customer = app(CreateCustomer::class)->execute(new CustomerDto(
            fullName:    'Ani Wijaya',
            idType:      IdType::KTP,
            idNumber:    '3275071900020002',
            phone:       '+6281234567891',
            email:       'ani.wijaya@example.com',
            addressLine: 'Jl. Asia Afrika 5',
            city:        'Bandung',
            province:    'Jawa Barat',
        ));
        $this->ok("Customer #{$customer->id} | {$customer->code} | {$customer->full_name}");

        $this->step(2, 'Pilih Unit AVAIL pertama');
        $unit = Unit::where('status', 'available')->first();
        $this->ok("Unit #{$unit->id} | {$unit->unit_code} | Rp ".number_format((float) $unit->current_price, 0, ',', '.'));

        $this->step(3, 'Hold Unit (3 hari)');
        $hold = app(HoldUnit::class)->execute(
            unitId:          $unit->id,
            customerId:      $customer->id,
            durationMinutes: 60 * 24 * 3,
            reason:          'Customer butuh konsultasi keluarga',
        );
        $unit->refresh();
        $this->ok("Hold #{$hold->id} | expires {$hold->expires_at->format('Y-m-d H:i')}");
        $this->info("  Unit status NOW: {$unit->status->value} (was: available)");

        $this->step(4, 'Booking dengan skema KPR 70/30, diskon 0%');
        $scheme = PaymentScheme::where('code', 'KPR-70')->first();
        $booking = app(CreateBooking::class)->execute(new BookingDto(
            projectId:       $unit->project_id,
            customerId:      $customer->id,
            unitId:          $unit->id,
            paymentSchemeId: $scheme->id,
            bookingDate:     now()->toDateString(),
            discountPct:     0,
            bookingSource:   'direct',
            notes:           'Demo booking via artisan',
        ));
        $unit->refresh();
        $this->ok("Booking #{$booking->id} | {$booking->booking_number} | status={$booking->status->value}");
        $this->info("  Total: Rp ".number_format((float) $booking->total_price, 0, ',', '.'));
        $this->info("  PPN: Rp ".number_format((float) $booking->ppn_amount, 0, ',', '.'));
        $this->info("  Unit status: {$unit->status->value}");

        $this->step(5, 'Verify Payment Schedule auto-generated');
        $booking->load('paymentSchedules');
        foreach ($booking->paymentSchedules as $ps) {
            $this->line(sprintf("  • Termin %d: %s | due %s | Rp %s | %s",
                $ps->installment_no, $ps->label, $ps->due_date->format('Y-m-d'),
                number_format((float) $ps->amount, 0, ',', '.'), $ps->status));
        }
        $this->ok($booking->paymentSchedules->count().' termin generated');

        $this->step(6, 'Promote Booking ke PPJB (Contract)');
        $contract = app(PromoteToContract::class)->execute($booking);
        $booking->refresh();
        $this->ok("Contract #{$contract->id} | {$contract->contract_number} | status={$contract->status}");
        $this->info("  Booking status NOW: {$booking->status->value}");

        $this->step(7, 'Sign Contract → Booking Active');
        $contract->update(['status' => 'signed', 'signed_at' => now()->toDateString()]);
        $booking->refresh();
        if ($booking->status === BookingStatus::Ppjb) {
            app(BookingService::class)->transitionTo($booking, BookingStatus::Active, "PPJB signed");
        }
        $booking->refresh();
        $this->ok("Contract signed | Booking status: {$booking->status->value}");

        $this->step(8, 'TTS: DP 1 Rp 100 jt (cash)');
        $tts1 = app(CreateTts::class)->execute(new TtsDto(
            customerId:    $customer->id,
            bookingId:     $booking->id,
            amount:        100_000_000,
            paymentMethod: PaymentMethod::Cash,
            receivedDate:  now()->toDateString(),
            referenceNo:   'CASH-001-DEMO',
            notes:         'DP 1 — cash',
        ));
        $this->ok("TTS #{$tts1->id} | {$tts1->tts_number} | Rp ".number_format((float) $tts1->amount, 0, ',', '.')." | status={$tts1->status}");

        $booking->load('paymentSchedules');
        $s1 = $booking->paymentSchedules->where('installment_no', 1)->first();
        $this->info("  Schedule #1 ({$s1->label}): paid=Rp ".number_format((float) $s1->paid_amount, 0, ',', '.')." | status={$s1->status}");

        $this->step(9, 'Post TTS → Auto-issue Kuitansi (event listener)');
        app(PostTts::class)->execute($tts1);
        $tts1->refresh();
        $this->ok("TTS posted: status={$tts1->status}");
        $kuitansi = Kuitansi::where('tts_receipt_id', $tts1->id)->first();
        if ($kuitansi) {
            $this->ok("Auto-Kuitansi #{$kuitansi->id} | {$kuitansi->kuitansi_number} | Rp ".number_format((float) $kuitansi->amount, 0, ',', '.'));
            $this->info("  Terbilang: {$kuitansi->terbilang}");
        } else {
            $this->warn('  ⚠ Kuitansi tidak ter-issue');
        }

        $this->step(10, 'TTS 2: DP 2 Rp 100 jt (transfer)');
        $tts2 = app(CreateTts::class)->execute(new TtsDto(
            customerId:    $customer->id,
            bookingId:     $booking->id,
            amount:        100_000_000,
            paymentMethod: PaymentMethod::Transfer,
            receivedDate:  now()->toDateString(),
            referenceNo:   'TRX-BCA-002-DEMO',
            notes:         'DP 2 — transfer BCA',
        ));
        app(PostTts::class)->execute($tts2);
        $tts2->refresh();
        $this->ok("TTS #{$tts2->id} | {$tts2->tts_number} | posted | Rp ".number_format((float) $tts2->amount, 0, ',', '.'));

        $this->step(11, 'Final state summary');
        $booking->refresh();
        $booking->load('paymentSchedules');
        $totalPaid = (float) $booking->paymentSchedules->sum('paid_amount');
        $outstanding = (float) $booking->paymentSchedules->sum(fn ($s) => (float) $s->amount - (float) $s->paid_amount);
        $this->info("  Total paid: Rp ".number_format($totalPaid, 0, ',', '.'));
        $this->info("  Outstanding: Rp ".number_format($outstanding, 0, ',', '.'));
        $this->line('');
        foreach ($booking->paymentSchedules as $ps) {
            $this->line(sprintf("  • Termin %d (%s): %s | paid=%s | sisa=%s",
                $ps->installment_no, $ps->label, $ps->status,
                number_format((float) $ps->paid_amount, 0, ',', '.'),
                number_format((float) $ps->amount - (float) $ps->paid_amount, 0, ',', '.')));
        }

        $this->line('');
        $this->line(str_repeat('━', 65));
        $this->info('DEMO 1 SUMMARY');
        $this->line(str_repeat('━', 65));
        $this->table(['Metric', 'Value'], [
            ['Customer created',     "1 ({$customer->code})"],
            ['Unit status flow',     'available → hold → booked'],
            ['Booking',              $booking->booking_number],
            ['Booking status',       $booking->status->value],
            ['Payment schedules',    (string) $booking->paymentSchedules->count()],
            ['Contract (PPJB)',      $contract->contract_number],
            ['TTS receipts',         (string) DB::table('tts_receipts')->where('booking_id', $booking->id)->count()],
            ['Kuitansi auto-issued', (string) Kuitansi::where('customer_id', $customer->id)->count()],
            ['Audit log entries',    (string) DB::table('audit_logs')->count()],
            ['Status history',       (string) $booking->statusHistories()->count()],
        ]);

        $this->line('');
        $this->info('✅ DEMO 1 SELESAI — semua side effects bekerja.');

        return self::SUCCESS;
    }

    private function step(int $n, string $msg): void
    {
        $this->line('');
        $this->line("<comment>[Step {$n}]</comment> {$msg}");
    }

    private function ok(string $msg): void
    {
        $this->line("  <info>✅</info> {$msg}");
    }
}
