<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class TrialAllCommand extends Command
{
    protected $signature   = 'trial:all';
    protected $description = 'Trial input untuk SEMUA modul. Setiap module di-test create/update. Bug akan ditampilkan.';

    /** @var array<string, array{ok: bool, message: string, time_ms: int}> */
    private array $results = [];

    public function handle(): int
    {
        $admin = User::where('email', 'admin@mup.local')->first();
        auth()->login($admin);
        app()->instance('tenant.id', $admin->tenant_id);

        $this->line(str_repeat('═', 70));
        $this->info('TRIAL INPUT PER MODUL — Java Core System v2.0');
        $this->line(str_repeat('═', 70));

        $this->trial('1. Hold',                fn () => $this->trialHold());
        $this->trial('2. NUP Registration',    fn () => $this->trialNup());
        $this->trial('3. Memo (Finance)',      fn () => $this->trialMemo());
        $this->trial('4. Followup (Collection)', fn () => $this->trialFollowup());
        $this->trial('5. KPR Application',     fn () => $this->trialKpr());
        $this->trial('6. SP3K Register',       fn () => $this->trialSp3k());
        $this->trial('7. KPR Akad',            fn () => $this->trialAkad());
        $this->trial('8. KPR Disbursement',    fn () => $this->trialDisbursement());
        $this->trial('9. PPJB Issue',          fn () => $this->trialPpjb());
        $this->trial('10. AJB Issue',          fn () => $this->trialAjb());
        $this->trial('11. BAST Issue',         fn () => $this->trialBast());
        $this->trial('12. IMB Register',       fn () => $this->trialImb());
        $this->trial('13. Sertifikat',         fn () => $this->trialSertifikat());
        $this->trial('14. SP1 Issue',          fn () => $this->trialSp1());
        $this->trial('16. Commission Calc',    fn () => $this->trialCommission());
        $this->trial('17. Faktur Pajak',       fn () => $this->trialFakturPajak());
        $this->trial('18. Cashback',           fn () => $this->trialCashback());
        $this->trial('19. VA Assign',          fn () => $this->trialVa());
        $this->trial('20. Settings User',      fn () => $this->trialSettingsUser());
        $this->trial('21. Parameter Update',   fn () => $this->trialParameter());
        $this->trial('22. Numerator Preview',  fn () => $this->trialNumerator());
        $this->trial('23. Exception GN',       fn () => $this->trialGn());
        $this->trial('24. Exception Reschedule', fn () => $this->trialReschedule());

        $this->summary();

        return self::SUCCESS;
    }

    private function trial(string $name, callable $fn): void
    {
        $start = microtime(true);
        try {
            $result = $fn();
            $elapsed = (int) ((microtime(true) - $start) * 1000);
            $this->results[$name] = ['ok' => true, 'message' => $result ?: 'OK', 'time_ms' => $elapsed];
            $this->line("  <fg=green>✓</> [$elapsed ms] $name → $result");
        } catch (Throwable $e) {
            $elapsed = (int) ((microtime(true) - $start) * 1000);
            $this->results[$name] = ['ok' => false, 'message' => $e->getMessage(), 'time_ms' => $elapsed];
            $this->line("  <fg=red>✗</> [$elapsed ms] $name");
            $this->line("    <fg=red>{$e->getMessage()}</>");
            $this->line("    <fg=gray>at {$e->getFile()}:{$e->getLine()}</>");
        }
    }

    private function summary(): void
    {
        $passed = collect($this->results)->filter(fn ($r) => $r['ok'])->count();
        $failed = collect($this->results)->reject(fn ($r) => $r['ok'])->count();

        $this->line('');
        $this->line(str_repeat('═', 70));
        $this->info("HASIL: $passed PASS · $failed FAIL · ".count($this->results)." total");
        $this->line(str_repeat('═', 70));

        if ($failed > 0) {
            $this->line('');
            $this->error('BUG YANG PERLU DIPERBAIKI:');
            foreach ($this->results as $name => $r) {
                if (! $r['ok']) {
                    $this->line("  • $name: {$r['message']}");
                }
            }
        }
    }

    // ─── TRIAL FUNCTIONS ──────────────────────────────────────────────────

    private function trialHold(): string
    {
        $unit = \App\Domain\Master\Unit\Models\Unit::where('status', 'available')->first();
        $customer = \App\Domain\Master\Customer\Models\Customer::first();
        $hold = app(\App\Domain\Sales\Hold\Actions\HoldUnit::class)->execute(
            unitId: $unit->id, customerId: $customer->id,
            durationMinutes: 60 * 24, reason: 'Trial test',
        );
        $unit->refresh();

        // Release
        app(\App\Domain\Sales\Hold\Actions\ReleaseHold::class)->execute($hold);
        $unit->refresh();

        return "Hold #{$hold->id} created+released. Unit final: {$unit->status->value}";
    }

    private function trialNup(): string
    {
        $scheme = \App\Domain\Sales\Nup\Models\NupScheme::first();
        $customer = \App\Domain\Master\Customer\Models\Customer::first();
        $reg = app(\App\Domain\Sales\Nup\Actions\RegisterNup::class)->execute([
            'nup_scheme_id' => $scheme->id,
            'customer_id'   => $customer->id,
        ]);

        // Pay
        $pay = app(\App\Domain\Sales\Nup\Actions\PayNup::class)->execute(
            $reg, 5_000_000, 'transfer', now()->toDateString(), 'TRX-NUP-001',
        );
        $reg->refresh();

        return "NUP {$reg->nup_number} queue #{$reg->queue_no} status={$reg->status}";
    }

    private function trialMemo(): string
    {
        $memo = app(\App\Domain\Finance\Memo\Actions\CreateMemo::class)->execute([
            'type'        => 'outgoing',
            'memo_date'   => now()->toDateString(),
            'amount'      => 5_000_000,
            'description' => 'Trial memo: biaya operasional',
        ]);
        return "Memo {$memo->memo_number} type={$memo->type->value}";
    }

    private function trialFollowup(): string
    {
        $customer = \App\Domain\Master\Customer\Models\Customer::first();
        $followup = app(\App\Domain\Collection\Followup\Actions\CreateFollowup::class)->execute([
            'customer_id'    => $customer->id,
            'channel'        => 'wa',
            'contacted_at'   => now(),
            'result'         => 'interested',
            'notes'          => 'Trial followup test',
            'next_action_at' => now()->addDays(3),
        ]);
        return "Followup #{$followup->id} channel={$followup->channel}";
    }

    private function trialKpr(): string
    {
        $booking = \App\Domain\Sales\Booking\Models\Booking::where('status', 'active')->first();
        if (! $booking) {
            throw new \RuntimeException('No active booking for KPR test');
        }
        $bank = \App\Domain\BankLoan\Models\BankPartner::first();
        $product = \App\Domain\BankLoan\Models\BankProduct::where('bank_partner_id', $bank->id)->first();

        $app = app(\App\Domain\BankLoan\Actions\CreateKprApplication::class)->execute([
            'booking_id'       => $booking->id,
            'customer_id'      => $booking->customer_id,
            'bank_partner_id'  => $bank->id,
            'bank_product_id'  => $product->id,
            'plafon_requested' => 600_000_000,
            'tenor_month'      => 180,
        ]);
        return "KPR {$app->application_number} bank={$bank->code} plafon=Rp ".number_format((float) $app->plafon_requested, 0, ',', '.');
    }

    private function trialSp3k(): string
    {
        $app = \App\Domain\BankLoan\Models\KprApplication::orderByDesc('id')->first();
        if (! $app) {
            throw new \RuntimeException('No KPR application');
        }
        // Pastikan KPR berada di state InReview sebelum SP3K register.
        // Path: Draft → Submitted → InReview
        if ($app->status === \App\Domain\BankLoan\Enums\KprStatus::Draft) {
            $app->update(['status' => \App\Domain\BankLoan\Enums\KprStatus::Submitted, 'submitted_at' => now()->toDateString()]);
            $app->refresh();
        }
        if ($app->status === \App\Domain\BankLoan\Enums\KprStatus::Submitted) {
            $app->update(['status' => \App\Domain\BankLoan\Enums\KprStatus::InReview]);
            $app->refresh();
        }

        $sp3k = app(\App\Domain\BankLoan\Actions\RegisterSp3k::class)->execute($app, [
            'sp3k_number'     => 'SP3K-TRIAL-001',
            'sp3k_date'       => now()->toDateString(),
            'approved_amount' => 580_000_000,
            'interest_pct'    => 8.5,
            'tenor_month'     => 180,
            'expires_at'      => now()->addDays(30)->toDateString(),
        ]);
        return "SP3K {$sp3k->sp3k_number} approved=Rp ".number_format((float) $sp3k->approved_amount, 0, ',', '.');
    }

    private function trialAkad(): string
    {
        $app = \App\Domain\BankLoan\Models\KprApplication::orderByDesc('id')->first();
        $akad = app(\App\Domain\BankLoan\Actions\ScheduleAkad::class)->execute($app, [
            'akad_at' => now()->addDays(7),
            'place'   => 'Kantor Notaris X',
            'notary'  => 'Notaris Trial',
        ]);
        // Complete
        app(\App\Domain\BankLoan\Actions\CompleteAkad::class)->execute($app);
        $app->refresh();
        return "Akad scheduled+completed | status={$app->status->value}";
    }

    private function trialDisbursement(): string
    {
        $app = \App\Domain\BankLoan\Models\KprApplication::orderByDesc('id')->first();
        $real = app(\App\Domain\BankLoan\Actions\RecordDisbursement::class)->execute($app, [
            'disbursed_at'     => now()->toDateString(),
            'disbursed_amount' => 580_000_000,
            'retention_amount' => 50_000_000,
            'reference_no'     => 'DISB-TRIAL-001',
        ]);
        return "Disbursement Rp ".number_format((float) $real->disbursed_amount, 0, ',', '.')." (retensi Rp ".number_format((float) $real->retention_amount, 0, ',', '.').")";
    }

    private function trialPpjb(): string
    {
        $contract = \App\Domain\Sales\Contract\Models\Contract::first();
        if (! $contract) {
            throw new \RuntimeException('No contract');
        }
        if ($contract->status !== 'signed') {
            $contract->update(['status' => 'signed', 'signed_at' => now()->toDateString()]);
        }
        $ppjb = app(\App\Domain\Legal\Ppjb\Actions\IssuePpjb::class)->execute($contract);
        return "PPJB {$ppjb->ppjb_number}";
    }

    private function trialAjb(): string
    {
        // Cari booking yang sudah lunas (semua schedules paid). Untuk trial, manual mark.
        $booking = \App\Domain\Sales\Booking\Models\Booking::first();

        // Mark all schedules paid agar AJB bisa di-issue
        \App\Domain\Sales\Booking\Models\PaymentSchedule::where('booking_id', $booking->id)
            ->update(['paid_amount' => DB::raw('amount'), 'status' => 'paid', 'paid_at' => now()]);

        $ajb = app(\App\Domain\Legal\Ajb\Actions\IssueAjb::class)->execute($booking, 'Notaris Trial', now()->toDateString());
        return "AJB {$ajb->ajb_number} notary={$ajb->notary_name}";
    }

    private function trialBast(): string
    {
        $booking = \App\Domain\Sales\Booking\Models\Booking::first();
        $bast = app(\App\Domain\Legal\Bast\Actions\IssueBast::class)->execute(
            $booking, now()->toDateString(),
            ['kunci_diterima' => true, 'unit_kondisi_baik' => true, 'utilitas_aktif' => true],
        );
        return "BAST {$bast->bast_number}";
    }

    private function trialImb(): string
    {
        $unit = \App\Domain\Master\Unit\Models\Unit::first();
        $imb = \App\Domain\Legal\Imb\Models\ImbDocument::create([
            'unit_id'    => $unit->id,
            'imb_number' => 'IMB-TRIAL-'.now()->format('Ymd'),
            'issued_at'  => now()->toDateString(),
        ]);
        return "IMB {$imb->imb_number} for unit {$unit->unit_code}";
    }

    private function trialSertifikat(): string
    {
        $unit = \App\Domain\Master\Unit\Models\Unit::first();
        $customer = \App\Domain\Master\Customer\Models\Customer::first();
        $cert = \App\Domain\Legal\Sertifikat\Models\SertifikatDocument::create([
            'unit_id'           => $unit->id,
            'owner_customer_id' => $customer->id,
            'type'              => 'SHM',
            'serial_number'     => 'SHM-TRIAL-'.now()->format('Ymd'),
            'issued_at'         => now()->toDateString(),
        ]);
        return "Sertifikat {$cert->type} {$cert->serial_number}";
    }

    private function trialSp1(): string
    {
        $tunggakan = \App\Domain\Collection\Tunggakan\Models\TunggakanHeader::first();
        if (! $tunggakan) {
            throw new \RuntimeException('No tunggakan to issue SP for');
        }
        $sp = app(\App\Domain\Collection\SuratPeringatan\Actions\IssueSp::class)
            ->execute($tunggakan, 'SP1', 14);
        return "SP1 {$sp->sp_number} deadline={$sp->deadline_at->format('Y-m-d')}";
    }

    private function trialCommission(): string
    {
        $booking = \App\Domain\Sales\Booking\Models\Booking::first();
        // Need agent
        $agent = DB::table('agents')->first();
        if (! $agent) {
            // Create dummy agent
            $agentType = DB::table('agent_types')->where('code', 'POKOK')->first();
            $agentId = DB::table('agents')->insertGetId([
                'tenant_id'     => 1,
                'code'          => 'AG-TRIAL',
                'name'          => 'Agent Trial',
                'agent_type_id' => $agentType->id,
                'is_active'     => true,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        } else {
            $agentId = $agent->id;
        }
        $booking->update(['agent_id' => $agentId]);

        $commission = app(\App\Domain\Commission\Services\CommissionCalculator::class)->calculate($booking);
        $linesCount = $commission->lines()->count();
        return "Commission #{$commission->id} | {$linesCount} lines for booking {$booking->booking_number}";
    }

    private function trialFakturPajak(): string
    {
        $customer = \App\Domain\Master\Customer\Models\Customer::first();
        $booking = \App\Domain\Sales\Booking\Models\Booking::first();
        $fp = app(\App\Domain\Finance\FakturPajak\Actions\CreateFakturPajak::class)->execute(
            [
                'customer_id' => $customer->id,
                'booking_id'  => $booking->id,
                'issued_at'   => now()->toDateString(),
                'ppn_pct'     => 11,
            ],
            [
                ['description' => 'Unit C-A-01 Java Residence', 'qty' => 1, 'unit_price' => 850_000_000, 'total' => 850_000_000],
            ],
        );
        return "FP {$fp->fp_number} total=Rp ".number_format((float) $fp->total, 0, ',', '.');
    }

    private function trialCashback(): string
    {
        $booking = \App\Domain\Sales\Booking\Models\Booking::first();
        $cb = \App\Domain\Finance\Cashback\Models\Cashback::create([
            'booking_id' => $booking->id,
            'amount'     => 5_000_000,
            'reason'     => 'Trial cashback',
            'status'     => 'pending',
        ]);

        // Approve
        $cb->update(['status' => 'approved']);

        return "Cashback #{$cb->id} status={$cb->status}";
    }

    private function trialVa(): string
    {
        $customer = \App\Domain\Master\Customer\Models\Customer::first();
        $bank = \App\Domain\BankLoan\Models\BankPartner::first();
        $va = app(\App\Domain\Finance\VirtualAccount\Actions\AssignVa::class)
            ->execute($customer->id, $bank->id, '8808-9999-001-TRIAL');
        return "VA {$va->va_number} for customer #{$customer->id}";
    }

    private function trialSettingsUser(): string
    {
        // Create test user
        $u = \App\Models\User::firstOrCreate(
            ['email' => 'trial-user@mup.local'],
            [
                'tenant_id'         => 1,
                'name'              => 'Trial User',
                'password'          => bcrypt('Trial@123'),
                'is_active'         => true,
                'email_verified_at' => now(),
            ],
        );
        $u->syncRoles(['Viewer']);
        return "User #{$u->id} | {$u->email} | role: Viewer";
    }

    private function trialParameter(): string
    {
        $p = DB::table('parameters')->where('group', 'tax')->where('key', 'ppn_pct')->first();
        $originalValue = $p->value;
        DB::table('parameters')->where('id', $p->id)->update(['value' => '11.5', 'updated_at' => now()]);
        $newP = DB::table('parameters')->where('id', $p->id)->first();
        // Restore
        DB::table('parameters')->where('id', $p->id)->update(['value' => $originalValue]);
        return "Parameter tax.ppn_pct {$originalValue} → 11.5 → restored";
    }

    private function trialNumerator(): string
    {
        $svc = app(\App\Domain\Foundation\Numerator\Services\NumeratorService::class);
        $next = $svc->preview('BOOK');
        $nextCust = $svc->preview('CUST');
        return "Preview BOOK={$next} | CUST={$nextCust}";
    }

    private function trialGn(): string
    {
        $booking = \App\Domain\Sales\Booking\Models\Booking::first();
        $suffix = (string) now()->format('His');
        $newCustomer = \App\Domain\Master\Customer\Models\Customer::firstOrCreate(
            ['tenant_id' => 1, 'code' => "CUST-GN-{$suffix}"],
            [
                'full_name'  => "New Owner GN {$suffix}",
                'id_type'    => 'KTP',
                'id_number'  => "327507180009{$suffix}",
                'created_by' => auth()->id(),
            ],
        );

        // Make sure schedules tidak overdue
        \App\Domain\Sales\Booking\Models\PaymentSchedule::where('booking_id', $booking->id)
            ->where('status', 'overdue')->update(['status' => 'outstanding']);

        $req = app(\App\Domain\Sales\Exception\NameChange\Actions\RequestNameChange::class)
            ->execute($booking, $newCustomer->id, 1_000_000);
        return "GN request #{$req->id} approval_id={$req->approval_id} status={$req->status}";
    }

    private function trialReschedule(): string
    {
        $booking = \App\Domain\Sales\Booking\Models\Booking::first();
        $sch = $booking->paymentSchedules()->first();
        $req = app(\App\Domain\Sales\Exception\Reschedule\Actions\RequestReschedule::class)
            ->execute($booking, [
                ['id' => $sch->id, 'new_due_date' => now()->addDays(60)->toDateString()],
            ], 500_000);
        return "Reschedule request #{$req->id} approval_id={$req->approval_id}";
    }
}
