<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        // Versi aset di-PIN ke nilai stabil. Default Inertia memakai hash manifest Vite yang
        // berubah tiap `npm run build`, sehingga tab yang sedang terbuka dipaksa full-reload
        // (terasa seperti "refresh ganda" saat klik Simpan). Dengan versi tetap, Save selalu
        // jadi satu transisi XHR yang mulus. Naikkan angka ini HANYA bila ingin memaksa semua
        // tab memuat ulang aset terbaru (mis. setelah rilis besar) — pengguna cukup hard-refresh.
        return 'v1';
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'name'  => config('app.name'),
            'auth'  => [
                'user' => fn () => $request->user() ? [
                    'id'         => $request->user()->id,
                    'name'       => $request->user()->name,
                    'email'      => $request->user()->email,
                    'roles'      => $request->user()->getRoleNames(),
                    'permissions'=> $request->user()->getAllPermissions()->pluck('name'),
                    'tenant_id'  => $request->user()->tenant_id,
                    'dark_mode'  => $request->user()->dark_mode,
                    'avatar_url' => $request->user()->avatar_path
                        ? asset('storage/'.$request->user()->avatar_path) : null,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info'    => fn () => $request->session()->get('info'),
                'created' => fn () => $request->session()->get('created'),
                // Hasil import massal: jumlah baru/diperbarui/dilewati + galat per baris.
                // Dibawa terpisah dari 'error' karena daftar galatnya bisa puluhan baris —
                // memampatkannya jadi satu kalimat toast akan menyembunyikan sebagian besarnya,
                // dan justru baris yang gagal itulah yang perlu dibetulkan operator.
                'import'  => fn () => $request->session()->get('import'),
            ],
            'config' => [
                'currency'   => config('domain.currency'),
                'timezone'   => config('domain.timezone'),
                'ppn_pct'    => config('domain.tax.ppn_default_pct'),
                'mfa_roles'  => config('domain.auth.mfa_required_roles'),
                'fo_url'     => config('sso.fo_url'), // link silang → FO J-ERP
            ],
            // CATATAN: Ziggy TIDAK lagi dishare di sini. Route list (~76 KB) sudah di-embed
            // sekali via @routes di app.blade.php → window.Ziggy; ZiggyVue (app.ts, tanpa argumen)
            // membacanya dari sana. Mengirim ulang tiap response Inertia hanya memboroskan ~76 KB/klik.
        ]);
    }
}
