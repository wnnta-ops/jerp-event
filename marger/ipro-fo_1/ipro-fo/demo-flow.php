<?php

/**
 * Demo Flow End-to-End — Java Core System v2.0
 *
 * Run: php artisan tinker demo-flow.php
 */

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\DTOs\CustomerDto;
use App\Domain\Master\Customer\Enums\IdType;
use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Booking\Actions\CreateBooking;
use App\Domain\Sales\Booking\Actions\PromoteToContract;
use App\Domain\Sales\Hold\Actions\HoldUnit;
use App\Domain\Sales\Hold\Models\UnitHold;
use App\Domain\Sales\Pricing\Models\PaymentScheme;
use App\Domain\Finance\Tts\Actions\CreateTts;
use App\Domain\Finance\Tts\Actions\PostTts;
use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Enums\PaymentMethod;
use App\Domain\Finance\Tts\Models\Tts;
use App\Domain\Finance\Kuitansi\Models\Kuitansi;
use App\Models\User;

function header_line($title) {
    echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "  {$title}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
}

function step($n, $msg) { echo "\n[Step {$n}] {$msg}\n"; }
function info($msg)     { echo "         → {$msg}\n"; }
function success($msg)  { echo "         ✅ {$msg}\n"; }

// Login as admin for context
$admin = User::where('email', 'admin@mup.local')->first();
auth()->login($admin);
app()->instance('tenant.id', $admin->tenant_id);

header_line('DEMO 1: BOOKING END-TO-END (KPR 70/30, Tanpa Diskon)');

// ──────────────────────────────────────────────────────────────
step(1, 'Buat Customer (Ani Wijaya)');
$createCust = app(CreateCustomer::class);
$customer = $createCust->execute(new CustomerDto(
    fullName: 'Ani Wijaya',
    idType:   IdType::KTP,
    idNumber: '3275071900020002',
    phone:    '+6281234567891',
    email:    'ani.wijaya@example.com',
    addressLine: 'Jl. Asia Afrika 5',
    city:     'Bandung',
    province: 'Jawa Barat',
));
success("Customer created: #{$customer->id} | {$customer->code} | {$customer->full_name}");

// ──────────────────────────────────────────────────────────────
step(2, 'Pilih Unit AVAIL pertama');
$unit = Unit::where('status', 'available')->first();
success("Unit: #{$unit->id} | {$unit->unit_code} | Rp ".number_format($unit->current_price, 0, ',', '.'));

// ──────────────────────────────────────────────────────────────
step(3, 'Hold Unit (3 hari)');
$hold = app(HoldUnit::class)->execute(
    unitId:          $unit->id,
    customerId:      $customer->id,
    durationMinutes: 60 * 24 * 3,
    reason:          'Customer butuh konsultasi keluarga',
);
$unit->refresh();
success("Hold created: #{$hold->id} | expires {$hold->expires_at->format('Y-m-d H:i')}");
info("Unit status: {$unit->status->value} (was: available)");

// ──────────────────────────────────────────────────────────────
step(4, 'Booking dengan skema KPR 70/30, diskon 0% (auto-confirm)');
$scheme = PaymentScheme::where('code', 'KPR-70')->first();

$createBooking = app(CreateBooking::class);
$booking = $createBooking->execute(new BookingDto(
    projectId:        $unit->project_id,
    customerId:       $customer->id,
    unitId:           $unit->id,
    paymentSchemeId:  $scheme->id,
    bookingDate:      now()->toDateString(),
    discountPct:      0,
    bookingSource:    'direct',
    notes:            'Demo booking via tinker',
));
$unit->refresh();
success("Booking: #{$booking->id} | {$booking->booking_number} | status={$booking->status->value}");
info("Total price: Rp ".number_format($booking->total_price, 0, ',', '.'));
info("PPN: Rp ".number_format($booking->ppn_amount, 0, ',', '.'));
info("Unit status now: {$unit->status->value}");

// ──────────────────────────────────────────────────────────────
step(5, 'Verify Payment Schedule auto-generated');
$booking->load('paymentSchedules');
foreach ($booking->paymentSchedules as $i => $ps) {
    info(sprintf("Termin %d: %s | due %s | Rp %s | %s",
        $ps->installment_no, $ps->label, $ps->due_date->format('Y-m-d'),
        number_format($ps->amount, 0, ',', '.'), $ps->status));
}
success("Total ".$booking->paymentSchedules->count()." termin generated");

// ──────────────────────────────────────────────────────────────
step(6, 'Promote Booking ke PPJB (Contract)');
$contract = app(PromoteToContract::class)->execute($booking);
$booking->refresh();
success("Contract: #{$contract->id} | {$contract->contract_number} | status={$contract->status}");
info("Booking status now: {$booking->status->value}");

// ──────────────────────────────────────────────────────────────
step(7, 'Sign Contract → Auto-transit Booking ke Active');
$contract->update(['status' => 'signed', 'signed_at' => now()->toDateString()]);
$booking->refresh();
if ($booking->status->value === 'ppjb') {
    // Manual promote since the route does it
    $bookingService = app(\App\Domain\Sales\Booking\Services\BookingService::class);
    $bookingService->transitionTo($booking, \App\Domain\Sales\Booking\Enums\BookingStatus::Active, "PPJB signed");
}
$booking->refresh();
success("Contract signed | Booking status: {$booking->status->value}");

// ──────────────────────────────────────────────────────────────
step(8, 'TTS: DP 1 dibayar tunai Rp 100 juta');
$tts1 = app(CreateTts::class)->execute(new TtsDto(
    customerId:    $customer->id,
    bookingId:     $booking->id,
    amount:        100_000_000,
    paymentMethod: PaymentMethod::Cash,
    receivedDate:  now()->toDateString(),
    referenceNo:   'CASH-001-DEMO',
    notes:         'DP 1 — cash',
), autoAllocate: true);
success("TTS: #{$tts1->id} | {$tts1->tts_number} | status={$tts1->status} | amount=Rp ".number_format($tts1->amount, 0, ',', '.'));
$booking->load('paymentSchedules');
$schedule1 = $booking->paymentSchedules->where('installment_no', 1)->first();
info("Schedule #1 ({$schedule1->label}): paid_amount=Rp ".number_format($schedule1->paid_amount, 0, ',', '.')." | status={$schedule1->status}");

// ──────────────────────────────────────────────────────────────
step(9, 'Post TTS → Auto-issue Kuitansi via event listener');
app(PostTts::class)->execute($tts1);
$tts1->refresh();
success("TTS posted: status={$tts1->status} | posted_at={$tts1->posted_at}");
$kuitansi = Kuitansi::where('tts_receipt_id', $tts1->id)->first();
if ($kuitansi) {
    success("Auto-Kuitansi: #{$kuitansi->id} | {$kuitansi->kuitansi_number} | amount=Rp ".number_format($kuitansi->amount, 0, ',', '.'));
    info("Terbilang: {$kuitansi->terbilang}");
} else {
    echo "         ⚠️  Kuitansi tidak ter-issue (event listener missed?)\n";
}

// ──────────────────────────────────────────────────────────────
step(10, 'TTS kedua: DP 2 via transfer Rp 100 juta');
$tts2 = app(CreateTts::class)->execute(new TtsDto(
    customerId:    $customer->id,
    bookingId:     $booking->id,
    amount:        100_000_000,
    paymentMethod: PaymentMethod::Transfer,
    receivedDate:  now()->toDateString(),
    referenceNo:   'TRX-BCA-002-DEMO',
    notes:         'DP 2 — transfer BCA',
), autoAllocate: true);
app(PostTts::class)->execute($tts2);
success("TTS #2: {$tts2->tts_number} | posted | Rp ".number_format($tts2->amount, 0, ',', '.'));

// ──────────────────────────────────────────────────────────────
step(11, 'Final state summary');
$booking->refresh();
$booking->load('paymentSchedules');
$totalPaid = $booking->paymentSchedules->sum('paid_amount');
$outstanding = $booking->paymentSchedules->sum(fn($s) => $s->amount - $s->paid_amount);
info("Total paid: Rp ".number_format($totalPaid, 0, ',', '.'));
info("Outstanding: Rp ".number_format($outstanding, 0, ',', '.'));
foreach ($booking->paymentSchedules as $ps) {
    info(sprintf("Termin %d (%s): %s | paid=%s | outstanding=%s",
        $ps->installment_no, $ps->label, $ps->status,
        number_format($ps->paid_amount, 0, ',', '.'),
        number_format($ps->amount - $ps->paid_amount, 0, ',', '.')));
}

echo "\n";
header_line('DEMO 1 SUMMARY');
echo "  Customer created       : 1 (Ani Wijaya, code: {$customer->code})\n";
echo "  Unit hold transitions  : available → hold → booked\n";
echo "  Booking auto-confirmed : {$booking->booking_number} (no approval needed)\n";
echo "  Payment schedules      : ".$booking->paymentSchedules->count()."\n";
echo "  Contract (PPJB)        : {$contract->contract_number}\n";
echo "  TTS receipts           : 2 (cash + transfer, both posted)\n";
echo "  Kuitansi auto-issued   : ".Kuitansi::where('customer_id', $customer->id)->count()."\n";
echo "  Audit log entries      : ".\DB::table('audit_logs')->count()." total\n";
echo "  Booking status history : ".$booking->statusHistories()->count()."\n";

echo "\n✅ DEMO 1 SELESAI\n";
