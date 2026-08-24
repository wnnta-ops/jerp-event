<?php

namespace App\Console\Commands\Akses;

use App\Models\User;
use App\Support\Akses;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Potret keadaan hak akses. TIDAK mengubah apa pun.
 *
 * Dijalankan lebih dulu sebelum penegakan dinyalakan: menyalakan penegakan tanpa
 * mengetahui peta izinnya berarti mengunci pengguna pada hari kerja berikutnya,
 * dan pemulihannya adalah mematikan seluruh penegakan — kembali ke titik nol.
 */
class AuditAksesCommand extends Command
{
    protected $signature = 'akses:audit {--peran= : Tampilkan detail izin satu peran}';

    protected $description = 'Laporkan keadaan peran, izin, gerbang panel, dan resource yang belum berpenjaga.';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=yellow>MODE</fg=yellow> : '.(Akses::tegakkan()
            ? '<fg=red>TEGAK</fg=red> — pemeriksaan yang gagal DITOLAK'
            : '<fg=green>LAPOR</fg=green> — yang gagal hanya dicatat, tidak ditolak'));

        if ($peran = $this->option('peran')) {
            return $this->detailPeran($peran);
        }

        $this->ringkasan();
        $this->gerbangPanel();
        $this->peranTanpaIzin();
        $this->resourceTanpaPenjaga();
        $this->penggunaBerisiko();
        $this->ruteBerisiko();

        $this->newLine();
        $this->line('  Detail satu peran: <fg=cyan>php artisan akses:audit --peran=sales_staff</fg=cyan>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function ringkasan(): void
    {
        $this->newLine();
        $this->line('  <options=bold>RINGKASAN</>');
        $this->table(['Hal', 'Jumlah'], [
            ['Pengguna aktif', User::where('is_active', true)->count()],
            ['Peran', Role::count()],
            ['Izin terdaftar', Permission::count()],
            ['Resource dipetakan', count((array) config('akses.resource'))],
        ]);
    }

    private function gerbangPanel(): void
    {
        $baris = [];
        foreach ((array) config('akses.panel') as $panel => $izin) {
            $ada = Permission::where('name', $izin)->exists();
            $pemilik = $ada
                ? Role::whereHas('permissions', fn ($q) => $q->where('name', $izin))->pluck('name')->implode(', ')
                : '—';
            $baris[] = [$panel, $izin, $ada ? 'ada' : 'BELUM DIBUAT', $pemilik ?: '(tak ada peran)'];
        }
        $this->line('  <options=bold>GERBANG PANEL</>');
        $this->table(['Panel', 'Izin', 'Status izin', 'Peran yang memilikinya'], $baris);
    }

    private function peranTanpaIzin(): void
    {
        $kosong = Role::withCount('permissions')->get()
            ->filter(fn ($r) => $r->permissions_count === 0)
            ->pluck('name');

        $this->line('  <options=bold>PERAN TANPA SATU PUN IZIN</> — akan kehilangan semua akses saat mode TEGAK');
        $this->line($kosong->isEmpty()
            ? '  (tidak ada)'
            : '  <fg=red>'.$kosong->count().' peran</fg=red> : '.$kosong->implode(', '));
        $this->newLine();
    }

    private function resourceTanpaPenjaga(): void
    {
        $dipetakan = array_keys((array) config('akses.resource'));
        $semua = [];
        foreach ((array) glob(app_path('Filament/*/Resources/*.php')) as $berkas) {
            $semua[] = basename($berkas, '.php');
        }
        $belum = array_values(array_diff($semua, $dipetakan));

        $this->line('  <options=bold>RESOURCE BELUM DIPETAKAN</> — dibiarkan lolos, tidak ditegakkan');
        $this->line('  <fg=yellow>'.count($belum).'</fg=yellow> dari '.count($semua).' resource');
        if ($belum) {
            $this->line('  '.implode(', ', array_slice($belum, 0, 20)).(count($belum) > 20 ? ' … (+'.(count($belum) - 20).')' : ''));
        }
        $this->newLine();
    }

    private function penggunaBerisiko(): void
    {
        $baris = [];
        foreach (User::where('is_active', true)->with('roles')->get() as $u) {
            $jml = $u->getAllPermissions()->count();
            if ($jml === 0) {
                $baris[] = [$u->id, $u->email, $u->getRoleNames()->implode(', ') ?: '(tanpa peran)', $jml];
            }
        }
        $this->line('  <options=bold>PENGGUNA AKTIF TANPA IZIN APA PUN</> — akan terkunci total saat mode TEGAK');
        if (! $baris) {
            $this->line('  (tidak ada)');
        } else {
            $this->line('  <fg=red>'.count($baris).' pengguna</fg=red>');
            $this->table(['ID', 'Email', 'Peran', 'Izin'], array_slice($baris, 0, 15));
            if (count($baris) > 15) {
                $this->line('  … dan '.(count($baris) - 15).' lainnya');
            }
        }
    }

    /**
     * Rute yang menerima id di URL tetapi tidak dijaga apa pun.
     *
     * Inilah pola yang membuat celah SPR Bank: `auth` saja hanya menjawab "sudah
     * login?", bukan "berhak atas id ini?". Yang perlu diperiksa adalah rute
     * ber-parameter tanpa `signed` maupun `auth` sama sekali.
     */
    private function ruteBerisiko(): void
    {
        $baris = [];
        foreach (app('router')->getRoutes() as $rute) {
            $uri = $rute->uri();
            if (! str_contains($uri, '{') || str_starts_with($uri, '_')) {
                continue;
            }
            // Rute panel Filament dijaga authMiddleware milik panelnya, dan itu
            // TIDAK muncul di gatherMiddleware(). Tanpa pengecualian ini, laporan
            // dipenuhi 60 alarm palsu dan temuan aslinya tenggelam.
            $nama = (string) $rute->getName();
            if (str_starts_with($nama, 'filament.') || str_contains($uri, 'livewire')) {
                continue;
            }
            // Horizon punya gerbangnya sendiri (viewHorizon) lewat middleware-nya.
            if (str_starts_with($nama, 'horizon.')) {
                continue;
            }
            if (in_array($nama, (array) config('akses.rute_dikecualikan', []), true)) {
                continue;
            }

            $mw = $rute->gatherMiddleware();
            $adaAuth = (bool) array_filter($mw, fn ($m) => is_string($m) && str_starts_with($m, 'auth'));
            $adaSigned = in_array('signed', $mw, true);

            if ($adaAuth || $adaSigned) {
                continue;
            }

            $baris[] = [implode('|', $rute->methods()), $uri, $rute->getName() ?: '—'];
        }

        $this->line('  <options=bold>RUTE BER-PARAMETER TANPA auth MAUPUN signed</>');
        if (! $baris) {
            $this->line('  (tidak ada)');
        } else {
            $this->line('  <fg=yellow>'.count($baris).' rute</fg=yellow> — periksa apakah id-nya bisa ditukar orang lain');
            $this->table(['Metode', 'URI', 'Nama'], array_slice($baris, 0, 20));
            if (count($baris) > 20) {
                $this->line('  … dan '.(count($baris) - 20).' lainnya');
            }
        }
        $this->newLine();
    }

    private function detailPeran(string $peran): int
    {
        $r = Role::where('name', $peran)->first();
        if (! $r) {
            $this->error("Peran '{$peran}' tidak ada.");

            return self::FAILURE;
        }
        $izin = $r->permissions->pluck('name')->sort()->values();
        $this->newLine();
        $this->line("  <options=bold>PERAN {$peran}</> — ".$izin->count().' izin, '
            .User::role($peran)->count().' pengguna');
        $this->line($izin->isEmpty() ? '  (tanpa izin)' : '  '.$izin->implode("\n  "));
        $this->newLine();

        return self::SUCCESS;
    }
}
