<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Finance\FakturPajak\Actions\CreateFakturPajak;
use App\Domain\Finance\Kuitansi\Models\Kuitansi;
use App\Domain\Finance\Tts\Actions\CreateTts;
use App\Domain\Finance\Tts\Actions\PostTts;
use App\Domain\Finance\Tts\DTOs\TtsDto;
use App\Domain\Finance\Tts\Enums\PaymentMethod;
use App\Domain\Legal\Ajb\Actions\IssueAjb;
use App\Domain\Legal\Bast\Actions\IssueBast;
use App\Domain\Legal\Ppjb\Actions\IssuePpjb;
use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\DTOs\CustomerDto;
use App\Domain\Master\Customer\Enums\IdType;
use App\Domain\Master\Unit\Models\Unit;
use App\Domain\Sales\Booking\Actions\CreateBooking;
use App\Domain\Sales\Booking\Actions\PromoteToContract;
use App\Domain\Sales\Booking\DTOs\BookingDto;
use App\Domain\Sales\Booking\Enums\BookingStatus;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Domain\Sales\Booking\Services\BookingService;
use App\Domain\Sales\Hold\Actions\HoldUnit;
use App\Domain\Sales\Pricing\Models\PaymentScheme;
use App\Models\User;
use App\Domain\Commission\Services\CommissionCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Satu transaksi penuh "awal hingga akhir" untuk customer Ronaldo.
 * Stop pada bug pertama dengan pesan jelas (file:line) supaya bisa di-fix.
 *
 * Run: php artisan trial:transaction
 */
class TrialTransactionCommand extends Command
{
    protected $signature   = 'trial:transaction {--name=Ronaldo}';
    protected $description = 'Jalankan satu transaksi end-to-end (Customer → Booking → PPJB → TTS → Kuitansi → Komisi → Faktur → AJB → BAST).';

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

        $name   = (string) $this->option('name');
        $suffix = now()->format('ymdHis');

        $this->line(str_repeat('━', 68));
        $this->info("TRANSAKSI END-TO-END — Customer: {$name}");
        $this->line(str_repeat('━', 68));

        try {
            // 1. Customer
            $customer = $this->step("Daftar Customer {$name}", fn () => app(CreateCustomer::class)->execute(new CustomerDto(
                fullName:    $name,
                idType:      IdType::KTP,
                idNumber:    '3175'.$suffix,            // 16 digit unik
                phone:       '+62812'.substr($suffix, -8),
                email:       'ronaldo.'.$suffix.'@example.com',
                addressLine: 'Jl. Madeira No. 7',
                city:        'Surabaya',
                province:    'Jawa Timur',
            )));
            $this->ok("Customer #{$customer->id} · {$customer->code} · {$customer->full_name}");

            // 2. Unit available
            $unit = $this->step('Pilih unit AVAILABLE', fn () => Unit::where('status', 'available')->first());
            if (! $unit) {
                throw new \RuntimeException('Tidak ada unit available. Tambah unit dulu.');
            }
            $this->ok("Unit #{$unit->id} · {$unit->unit_code} · Rp ".number_format((float) $unit->current_price, 0, ',', '.'));

            // 3. Hold
            $hold = $this->step('Hold unit (3 hari)', fn () => app(HoldUnit::class)->execute(
                unitId: $unit->id, customerId: $customer->id, durationMinutes: 60 * 24 * 3, reason: 'Konsultasi keluarga',
            ));
            $unit->refresh();
            $this->ok("Hold #{$hold->id} · unit sekarang: {$unit->status->value}");

            // 4. Booking
            $scheme = PaymentScheme::where('code', 'KPR-70')->first() ?? PaymentScheme::first();
            if (! $scheme) {
                throw new \RuntimeException('Tidak ada payment scheme.');
            }
            $booking = $this->step("Booking (skema {$scheme->code}, diskon 0%)", fn () => app(CreateBooking::class)->execute(new BookingDto(
                projectId:       $unit->project_id,
                customerId:      $customer->id,
                unitId:          $unit->id,
                paymentSchemeId: $scheme->id,
                bookingDate:     now()->toDateString(),
                discountPct:     0,
                bookingSource:   'direct',
                notes:           "Transaksi end-to-end {$name}",
            )));
            $unit->refresh();
            $this->ok("Booking #{$booking->id} · {$booking->booking_number} · status={$booking->status->value}");
            $this->dim('  Total: Rp '.number_format((float) $booking->total_price, 0, ',', '.').' · PPN: Rp '.number_format((float) $booking->ppn_amount, 0, ',', '.')." · unit: {$unit->status->value}");

            // 5. Payment schedule
            $booking->load('paymentSchedules');
            $this->step('Verifikasi jadwal pembayaran', fn () => $booking->paymentSchedules->count());
            foreach ($booking->paymentSchedules as $ps) {
                $this->dim(sprintf('  • Termin %d (%s): Rp %s · jatuh tempo %s · %s',
                    $ps->installment_no, $ps->label, number_format((float) $ps->amount, 0, ',', '.'),
                    $ps->due_date?->format('Y-m-d'), $ps->status));
            }
            $this->ok($booking->paymentSchedules->count().' termin ter-generate');

            // 6. Promote → Contract/PPJB
            $contract = $this->step('Promote Booking → Kontrak (PPJB)', fn () => app(PromoteToContract::class)->execute($booking));
            $booking->refresh();
            $this->ok("Contract #{$contract->id} · {$contract->contract_number} · status={$contract->status}");

            // 7. Sign contract → booking Active
            $this->step('Tanda tangan kontrak → Booking Active', function () use ($contract, $booking) {
                $contract->update(['status' => 'signed', 'signed_at' => now()->toDateString()]);
                $booking->refresh();
                if ($booking->status === BookingStatus::Ppjb) {
                    app(BookingService::class)->transitionTo($booking, BookingStatus::Active, 'PPJB ditandatangani');
                }
                return true;
            });
            $booking->refresh();
            $this->ok("Kontrak signed · Booking status: {$booking->status->value}");

            // 8. Issue PPJB document
            $ppjb = $this->step('Terbitkan dokumen PPJB', fn () => app(IssuePpjb::class)->execute($contract->fresh()));
            $this->ok("PPJB {$ppjb->ppjb_number}");

            // 9. TTS DP-1 + auto Kuitansi
            $tts1 = $this->step('TTS DP-1 Rp 50jt (cash) + auto-Kuitansi', function () use ($customer, $booking) {
                $t = app(CreateTts::class)->execute(new TtsDto(
                    customerId: $customer->id, bookingId: $booking->id, amount: 50_000_000,
                    paymentMethod: PaymentMethod::Cash, receivedDate: now()->toDateString(),
                    referenceNo: 'CASH-RON-1', notes: 'DP 1',
                ));
                app(PostTts::class)->execute($t);
                return $t->fresh();
            });
            $kw1 = Kuitansi::where('tts_receipt_id', $tts1->id)->first();
            $this->ok("TTS #{$tts1->id} · {$tts1->tts_number} · status={$tts1->status}".($kw1 ? " → Kuitansi {$kw1->kuitansi_number}" : ' (⚠ kuitansi belum terbit)'));

            // 10. TTS DP-2
            $tts2 = $this->step('TTS DP-2 Rp 50jt (transfer)', function () use ($customer, $booking) {
                $t = app(CreateTts::class)->execute(new TtsDto(
                    customerId: $customer->id, bookingId: $booking->id, amount: 50_000_000,
                    paymentMethod: PaymentMethod::Transfer, receivedDate: now()->toDateString(),
                    referenceNo: 'TRX-RON-2', notes: 'DP 2',
                ));
                app(PostTts::class)->execute($t);
                return $t->fresh();
            });
            $this->ok("TTS #{$tts2->id} · {$tts2->tts_number} · status={$tts2->status}");

            // 11. Commission
            $commission = $this->step('Hitung komisi', function () use ($booking) {
                $agent = DB::table('agents')->first();
                if ($agent) {
                    $booking->update(['agent_id' => $agent->id]);
                }
                return app(CommissionCalculator::class)->calculate($booking->fresh());
            });
            $this->ok("Komisi #{$commission->id} · {$commission->lines()->count()} baris · status={$commission->status}");

            // 12. Faktur Pajak
            $fp = $this->step('Terbitkan Faktur Pajak', fn () => app(CreateFakturPajak::class)->execute(
                ['customer_id' => $customer->id, 'booking_id' => $booking->id, 'issued_at' => now()->toDateString(), 'ppn_pct' => 11],
                [['description' => "Unit {$unit->unit_code} Java Residence", 'qty' => 1, 'unit_price' => (float) $unit->current_price, 'total' => (float) $unit->current_price]],
            ));
            $this->ok("Faktur {$fp->fp_number} · total Rp ".number_format((float) $fp->total, 0, ',', '.'));

            // 13. Lunaskan sisa cicilan (simulasi pelunasan) untuk dokumen serah terima
            $this->step('Lunaskan sisa cicilan (simulasi)', function () use ($booking) {
                PaymentSchedule::where('booking_id', $booking->id)
                    ->update(['paid_amount' => DB::raw('amount'), 'status' => 'paid', 'paid_at' => now()]);
                return true;
            });
            $this->ok('Semua termin → lunas');

            // 14. AJB
            $ajb = $this->step('Terbitkan AJB', fn () => app(IssueAjb::class)->execute($booking->fresh(), 'Notaris Java', now()->toDateString()));
            $this->ok("AJB {$ajb->ajb_number} · notaris {$ajb->notary_name}");

            // 15. BAST (serah terima)
            $bast = $this->step('Terbitkan BAST (serah terima kunci)', fn () => app(IssueBast::class)->execute(
                $booking->fresh(), now()->toDateString(),
                ['kunci_diterima' => true, 'unit_kondisi_baik' => true, 'utilitas_aktif' => true],
            ));
            $this->ok("BAST {$bast->bast_number}");

            // Summary
            $this->line('');
            $this->line(str_repeat('━', 68));
            $this->info('RINGKASAN TRANSAKSI');
            $this->line(str_repeat('━', 68));
            $this->table(['Tahap', 'Hasil'], [
                ['Customer',     "{$customer->code} · {$customer->full_name}"],
                ['Unit',         "{$unit->unit_code} ({$unit->fresh()->status->value})"],
                ['Booking',      "{$booking->booking_number} · {$booking->fresh()->status->value}"],
                ['Kontrak/PPJB', "{$contract->contract_number} · {$ppjb->ppjb_number}"],
                ['Pembayaran',   'TTS x2 (Rp 100jt) → '.Kuitansi::where('customer_id', $customer->id)->count().' kuitansi'],
                ['Komisi',       "#{$commission->id}"],
                ['Faktur Pajak', $fp->fp_number],
                ['AJB',          $ajb->ajb_number],
                ['BAST',         $bast->bast_number],
            ]);

            $this->line('');
            $this->info("✅ TRANSAKSI {$name} SELESAI — dari pendaftaran hingga serah terima & lunas.");

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
