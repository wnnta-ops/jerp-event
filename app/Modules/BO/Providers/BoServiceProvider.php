<?php

namespace App\Modules\BO\Providers;

use App\Modules\BO\FoBoBridge\Listeners\PostFoEventToGl;
use App\Modules\BO\GeneralLedger\Contracts\JurnalPostingServiceInterface;
use App\Modules\BO\GeneralLedger\Services\JurnalPostingService;
use App\Modules\BO\IproBridge\Jobs\SyncFoToIproJob;
use App\Modules\BO\IproBridge\Services\IproMasterSync;
use App\Modules\FO\FinanceAR\Models\KprPengajuan;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the BO (Back Office / Accounting) module:
 * binds the GL posting service and registers the FO→BO event subscriber.
 */
class BoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(JurnalPostingServiceInterface::class, JurnalPostingService::class);
        $this->app->singleton(IproMasterSync::class);
    }

    public function boot(): void
    {
        // (DIPENSIUNKAN) FOBO bridge ke GL J-ERP lama (bo_jurnal). GL kini ke ipro-fo.
        // Event::listen(FoFinancialEvent::class, PostFoEventToGl::class);

        // FO → GL ipro-fo (ipro_bo.bs_journals). BO ipro-fo = back office baru.
        if (config('ipro.gl_enabled', true)) {
            Event::listen(FoFinancialEvent::class, \App\Modules\BO\IproBridge\Listeners\PostFoEventToIproGl::class);
        }

        // Sync OTOMATIS master FO → ipro_bo (BO ipro-fo). Saat data FO disimpan,
        // langsung ditulis ke ipro_bo. Dibungkus try/catch: kegagalan sync TIDAK
        // boleh menggagalkan simpan FO (backfill `ipro:backfill` merekonsiliasi).
        if (config('ipro.sync_enabled', true)) {
            $this->wireIproSync();
        }
    }

    private function wireIproSync(): void
    {
        // Sync ASINKRON: dispatch job SETELAH transaksi FO commit (afterCommit) →
        // tak menambah latensi simpan, retry + outbox bila gagal. QUEUE=sync pun aman.
        $dispatch = function (string $method, $model): void {
            SyncFoToIproJob::dispatch($method, $model::class, $model->getKey())->afterCommit();
        };

        Customer::saved(fn ($m) => $dispatch('syncCustomer', $m));
        Unit::saved(fn ($m) => $dispatch('syncUnit', $m));
        Kontrak::saved(fn ($m) => $dispatch('syncSpr', $m));
        Tts::saved(fn ($m) => $dispatch('syncTts', $m));
        KprPengajuan::saved(fn ($m) => $dispatch('syncKpr', $m));
    }
}
