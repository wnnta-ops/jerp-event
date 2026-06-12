<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationActivity;
use App\Modules\FO\Launching\Events\NupRegistered;
use App\Modules\FO\Launching\Listeners\KirimKonfirmasiNupListener;
use App\Modules\FO\Shared\Integrations\WhatsApp\NullWhatsAppGateway;
use App\Modules\FO\Shared\Integrations\WhatsApp\QontakWhatsAppGateway;
use App\Modules\FO\Shared\Integrations\WhatsApp\WhatsAppGatewayInterface;
use App\Modules\FO\Shared\Integrations\Xendit\NullXenditGateway;
use App\Modules\FO\Shared\Integrations\Xendit\XenditGateway;
use App\Modules\FO\Shared\Integrations\Xendit\XenditGatewayInterface;
use App\Services\Numerator\NumeratorService;
use App\Services\Numerator\NumeratorServiceInterface;
use App\Settings\BackupSettings;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->bind(NumeratorServiceInterface::class, NumeratorService::class);

        // Xendit: real gateway when a secret key is configured, offline fake otherwise.
        $this->app->bind(XenditGatewayInterface::class, function () {
            $secret = (string) config('services.xendit.secret_key');

            return $secret !== ''
                ? new XenditGateway($secret, (string) config('services.xendit.base_url'))
                : new NullXenditGateway;
        });

        // WhatsApp (Mekari Qontak): real gateway when an access token is configured.
        $this->app->bind(WhatsAppGatewayInterface::class, function () {
            $token = (string) config('services.qontak.access_token');

            return $token !== ''
                ? new QontakWhatsAppGateway(
                    accessToken: $token,
                    channelIntegrationId: (string) config('services.qontak.channel_integration_id'),
                    baseUrl: (string) config('services.qontak.base_url'),
                    languageCode: (string) config('services.qontak.language'),
                )
                : new NullWhatsAppGateway;
        });

        // Telescope is a dev-only dependency; register conditionally so production
        // (composer install --no-dev) still boots without the vendor present.
        if ($this->app->environment('local') && class_exists(TelescopeServiceProvider::class)) {
            $this->app->register(TelescopeServiceProvider::class);
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Audit autentikasi (login/logout/gagal/terkunci) → Audit Log
        Event::subscribe(LogAuthenticationActivity::class);

        // FO/Launching domain events
        Event::listen(NupRegistered::class, KirimKonfirmasiNupListener::class);

        // Throttle public NUP registration: 5 req/min/IP for the GET form,
        // but the submit (Livewire POST) is throttled at the same key.
        RateLimiter::for('nup-public', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        $this->applyBackupSettings();
    }

    /**
     * Terapkan Pengaturan Backup (lokasi + retensi) ke config Spatie Backup di
     * runtime, agar lokasi backup bisa diatur manual dari UI.
     */
    private function applyBackupSettings(): void
    {
        try {
            // Lokasi/retensi global Spatie Backup mengikuti jadwal AKTIF pertama
            // (BackupSchedule). Spatie pakai 1 config global, jadi semua jadwal
            // menulis ke lokasi yang sama (umumnya memang 1 folder backup).
            $s = \App\Models\BackupSchedule::query()->orderByDesc('enabled')->orderBy('id')->first();
            if (! $s) {
                return;
            }

            if ($s->disk === 'backup_target' && trim((string) $s->local_path) !== '') {
                config(['filesystems.disks.backup_target.root' => $s->local_path]);
            }
            config(['backup.backup.destination.disks' => [$s->disk]]);
            config(['backup.cleanup.default_strategy.keep_all_backups_for_all_days' => (int) $s->retention_days]);
        } catch (\Throwable) {
            // Tabel belum ada (sebelum migrate) — biarkan config Spatie pakai default.
        }
    }
}
