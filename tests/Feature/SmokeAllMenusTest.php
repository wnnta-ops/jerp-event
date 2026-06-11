<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Smoke test menyeluruh: crawl SEMUA rute GET (semua panel FO/BO/BankLoan/Backup/System
 * + halaman publik) sebagai user aktif, pastikan tak ada yang 500. "Menu" = nav item =
 * rute index/halaman tanpa parameter. Rute ber-parameter (edit/detail) di-skip + dihitung.
 */
class SmokeAllMenusTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_menus_render_without_server_error(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        $skipPrefixes = ['_', 'livewire', 'horizon', 'telescope', 'pulse', 'sanctum', 'api', 'storage', 'up'];
        $failures = [];
        $tested = 0;
        $skippedParam = 0;
        $byArea = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            $uri = $route->uri();

            if (Str::startsWith($uri, $skipPrefixes)) {
                continue;
            }
            // Rute auth-redirect (login/logout/root/cluster landing) mengembalikan
            // Livewire Redirector — normal di browser, tapi GET sintetis test tak bisa
            // konversi → false-positive. Di-exclude.
            if ($uri === '' || $uri === 'login' || $uri === 'bo/general-ledger'
                || Str::endsWith($uri, '/login') || Str::endsWith($uri, '/logout')) {
                continue;
            }
            if (Str::contains($uri, '{')) {
                $skippedParam++;

                continue;
            }

            $area = explode('/', $uri)[0] ?: 'root';
            $byArea[$area] = ($byArea[$area] ?? 0) + 1;
            $tested++;

            try {
                $status = $this->get('/'.ltrim($uri, '/'))->status();
            } catch (\Throwable $e) {
                $failures[] = "/{$uri}  →  EXCEPTION: ".Str::limit($e->getMessage(), 180);

                continue;
            }

            if ($status >= 500) {
                $failures[] = "/{$uri}  →  HTTP {$status}";
            }
        }

        fwrite(STDERR, "\n=== SMOKE COVERAGE ===\n");
        fwrite(STDERR, "Rute GET diuji: {$tested}  | ber-parameter di-skip: {$skippedParam}\n");
        ksort($byArea);
        foreach ($byArea as $area => $n) {
            fwrite(STDERR, sprintf("  %-18s %d\n", $area, $n));
        }
        if ($failures) {
            fwrite(STDERR, "\n=== ".count($failures)." GAGAL (500/exception) ===\n".implode("\n", $failures)."\n");
        } else {
            fwrite(STDERR, "\n✅ Semua {$tested} menu render tanpa 500.\n");
        }

        $this->assertSame([], $failures, count($failures).' menu error (lihat detail di atas).');
    }
}
