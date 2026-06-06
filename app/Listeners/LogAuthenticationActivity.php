<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Request;

/**
 * Mencatat aktivitas autentikasi semua user ke Audit Log (Spatie Activitylog,
 * log_name = "auth"): login, logout, gagal login, dan akun terkunci.
 *
 * Inilah yang membuat menu Audit Log mulai terisi begitu ada user keluar/masuk.
 */
class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        // Catat jam & IP login terakhir — dipakai juga oleh halaman Audit User.
        // saveQuietly() agar tidak memicu activity "User diubah" yang berlebihan.
        if ($event->user instanceof User) {
            $event->user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => Request::ip(),
            ])->saveQuietly();
        }

        activity('auth')
            ->causedBy($event->user)
            ->withProperties(['ip' => Request::ip(), 'guard' => $event->guard])
            ->event('login')
            ->log('User masuk (login)');
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->withProperties(['ip' => Request::ip()])
            ->event('logout')
            ->log('User keluar (logout)');
    }

    public function handleFailed(Failed $event): void
    {
        activity('auth')
            ->withProperties([
                'email' => $event->credentials['email'] ?? null,
                'ip' => Request::ip(),
            ])
            ->event('failed')
            ->log('Gagal login');
    }

    public function handleLockout(Lockout $event): void
    {
        activity('auth')
            ->withProperties([
                'email' => $event->request->input('email'),
                'ip' => $event->request->ip(),
            ])
            ->event('lockout')
            ->log('Akun terkunci sementara (terlalu banyak percobaan login)');
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
        ];
    }
}
