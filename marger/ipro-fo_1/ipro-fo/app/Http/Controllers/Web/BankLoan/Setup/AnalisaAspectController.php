<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan\Setup;

use App\Domain\BankLoan\Models\KprAnalisaAspect;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting KPR → Kategori Analisa Berkas: konfigurasi aspek scoring HIGH/MEDIUM/LOW
 * (label, hint, nilai skor indikator, & skor rekomendasi bank) untuk tahap Analisa BI Checking & Berkas.
 */
class AnalisaAspectController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.setup.view'), 403);

        return Inertia::render('BankLoan/Setup/AnalisaBerkas/Index', [
            'aspects' => KprAnalisaAspect::orderBy('aspect_no')->orderBy('id')->get(),
            'scopes'  => KprAnalisaAspect::SCOPES,
            'levels'  => KprAnalisaAspect::LEVELS,
            'banks'   => KprAnalisaAspect::BANKS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $data = $this->validated($request);
        $tenantId = tenant_id() ?? default_tenant_id();

        $base = 'a_'.(Str::slug(Str::limit($data['label'], 40, ''), '_') ?: 'aspek');
        $key = $base;
        $n = 1;
        while (KprAnalisaAspect::where('tenant_id', $tenantId)->where('aspect_key', $key)->exists()) {
            $key = $base.'_'.(++$n);
        }

        KprAnalisaAspect::create([
            'aspect_key' => $key,
            'aspect_no'  => $data['aspect_no'] ?? ((int) KprAnalisaAspect::max('aspect_no') + 1),
            'label'      => $data['label'],
            'scope'      => $data['scope'],
            'is_active'  => $data['is_active'] ?? true,
            'options'    => $this->normalizeOptions($data['options']),
        ]);

        return back()->with('success', "Aspek analisa \"{$data['label']}\" ditambahkan.");
    }

    public function update(Request $request, KprAnalisaAspect $aspect): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $data = $this->validated($request);
        // aspect_key TIDAK diubah (kunci penyimpanan penilaian pemohon), hanya konten.
        $aspect->update([
            'aspect_no' => $data['aspect_no'] ?? $aspect->aspect_no,
            'label'     => $data['label'],
            'scope'     => $data['scope'],
            'is_active' => $data['is_active'] ?? true,
            'options'   => $this->normalizeOptions($data['options']),
        ]);

        return back()->with('success', "Aspek analisa \"{$aspect->label}\" diperbarui.");
    }

    public function destroy(Request $request, KprAnalisaAspect $aspect): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.setup.manage'), 403);

        $label = $aspect->label;
        $aspect->delete();

        return back()->with('success', "Aspek analisa \"{$label}\" dihapus.");
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label'             => ['required', 'string', 'max:255'],
            'scope'             => ['required', Rule::in(array_keys(KprAnalisaAspect::SCOPES))],
            'aspect_no'         => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active'         => ['boolean'],
            'options'           => ['required', 'array', 'size:3'],
            'options.*.level'   => ['required', Rule::in(array_keys(KprAnalisaAspect::LEVELS))],
            'options.*.desc'    => ['required', 'string', 'max:500'],
            'options.*.hint'    => ['nullable', 'string', 'max:120'],
            'options.*.score'   => ['required', 'integer', 'min:0', 'max:100'],
            'options.*.bank'    => ['required', 'array', 'size:5'],
            'options.*.bank.*'  => ['required', 'integer', 'min:0', 'max:100'],
        ]);
    }

    /** Rapikan struktur opsi + pastikan urutan high→medium→low. @param array<int,array<string,mixed>> $options */
    private function normalizeOptions(array $options): array
    {
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($options, fn ($a, $b) => ($order[$a['level']] ?? 9) <=> ($order[$b['level']] ?? 9));

        return array_map(fn ($o) => [
            'level' => $o['level'],
            'desc'  => (string) $o['desc'],
            'hint'  => ($o['hint'] ?? null) !== '' ? ($o['hint'] ?? null) : null,
            'score' => (int) $o['score'],
            'bank'  => array_map('intval', array_values($o['bank'])),
        ], $options);
    }
}
