<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationActivity;
use App\Models\BackupSchedule;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Launching\Events\NupRegistered;
use App\Modules\FO\Launching\Listeners\KirimKonfirmasiNupListener;
use App\Modules\FO\Shared\Integrations\Ai\AiAssistantGatewayInterface;
use App\Modules\FO\Shared\Integrations\Ai\ClaudeAiGateway;
use App\Modules\FO\Shared\Integrations\Ai\GeminiAiGateway;
use App\Modules\FO\Shared\Integrations\Ai\NullAiAssistantGateway;
use App\Modules\FO\Shared\Integrations\WhatsApp\NullWhatsAppGateway;
use App\Modules\FO\Shared\Integrations\WhatsApp\QontakWhatsAppGateway;
use App\Modules\FO\Shared\Integrations\WhatsApp\WhatsAppGatewayInterface;
use App\Modules\FO\Shared\Integrations\Xendit\NullXenditGateway;
use App\Modules\FO\Shared\Integrations\Xendit\XenditGateway;
use App\Modules\FO\Shared\Integrations\Xendit\XenditGatewayInterface;
use App\Policies\ApprovalPolicy;
use App\Services\Numerator\NumeratorService;
use App\Services\Numerator\NumeratorServiceInterface;
use App\Support\Rupiah;
use App\Support\TenantContext;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // HTTPS dipaksa di fase register(), BUKAN boot(): PanelProvider Filament
        // memanggil panel() (→ favicon(asset(...))) saat register, sebelum boot()
        // mana pun jalan & sebelum TrustProxies dipasang. Kalau di boot() → URL
        // favicon terkunci http:// dan diblokir browser sebagai mixed content.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Guard GLOBAL anti-"resurrection" baris repeater (bug Livewire 3.8). WAJIB di
        // fase register() agar masuk daftar SEBELUM Livewire mem-boot ComponentHookRegistry
        // (kalau di boot() → terlambat, hook tak pernah dipasang). Lihat App\Support\Livewire.
        \Livewire\ComponentHookRegistry::register(\App\Support\Livewire\RepeaterResurrectionGuard::class);

        $this->app->singleton(TenantContext::class);
        $this->app->bind(NumeratorServiceInterface::class, NumeratorService::class);

        // Xendit: real gateway when a secret key is configured, offline fake otherwise.
        $this->app->bind(XenditGatewayInterface::class, function () {
            $secret = (string) config('services.xendit.secret_key');

            return $secret !== ''
                ? new XenditGateway($secret, (string) config('services.xendit.base_url'))
                : new NullXenditGateway;
        });

        // Asisten AI: pilih provider (Claude / Gemini) sesuai key + AI_PROVIDER,
        // fallback kata kunci sederhana bila tak ada key (modul tetap jalan).
        $this->app->bind(AiAssistantGatewayInterface::class, function () {
            $provider = strtolower((string) config('services.ai.provider', 'auto'));
            $anthropicKey = (string) config('services.anthropic.api_key');
            $geminiKey = (string) config('services.gemini.api_key');

            $wantClaude = $provider === 'claude' || ($provider === 'auto' && $anthropicKey !== '');
            $wantGemini = $provider === 'gemini' || ($provider === 'auto' && $anthropicKey === '' && $geminiKey !== '');

            if ($wantClaude && $anthropicKey !== '') {
                return new ClaudeAiGateway(
                    apiKey: $anthropicKey,
                    model: (string) config('services.anthropic.model'),
                    baseUrl: (string) config('services.anthropic.base_url'),
                    version: (string) config('services.anthropic.version'),
                    maxTokens: (int) config('services.anthropic.max_tokens'),
                );
            }

            if ($wantGemini && $geminiKey !== '') {
                return new GeminiAiGateway(
                    apiKey: $geminiKey,
                    model: (string) config('services.gemini.model'),
                    baseUrl: (string) config('services.gemini.base_url'),
                    maxTokens: (int) config('services.gemini.max_tokens'),
                );
            }

            return new NullAiAssistantGateway;
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
        // Audit autentikasi (login/logout/gagal/terkunci) → Audit Log
        Event::subscribe(LogAuthenticationActivity::class);

        // Webhook Survei: perubahan SPR → aplikasi Survei Kepuasan Customer (mati bila
        // SURVEI_WEBHOOK_URL/SECRET kosong). Lihat App\Modules\FO\Survei.
        \App\Modules\FO\MarketingSales\Models\Kontrak::observe(\App\Modules\FO\Survei\Observers\SprWebhookObserver::class);
        \App\Modules\FO\MarketingSales\Models\Customer::observe(\App\Modules\FO\Survei\Observers\SprWebhookObserver::class);
        \App\Modules\FO\MarketingSales\Models\Unit::observe(\App\Modules\FO\Survei\Observers\SprWebhookObserver::class);

        // FO/Launching domain events
        Event::listen(NupRegistered::class, KirimKonfirmasiNupListener::class);

        // Kwitansi pembayaran (DP/angsuran/pelunasan) ke customer setelah BKM tersimpan.
        // UTJ yang TTS-nya skip_gl_posting sudah dapat Kwitansi UTJ saat bayar → dilewati.
        // Data migrasi (SQL langsung) tak memicu event ini.
        // Dibungkus try: notifikasi tidak boleh menggagalkan pencatatan pembayaran.
        \App\Modules\FO\FinanceAR\Models\Bkm::created(function ($bkm) {
            try {
                if ($bkm->status !== 'POSTED' || ($bkm->tts_id && $bkm->tts?->skip_gl_posting)) {
                    return;
                }
                if (! \App\Modules\FO\Komunikasi\Models\TemplatePesan::query()
                    ->where('jenis', \App\Modules\FO\Komunikasi\Support\JenisPesan::KWITANSI_BAYAR)->where('is_active', true)->exists()) {
                    return;
                }
                \App\Modules\FO\Komunikasi\Jobs\KirimKwitansiBayarJob::dispatch($bkm->id)->afterCommit();
            } catch (\Throwable $e) {
                report($e);
            }
        });

        // Otorisasi Approval (model di luar App\Models → daftarkan eksplisit).
        Gate::policy(Approval::class, ApprovalPolicy::class);

        // Throttle public NUP registration: 5 req/min/IP for the GET form,
        // but the submit (Livewire POST) is throttled at the same key.
        RateLimiter::for('nup-public', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // Throttle API mobile: per-user (token) bila ada, jatuh ke IP.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Throttle ketat untuk login mobile (anti brute-force).
        RateLimiter::for('mobile-login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        $this->applyBackupSettings();
        $this->registerRupiahMacro();
    }

    /**
     * Macro TextInput::rupiah() — format ribuan gaya Indonesia (488.600.000) pada
     * semua field uang: prefix Rp, mask ribuan, bersihkan titik saat simpan, dan
     * normalkan nilai DB (.00) saat muat. Dipakai lintas panel (FO/BO/BankLoan).
     */
    private function registerRupiahMacro(): void
    {
        TextInput::macro('rupiah', function () {
            /** @var TextInput $this */
            return $this
                ->prefix('Rp')
                ->mask(RawJs::make("\$money(\$input, ',', '.', 0)"))
                ->stripCharacters('.')
                ->formatStateUsing(fn ($state) => Rupiah::formatState($state));
        });

        // Field tarif yang MENGIKUTI toggle Tipe Tarif (PERSEN/RP):
        //  - RP     → prefix Rp + mask ribuan (seperti rupiah())
        //  - PERSEN → suffix % + angka biasa (boleh desimal)
        // Toggle-nya wajib ->live() agar format ikut berubah saat dipilih.
        TextInput::macro('rupiahPersen', function (string $toggle = 'tipe_tarif', string $rpValue = 'RP') {
            /** @var TextInput $this */
            $isRp = fn (\Filament\Forms\Get $get): bool => $get($toggle) === $rpValue;

            return $this
                ->prefix(fn (\Filament\Forms\Get $get) => $isRp($get) ? 'Rp' : null)
                ->suffix(fn (\Filament\Forms\Get $get) => $isRp($get) ? null : '%')
                ->mask(fn (\Filament\Forms\Get $get) => $isRp($get) ? RawJs::make("\$money(\$input, ',', '.', 0)") : null)
                ->dehydrateStateUsing(fn ($state, \Filament\Forms\Get $get) => $isRp($get)
                    ? (float) str_replace('.', '', (string) $state)
                    : (float) str_replace(',', '.', (string) $state))
                ->formatStateUsing(fn ($state, \Filament\Forms\Get $get) => $isRp($get)
                    ? Rupiah::formatState($state)
                    : (rtrim(rtrim(number_format((float) $state, 2, '.', ''), '0'), '.') ?: '0'));
        });
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
            $s = BackupSchedule::query()->orderByDesc('enabled')->orderBy('id')->first();
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
