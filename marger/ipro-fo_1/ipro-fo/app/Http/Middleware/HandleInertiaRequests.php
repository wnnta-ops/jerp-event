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
                    // + penanda 'kpr.approval.<tahap>' untuk tahap yang approver-nya mencantumkan user ini
                    //   (Set Up → Setup Approval KPR). Bukan izin Spatie — hanya agar menu KPR → Approval
                    //   memakai saringan sidebar yang sama; halaman & aksinya tetap diperiksa server.
                    'permissions'=> $request->user()->getAllPermissions()->pluck('name')
                        ->merge(rescue(fn () => array_map(fn ($s) => 'kpr.approval.'.$s,
                            app(\App\Domain\BankLoan\Services\KprPerubahanService::class)->tahapApprover((int) $request->user()->id)), [], false))
                        ->values(),
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
                // Pop-up Posting Invoice dari FO: invoice AP yang baru terbit (nomor bisa diklik).
                'apTerbit' => fn () => $request->session()->get('apTerbit'),
                // Hasil import massal: jumlah baru/diperbarui/dilewati + galat per baris.
                // Dibawa terpisah dari 'error' karena daftar galatnya bisa puluhan baris —
                // memampatkannya jadi satu kalimat toast akan menyembunyikan sebagian besarnya,
                // dan justru baris yang gagal itulah yang perlu dibetulkan operator.
                'import'  => fn () => $request->session()->get('import'),
                // Penolakan gerbang anggaran (OVER_BUDGET / BUDGET_TIDAK_ADA). Terpisah dari
                // 'error' karena layar memunculkannya sebagai DIALOG yang harus ditutup, bukan
                // toast yang lewat: angkanya perlu dibaca, dan tindakan lanjutannya berbeda —
                // minta tambahan anggaran, atau minta anggarannya dibuat.
                'budgetError' => fn () => $request->session()->get('budgetError'),
                // Penolakan Upload Budget: daftar baris yang salah. Dialog, bukan toast —
                // galatnya bisa puluhan baris dan justru baris itulah yang harus dibetulkan.
                'uploadError' => fn () => $request->session()->get('uploadError'),
            ],
            // Memo Urgent saya yang sudah dibayar tapi invoice hutangnya belum dibuat — pop-up sekali
            // tiap login (penanda diset saat login, dihapus saat pop-up ditutup / tidak ada isinya).
            'pengingatMemo' => fn () => $this->pengingatMemo($request),
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

    /** @return array{batas:int, rows:mixed}|null */
    private function pengingatMemo(Request $request): ?array
    {
        $kunci = \App\Domain\BusinessSupport\Services\PembayaranMemoUrgent::SESI_PENGINGAT;
        if (! $request->user() || ! $request->hasSession() || ! $request->session()->get($kunci)) {
            return null;
        }
        $svc = app(\App\Domain\BusinessSupport\Services\PembayaranMemoUrgent::class);
        $rows = rescue(fn () => $svc->outstanding($request->user()->id), collect(), report: false);
        if ($rows->isEmpty()) {
            $request->session()->forget($kunci);

            return null;
        }

        return ['batas' => $svc->batasHari(), 'rows' => $rows];
    }
}
