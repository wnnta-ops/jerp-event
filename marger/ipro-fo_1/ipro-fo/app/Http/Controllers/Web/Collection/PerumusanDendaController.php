<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Collection;

use App\Domain\Collection\Denda\Services\DendaConfig;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Perumusan Denda (eProperty Settings → Perumusan Denda).
 * Mengatur parameter rumus denda yang dipakai command billing:jatuh-tempo.
 */
class PerumusanDendaController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('collection.denda.view'), 403);

        return Inertia::render('Collection/Denda/Perumusan', [
            'config' => [
                'rate_pct_per_day' => round(DendaConfig::rate() * 100, 4), // multiplier → %
                'grace_days'       => DendaConfig::graceDays(),
                'max_pct'          => DendaConfig::maxPct(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('collection.denda.waive'), 403);
        $v = $request->validate([
            'rate_pct_per_day' => ['required', 'numeric', 'min:0', 'max:100'],
            'grace_days'       => ['required', 'integer', 'min:0', 'max:365'],
            'max_pct'          => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->save('rate_per_day', (string) ((float) $v['rate_pct_per_day'] / 100), 'decimal', 'Denda per Hari');
        $this->save('grace_days', (string) (int) $v['grace_days'], 'int', 'Hari Toleransi Denda');
        $this->save('max_pct', (string) (float) $v['max_pct'], 'decimal', 'Cap Maksimum Denda (%)');

        return back()->with('success', 'Perumusan denda disimpan. Berlaku pada perhitungan denda berikutnya.');
    }

    private function save(string $key, string $value, string $type, string $label): void
    {
        DB::table('parameters')->updateOrInsert(
            ['tenant_id' => tenant_id() ?? default_tenant_id(), 'group' => 'denda', 'key' => $key],
            ['value' => $value, 'data_type' => $type, 'label' => $label, 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
