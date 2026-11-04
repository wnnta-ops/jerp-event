<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! app()->isProduction());
        Model::preventLazyLoading(! app()->isProduction());

        Paginator::useTailwind();

        Carbon::setLocale('id');

        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Relation::enforceMorphMap([
            'customer'          => \App\Domain\Master\Customer\Models\Customer::class,
            'unit'              => \App\Domain\Master\Unit\Models\Unit::class,
            'booking'           => \App\Domain\Sales\Booking\Models\Booking::class,
            'contract'          => \App\Domain\Sales\Contract\Models\Contract::class,
            'tts'               => \App\Domain\Finance\Tts\Models\Tts::class,
            'kuitansi'          => \App\Domain\Finance\Kuitansi\Models\Kuitansi::class,
            'memo'              => \App\Domain\Finance\Memo\Models\Memo::class,
            'user'              => \App\Models\User::class,
            'approval'          => \App\Domain\Approval\Models\Approval::class,
            'nup_registration'  => \App\Domain\Sales\Nup\Models\NupRegistration::class,
            'kpr_application'   => \App\Domain\BankLoan\Models\KprApplication::class,
            'cashback'          => \App\Domain\Finance\Cashback\Models\Cashback::class,
            'commission'        => \App\Domain\Commission\Models\Commission::class,
            'commission_payout' => \App\Domain\Commission\Models\CommissionPayout::class,
            'name_change'       => \App\Domain\Sales\Exception\NameChange\Models\NameChangeRequest::class,
            'unit_change'       => \App\Domain\Sales\Exception\UnitChange\Models\UnitChangeRequest::class,
            'reschedule'        => \App\Domain\Sales\Exception\Reschedule\Models\RescheduleRequest::class,
            'surat_peringatan'  => \App\Domain\Collection\SuratPeringatan\Models\SuratPeringatan::class, // #118 approval finance
            'memo_internal'     => \App\Domain\ApprovalInbox\Models\MemoInternal::class, // Approval Inbox
            'ap_berkas_upload'  => \App\Domain\BusinessSupport\Models\BsApBerkasUpload::class, // Upload Berkas → TTD Dept Head
            'ap_invoice'        => \App\Domain\BusinessSupport\Models\BsApInvoice::class,      // approval invoice dari berkas
            'budget_correction' => \App\Domain\BusinessSupport\Models\BsBudgetCorrection::class, // koreksi budget dari invoice hutang
            'budget_allocation' => \App\Domain\BusinessSupport\Models\BsBudgetAllocation::class, // alokasi budget dari invoice hutang

            // Phase 3 — Payment polymorphic sources
            'tts_receipt'       => \App\Domain\Finance\Tts\Models\Tts::class,
            'kpr_disbursement'  => \App\Domain\BankLoan\Models\KprDisbursement::class,
            'payment_allocation'=> \App\Domain\Finance\Payment\Models\PaymentAllocation::class,

            // Phase 7 — CRM Activity Log
            'activity_log'      => \App\Domain\Crm\Models\ActivityLog::class,
        ]);

        // Rate limiters
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(600)->by(optional($request->user())->id ?: $request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('web', fn (Request $request) => Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip()));
    }
}
