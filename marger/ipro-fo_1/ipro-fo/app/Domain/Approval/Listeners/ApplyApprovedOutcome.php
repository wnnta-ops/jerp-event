<?php

declare(strict_types=1);

namespace App\Domain\Approval\Listeners;

use App\Domain\Approval\Events\ApprovalApproved;
use App\Domain\Approval\Models\Approval;
use App\Domain\Collection\SuratPeringatan\Models\SuratPeringatan;
use App\Domain\Master\Unit\Enums\UnitStatus;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Master\Unit\Services\UnitService;
use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Domain\Sales\Booking\Services\BookingService;
use App\Domain\Sales\Booking\Services\ScheduleGenerator;
use App\Domain\Sales\Hold\Models\UnitHold;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Otomatisasi efek samping setelah sebuah Approval disetujui penuh.
 *
 * Sebelumnya tidak ada callback pasca-approval, sehingga (mis.) booking yang
 * butuh approval diskon tetap DRAFT selamanya walau sudah approved (bug/gap).
 *
 * Listener ini idempoten — aman dipanggil ulang dan tidak menggagalkan approval
 * bila efek samping error (di-report saja).
 */
final class ApplyApprovedOutcome
{
    public function __construct(
        private readonly BookingService $bookingService,
        private readonly ScheduleGenerator $scheduleGen,
        private readonly UnitService $unitService,
    ) {}

    public function handle(ApprovalApproved $event): void
    {
        $approval = $event->approval;
        if ($approval->status !== 'approved') {
            return;
        }

        try {
            match ($approval->document_type) {
                'booking_discount'   => $this->activateBooking($approval),
                'booking_reschedule' => $this->applyReschedule($approval),
                'booking_gn'         => $this->applyNameChange($approval),
                'booking_gu'         => $this->applyUnitChange($approval),
                'surat_peringatan'   => $this->approveSuratPeringatan($approval),
                default              => null,
            };
        } catch (Throwable $e) {
            // Jangan rollback approval hanya karena efek samping gagal.
            report($e);
        }
    }

    /** booking_discount → DRAFT jadi ACTIVE + generate jadwal + book unit. */
    private function activateBooking(Approval $approval): void
    {
        $bookingId = $approval->approvable_type === 'booking'
            ? $approval->approvable_id
            : ($approval->payload['booking_id'] ?? null);

        $booking = $bookingId ? Booking::find($bookingId) : null;
        if (! $booking || $booking->status !== BookingStatus::Draft) {
            return; // idempotent
        }

        DB::transaction(function () use ($booking): void {
            $this->bookingService->transitionTo($booking, BookingStatus::Active, 'Approval diskon disetujui (otomatis).');
            $this->scheduleGen->generate($booking->fresh());

            $unit = Unit::find($booking->unit_id);
            if ($unit && $unit->status !== UnitStatus::Booked) {
                $this->unitService->changeStatus($unit, UnitStatus::Booked);
            }

            UnitHold::query()
                ->where('unit_id', $booking->unit_id)
                ->where('customer_id', $booking->customer_id)
                ->where('status', 'active')
                ->update(['status' => 'converted']);
        });
    }

    /** booking_reschedule → terapkan due date baru ke termin. */
    private function applyReschedule(Approval $approval): void
    {
        $req = $approval->approvable; // RescheduleRequest
        if (! $req || ($req->status ?? null) === 'approved') {
            return; // idempotent
        }

        DB::transaction(function () use ($req): void {
            foreach ((array) $req->proposed_schedule as $row) {
                if (isset($row['id'], $row['new_due_date'])) {
                    PaymentSchedule::where('id', $row['id'])->update(['due_date' => $row['new_due_date']]);
                }
            }
            $req->update(['status' => 'approved']);
        });
    }

    /** booking_gn → ganti customer pemilik booking. */
    private function applyNameChange(Approval $approval): void
    {
        $req = $approval->approvable; // NameChangeRequest
        if (! $req || ($req->status ?? null) === 'approved') {
            return;
        }

        DB::transaction(function () use ($req): void {
            Booking::where('id', $req->booking_id)->update(['customer_id' => $req->new_customer_id]);
            $req->update(['status' => 'approved']);
        });
    }

    /** booking_gu → pindahkan booking ke unit baru + sinkronkan status unit. */
    private function applyUnitChange(Approval $approval): void
    {
        $req = $approval->approvable; // UnitChangeRequest
        if (! $req || ($req->status ?? null) === 'approved') {
            return;
        }

        DB::transaction(function () use ($req): void {
            $booking = Booking::find($req->booking_id);
            if (! $booking) {
                return;
            }

            $booking->update(['unit_id' => $req->new_unit_id]);

            $old = Unit::find($req->old_unit_id);
            if ($old && $old->status === UnitStatus::Booked) {
                $this->unitService->changeStatus($old, UnitStatus::Available);
            }
            $new = Unit::find($req->new_unit_id);
            if ($new && $new->status !== UnitStatus::Booked) {
                $this->unitService->changeStatus($new, UnitStatus::Booked);
            }

            $req->update(['status' => 'approved']);
        });
    }

    /** surat_peringatan → status jadi 'approved' + stempel TTD finance (approver terakhir). */
    private function approveSuratPeringatan(Approval $approval): void
    {
        $sp = $approval->approvable; // SuratPeringatan
        if (! $sp instanceof SuratPeringatan || $sp->status === 'approved') {
            return; // idempotent
        }

        // Approver finance = aktor pada step terakhir yang menyetujui.
        $approverId = $approval->steps()
            ->where('status', 'approved')
            ->orderByDesc('level')
            ->value('approver_id');

        DB::transaction(function () use ($sp, $approverId): void {
            $sp->update(array_filter([
                'status'    => 'approved',
                'signed_by' => $approverId, // TTD finance pada surat cetak
            ], fn ($v) => $v !== null));
        });
    }
}
