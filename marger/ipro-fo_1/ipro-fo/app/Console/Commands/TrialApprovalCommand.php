<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\DTOs\CustomerDto;
use App\Domain\Master\Customer\Enums\IdType;
use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Services\UnitService;
use App\Domain\Sales\Booking\Actions\CreateBooking;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Domain\Sales\Booking\Services\BookingService;
use App\Domain\Sales\Booking\Services\ScheduleGenerator;
use App\Domain\Sales\Exception\Reschedule\Actions\RequestReschedule;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Skenario: Customer → Booking diskon % besar → approval BERJENJANG (3 level) →
 * aktivasi → reschedule cicilan (approval 2 level).
 *
 * Run: php artisan trial:approval
 */
class TrialApprovalCommand extends Command
{
    protected $signature   = 'trial:approval {--name=Mbappe} {--discount-pct=15}';
    protected $description = 'Booking diskon % → approval berjenjang → reschedule cicilan (end-to-end).';

    private int $stepNo = 0;
    private User $admin;

    public function handle(): int
    {
        $this->admin = User::where('email', 'admin@mup.local')->first();
        if (! $this->admin) {
            $this->error('User admin@mup.local tidak ditemukan.');
            return self::FAILURE;
        }
        auth()->login($this->admin);
        app()->instance('tenant.id', $this->admin->tenant_id);

        $name        = (string) $this->option('name');
        $discountPct = (float) $this->option('discount-pct');
        $suffix      = now()->format('ymdHis');
        $rp          = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');

        $this->line(str_repeat('━', 70));
        $this->info("SKENARIO APPROVAL BERJENJANG + RESCHEDULE — Customer: {$name}");
        $this->line(str_repeat('━', 70));

        try {
            // 1. Register
            $customer = $this->step("Register Customer {$name}", fn () => app(CreateCustomer::class)->execute(new CustomerDto(
                fullName: $name, idType: IdType::KTP, idNumber: '3174'.$suffix,
                phone: '+62814'.substr($suffix, -8), email: 'mbappe.'.$suffix.'@example.com',
                addressLine: 'Jl. Bondy No. 7', city: 'Bandung', province: 'Jawa Barat',
            )));
            $this->ok("Customer #{$customer->id} · {$customer->code} · {$customer->full_name}");

            // 2. Unit
            $unit = $this->step('Pilih unit AVAILABLE', fn () => Unit::where('status', 'available')->first());
            if (! $unit) {
                throw new \RuntimeException('Tidak ada unit available.');
            }
            $this->ok("Unit #{$unit->id} · {$unit->unit_code} · {$rp($unit->current_price)}");

            // 3. Booking diskon 15% → harus DRAFT (perlu approval)
            $scheme = \App\Domain\Sales\Pricing\Models\PaymentScheme::where('code', 'KPR-70')->first()
                ?? \App\Domain\Sales\Pricing\Models\PaymentScheme::first();
            $booking = $this->step("Booking diskon {$discountPct}% (memicu approval)", fn () => app(CreateBooking::class)->execute(new BookingDto(
                projectId: $unit->project_id, customerId: $customer->id, unitId: $unit->id,
                paymentSchemeId: $scheme->id, bookingDate: now()->toDateString(),
                discountPct: $discountPct, bookingSource: 'direct',
                notes: "Booking {$name} diskon {$discountPct}%",
            )));
            $unit->refresh();
            $this->ok("Booking #{$booking->id} · {$booking->booking_number} · status=".$booking->status->value);
            $this->dim("  Diskon: {$rp($booking->discount_amount)} ({$discountPct}%) · TOTAL: {$rp($booking->total_price)}");
            if ($booking->status !== BookingStatus::Draft) {
                throw new \RuntimeException("BUG: booking diskon {$discountPct}% seharusnya DRAFT (perlu approval), tapi status={$booking->status->value}.");
            }
            $this->dim("  Unit: {$unit->status->value} (belum booked sampai approved) · termin: {$booking->paymentSchedules()->count()} (belum digenerate)");

            // 4. Submit ke approval engine
            $approval = $this->step('Submit ke approval engine (chain diskon)', fn () => app(SubmitForApproval::class)->execute(
                'booking_discount', $booking,
                ['booking_id' => $booking->id, 'discount_pct' => $discountPct, 'total_value' => (float) $booking->total_price],
            ));
            $approval->load('steps');
            $levels = $approval->steps->sortBy('level')->values();
            $chainCode = $this->chainCode($approval);
            $this->ok("Approval #{$approval->id} · chain={$chainCode} · {$levels->count()} level · status={$approval->status}");
            foreach ($levels as $lv) {
                $role = $this->chainRole($approval, (int) $lv->level);
                $this->dim("    Level {$lv->level}: {$role} — pending");
            }
            if ($levels->count() < 2) {
                throw new \RuntimeException("Diskon {$discountPct}% tidak menghasilkan approval berjenjang (level < 2).");
            }

            // 5. Approve berjenjang per level (login sesuai role)
            foreach ($levels as $lv) {
                $role = $this->chainRole($approval, (int) $lv->level);
                $actor = $this->actor($role);
                auth()->login($actor);
                $approval = $this->step("Approve Level {$lv->level} sebagai {$role} ({$actor->email})", fn () => app(ActOnApproval::class)->execute($approval, 'approve', "Setuju level {$lv->level}"));
                $this->ok("Approval status → {$approval->status}");
            }
            auth()->login($this->admin);
            if ($approval->status !== 'approved') {
                throw new \RuntimeException("Approval belum approved setelah semua level (status={$approval->status}).");
            }

            // 6. Post-approval: harus otomatis via listener ApplyApprovedOutcome.
            $mode = $this->step('Cek aktivasi otomatis pasca-approval (listener)', function () use ($booking, $unit) {
                $booking->refresh();
                if ($booking->status === BookingStatus::Draft) {
                    // Fallback manual bila listener tidak aktif.
                    app(BookingService::class)->transitionTo($booking, BookingStatus::Active, 'Approval diskon disetujui');
                    app(ScheduleGenerator::class)->generate($booking->fresh());
                    app(UnitService::class)->changeStatus($unit, UnitStatus::Booked);
                    return 'MANUAL (listener tidak jalan!)';
                }
                return 'OTOMATIS via listener';
            });
            $booking->refresh()->load('paymentSchedules');
            $unit->refresh();
            $this->ok("Aktivasi: {$mode}");
            $this->ok("Booking: {$booking->status->value} · Unit: {$unit->status->value} · termin: {$booking->paymentSchedules->count()}");

            // 7. Reschedule cicilan → approval 2 level
            $sched = $booking->paymentSchedules->sortBy('installment_no')->first();
            $oldDue = $sched->due_date?->toDateString();
            $newDue = $sched->due_date?->copy()->addDays(60)->toDateString();
            $reschedule = $this->step("Ajukan reschedule termin #{$sched->installment_no} ({$oldDue} → {$newDue}, fee Rp 500rb)", fn () => app(RequestReschedule::class)->execute(
                $booking, [['id' => $sched->id, 'new_due_date' => $newDue]], 500_000,
            ));
            $rsApproval = Approval::find($reschedule->approval_id);
            $rsApproval->load('steps');
            $rsLevels = $rsApproval->steps->sortBy('level')->values();
            $this->ok("Reschedule #{$reschedule->id} · Approval #{$rsApproval->id} · {$rsLevels->count()} level · status={$rsApproval->status}");

            // 8. Approve reschedule berjenjang
            foreach ($rsLevels as $lv) {
                $role = $this->chainRole($rsApproval, (int) $lv->level);
                $actor = $this->actor($role);
                auth()->login($actor);
                $rsApproval = $this->step("Approve reschedule Level {$lv->level} sebagai {$role}", fn () => app(ActOnApproval::class)->execute($rsApproval, 'approve', "ACC reschedule L{$lv->level}"));
                $this->ok("Approval status → {$rsApproval->status}");
            }
            auth()->login($this->admin);

            // 9. Penerapan due date baru — harus otomatis via listener.
            $rsMode = $this->step('Cek penerapan due date otomatis (listener)', function () use ($reschedule) {
                $reschedule->refresh();
                if (($reschedule->status ?? null) !== 'approved') {
                    foreach ((array) $reschedule->proposed_schedule as $row) {
                        PaymentSchedule::where('id', $row['id'])->update(['due_date' => $row['new_due_date']]);
                    }
                    $reschedule->update(['status' => 'approved']);
                    return 'MANUAL (listener tidak jalan!)';
                }
                return 'OTOMATIS via listener';
            });
            $sched->refresh();
            $this->ok("Penerapan: {$rsMode} · Termin #{$sched->installment_no} jatuh tempo baru: {$sched->due_date?->toDateString()}");

            // Summary
            $this->line('');
            $this->line(str_repeat('━', 70));
            $this->info('RINGKASAN');
            $this->line(str_repeat('━', 70));
            $this->table(['Tahap', 'Hasil'], [
                ['Customer',            "{$customer->code} · {$customer->full_name}"],
                ['Booking',            "{$booking->booking_number} · diskon {$discountPct}% ({$rp($booking->discount_amount)})"],
                ['Status awal',        'draft (perlu approval)'],
                ['Approval diskon',    "{$levels->count()} level ({$chainCode}) → approved"],
                ['Status akhir',       $booking->status->value],
                ['Reschedule',         "termin #{$sched->installment_no}: {$oldDue} → {$newDue}"],
                ['Approval reschedule', "{$rsLevels->count()} level (RS-DEFAULT) → {$rsApproval->status}"],
                ['Approval actions',   (string) DB::table('approval_actions')->count()],
            ]);

            $this->line('');
            $this->info("✅ SKENARIO {$name} SELESAI — approval berjenjang & reschedule berfungsi.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            auth()->login($this->admin);
            $this->line('');
            $this->error("✗ BUG pada langkah #{$this->stepNo}:");
            $this->line("  <fg=red>".get_class($e).': '.$e->getMessage()."</>");
            $this->line("  <fg=gray>{$e->getFile()}:{$e->getLine()}</>");

            return self::FAILURE;
        }
    }

    /** Kode chain dari approval_chains. */
    private function chainCode(Approval $approval): string
    {
        return (string) (DB::table('approval_chains')->where('id', $approval->approval_chain_id)->value('code') ?? '?');
    }

    /** Ambil role approver dari chain step untuk level tertentu. */
    private function chainRole(Approval $approval, int $level): string
    {
        $step = DB::table('approval_chain_steps')
            ->where('approval_chain_id', $approval->approval_chain_id)
            ->where('level', $level)
            ->first();

        return $step->approver_value ?? 'SuperAdmin';
    }

    /** User dengan role tsb, fallback ke admin (approval engine belum enforce role). */
    private function actor(string $role): User
    {
        return User::role($role)->where('is_active', true)->first() ?? $this->admin;
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
