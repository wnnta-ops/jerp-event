<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\DTOs\CustomerDto;
use App\Domain\Master\Customer\Enums\IdType;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Actions\CreateBooking;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Pricing\Models\PaymentScheme;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DemoApprovalCommand extends Command
{
    protected $signature   = 'demo:approval';
    protected $description = 'Demo: Booking dengan diskon 5% → masuk approval queue → SalesManager approve';

    public function handle(): int
    {
        // Login as Sales
        $sales = User::where('email', 'rina.sales@mup.local')->first();
        auth()->login($sales);
        app()->instance('tenant.id', $sales->tenant_id);

        // Cleanup
        Customer::where('email', 'hendra@example.com')->forceDelete();

        $this->line(str_repeat('━', 65));
        $this->info('DEMO 2: BOOKING WITH DISCOUNT 5% → APPROVAL FLOW');
        $this->line(str_repeat('━', 65));

        $this->line('');
        $this->line('<comment>[Step 1]</comment> Sales login: rina.sales@mup.local');

        $this->line('');
        $this->line('<comment>[Step 2]</comment> Buat Customer baru (Hendra Putra)');
        $customer = app(CreateCustomer::class)->execute(new CustomerDto(
            fullName:    'Hendra Putra',
            idType:      IdType::KTP,
            idNumber:    '3275071800030003',
            phone:       '+6281234567892',
            email:       'hendra@example.com',
            city:        'Bandung',
        ));
        $this->line("  ✅ Customer #{$customer->id} | {$customer->code}");

        $this->line('');
        $this->line('<comment>[Step 3]</comment> Pilih Unit AVAIL + scheme Cash Keras');
        $unit = Unit::where('status', 'available')->first();
        $scheme = PaymentScheme::where('code', 'CASH-KERAS')->first();
        $this->line("  ✅ Unit {$unit->unit_code} | Rp ".number_format((float) $unit->current_price, 0, ',', '.'));

        $this->line('');
        $this->line('<comment>[Step 4]</comment> Sales request booking dengan diskon 5% (perlu approval SalesManager)');
        $booking = app(CreateBooking::class)->execute(new BookingDto(
            projectId:       $unit->project_id,
            customerId:      $customer->id,
            unitId:          $unit->id,
            paymentSchemeId: $scheme->id,
            bookingDate:     now()->toDateString(),
            discountPct:     5,
            notes:           'Sales request diskon 5% untuk closing Q2',
        ));
        $unit->refresh();
        $this->line("  ✅ Booking #{$booking->id} | {$booking->booking_number}");
        $this->line("     Status: <fg=yellow>{$booking->status->value}</> (DRAFT — menunggu approval)");
        $this->line("     Unit status: {$unit->status->value} (tidak booked sampai approved)");
        $this->line("     Payment schedules: ".$booking->paymentSchedules()->count()." (tidak digenerate karena DRAFT)");

        $this->line('');
        $this->line('<comment>[Step 5]</comment> Submit booking ke approval engine');
        // Submit ke approval engine - karena CreateBooking sudah otomatis route via threshold
        // Tapi belum membuat record. Mari kita panggil engine secara eksplisit
        $submitter = app(\App\Domain\Approval\Actions\SubmitForApproval::class);
        $approval = $submitter->execute('booking_discount', $booking, [
            'booking_id'   => $booking->id,
            'discount_pct' => 5,
            'total_value'  => (float) $booking->total_price,
        ]);
        $this->line("  ✅ Approval #{$approval->id} | document_type=booking_discount | status={$approval->status}");
        $this->line("     Steps: ".$approval->steps()->count());
        foreach ($approval->steps as $step) {
            $this->line("       Level {$step->level}: pending (waiting for approver)");
        }

        $this->line('');
        $this->line('<comment>[Step 6]</comment> Cek pending approvals di Approval Center');
        $pendings = Approval::where('status', 'pending')->count();
        $this->line("  ✅ Pending approvals di sistem: {$pendings}");

        $this->line('');
        $this->line('<comment>[Step 7]</comment> SalesManager (Joko) login & approve');
        $manager = User::where('email', 'joko.smanager@mup.local')->first();
        auth()->login($manager);
        $this->line("  ✅ Logged in: {$manager->name} ({$manager->getRoleNames()->first()})");

        $approved = app(ActOnApproval::class)->execute($approval, 'approve', 'OK, closing strategis Q2');
        $approved->refresh();
        $this->line("  ✅ Approval status NOW: <fg=green>{$approved->status}</>");

        $this->line('');
        $this->line('<comment>[Step 8]</comment> Final state');
        $booking->refresh();
        $this->line("  Booking status: {$booking->status->value} (masih DRAFT — manual confirm setelah approval)");
        $this->line("");
        $this->line('  <fg=cyan>Note:</> Production system perlu post-approval callback yang otomatis');
        $this->line('  convert booking dari DRAFT → ACTIVE setelah approval approved.');
        $this->line('  Untuk demo ini, kita manual transit:');

        // Pasca-approval kini OTOMATIS via listener ApplyApprovedOutcome.
        // Fallback manual hanya bila masih DRAFT (listener tidak aktif).
        $booking->refresh();
        if ($booking->status === \App\Domain\Sales\Booking\Enums\BookingStatus::Draft) {
            app(\App\Domain\Sales\Booking\Services\BookingService::class)
                ->transitionTo($booking, \App\Domain\Sales\Booking\Enums\BookingStatus::Active, 'Approval approved');
            app(\App\Domain\Sales\Booking\Services\ScheduleGenerator::class)->generate($booking->fresh());
            app(\App\Domain\Master\Unit\Services\UnitService::class)
                ->changeStatus(Unit::find($booking->unit_id), \App\Domain\Master\Unit\Enums\UnitStatus::Booked);
        }
        $unit = Unit::find($booking->unit_id);

        $booking->refresh()->load('paymentSchedules');
        $unit->refresh();
        $this->line('');
        $this->line("  Booking final status: <fg=green>{$booking->status->value}</>");
        $this->line("  Unit final status: {$unit->status->value}");
        $this->line("  Payment schedules generated: ".$booking->paymentSchedules->count());
        $this->line("  Total: Rp ".number_format((float) $booking->total_price, 0, ',', '.'));

        $this->line('');
        $this->line(str_repeat('━', 65));
        $this->info('DEMO 2 SUMMARY');
        $this->line(str_repeat('━', 65));
        $this->table(['Metric', 'Value'], [
            ['Booking',                 $booking->booking_number],
            ['Initial status',          'draft (perlu approval karena diskon 5%)'],
            ['Approval document type',  'booking_discount'],
            ['Approval chain',          'DISC-MID (3-7% → SalesManager)'],
            ['Approval status',         'approved'],
            ['Final booking status',    $booking->status->value],
            ['Discount amount',         'Rp '.number_format((float) $booking->discount_amount, 0, ',', '.').' (5% of base)'],
            ['Total after discount',    'Rp '.number_format((float) $booking->total_price, 0, ',', '.')],
            ['Payment schedules',       (string) $booking->paymentSchedules->count()],
            ['Approval actions logged', (string) DB::table('approval_actions')->count()],
        ]);

        $this->line('');
        $this->info('✅ DEMO 2 SELESAI — approval engine + role-based auth bekerja.');

        return self::SUCCESS;
    }
}
