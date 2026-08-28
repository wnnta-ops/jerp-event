<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Domain\BusinessSupport\Support\BoDocumentNumbers;
use App\Domain\Foundation\Numerator\Models\Numerator;
use App\Domain\Foundation\Numerator\Services\NumeratorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Numerator Back Office.
 *
 * Terpisah dari layar Numerator (Front Office) karena keduanya memang dua daftar berbeda:
 * yang satu dokumen penjualan & legal, yang satu dokumen akuntansi. Dicampur, operator harus
 * memilah 52 baris untuk menemukan satu; dan kode yang kebetulan sama akan tampak kembar.
 */
class BoNumeratorController extends Controller
{
    public function __construct(private readonly NumeratorService $service) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('system.setup.numerator'), 403);

        $katalog = BoDocumentNumbers::all();

        $rows = Numerator::where('scope', BoDocumentNumbers::SCOPE)
            ->orderBy('code')->get()
            ->map(fn (Numerator $n) => [
                'id'             => $n->id,
                'code'           => $n->code,
                'prefix'         => $n->prefix,
                'format'         => $n->format,
                'seq_padding'    => (int) $n->seq_padding,
                'reset_strategy' => $n->reset_strategy,
                'current_period' => $n->current_period,
                'current_seq'    => (int) $n->current_seq,
                // Keterangan dari katalog. Kode yang ditambahkan operator sendiri belum ada
                // di katalog — dikatakan apa adanya, bukan disamarkan jadi dokumen dikenal.
                'label' => $katalog[$n->code]['label'] ?? null,
                'grup'  => $katalog[$n->code]['grup'] ?? 'Tambahan',
                // Peringatan khusus dokumen yang penghitungnya TIDAK di master ini.
                'catatan' => $katalog[$n->code]['catatan'] ?? null,
                'bawaan' => isset($katalog[$n->code]),
            ]);

        // Dokumen katalog yang barisnya belum ada — supaya ketahuan kalau ada yang terlewat
        // alih-alih diam-diam memakai format bawaan di kode.
        $belumAda = collect($katalog)
            ->reject(fn ($d, $code) => $rows->contains('code', $code))
            ->map(fn ($d, $code) => ['code' => $code, 'label' => $d['label'], 'grup' => $d['grup']])
            ->values();

        return Inertia::render('Settings/BoNumerators/Index', [
            'rows'      => $rows,
            'belumAda'  => $belumAda,
            'resets'    => ['never' => 'Tidak pernah', 'yearly' => 'Tiap tahun', 'monthly' => 'Tiap bulan', 'daily' => 'Tiap hari'],
            // Token dijelaskan di layar; format tanpa {SEQ} akan menghasilkan nomor kembar
            // selamanya, jadi operator perlu tahu artinya sebelum menyuntingnya.
            'tokens'    => [
                '{PREFIX}' => 'Prefix pada kolom sebelah', '{SEQ}' => 'Nomor urut (wajib ada)',
                '{YYYY}' => 'Tahun 4 digit — 2026', '{YY}' => 'Tahun 2 digit — 26',
                '{MM}' => 'Bulan 2 digit — 08', '{DD}' => 'Tanggal 2 digit — 27',
                '{MM_ROMAN}' => 'Bulan angka Romawi — VIII', '{MMM_ID}' => 'Bulan singkat — Agu',
                '{KATEGORI}' => 'Kode kategori (khusus Invoice Hutang)',
                '{PROJ}' => 'Kode proyek (khusus dokumen Budget Control)',
            ],
        ]);
    }

    public function preview(Request $request, string $code): JsonResponse
    {
        // Layar Numerator Front Office LUPA menjaga endpoint ini, sehingga siapa pun yang
        // login bisa membaca format & nomor berikutnya. Di sini dijaga sama dengan index.
        abort_unless($request->user()?->can('system.setup.numerator'), 403);

        return response()->json([
            'preview' => $this->service->preview($code, [
                'KATEGORI' => 'KATEGORI', 'PROJ' => 'JR',
            ], BoDocumentNumbers::SCOPE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('system.setup.numerator'), 403);

        $v = $this->aturan($request);
        // Keunikan diperiksa di sini, BUKAN diserahkan ke indeks unik: MySQL memperlakukan
        // NULL sebagai nilai berbeda, sehingga unique(tenant, project_id, scope, code)
        // TIDAK mencegah dua baris ber-project_id NULL dengan kode sama.
        $v['scope'] = BoDocumentNumbers::SCOPE;
        $v['project_id'] = null;
        $v['current_period'] = null;

        Numerator::create($v);

        return back()->with('success', "Numerator {$v['code']} ditambahkan.");
    }

    public function update(Request $request, Numerator $bo_numerator): RedirectResponse
    {
        abort_unless($request->user()?->can('system.setup.numerator'), 403);
        abort_unless($bo_numerator->scope === BoDocumentNumbers::SCOPE, 404);

        // code TIDAK ikut diubah: ia kunci yang dipakai kode penerbit dokumen. Menggantinya
        // membuat dokumennya kehilangan masternya dan diam-diam kembali ke format bawaan.
        $v = $this->aturan($request, $bo_numerator);
        unset($v['code']);

        $bo_numerator->update($v);

        return back()->with('success', "Numerator {$bo_numerator->code} diperbarui.");
    }

    public function destroy(Request $request, Numerator $bo_numerator): RedirectResponse
    {
        abort_unless($request->user()?->can('system.setup.numerator'), 403);
        abort_unless($bo_numerator->scope === BoDocumentNumbers::SCOPE, 404);
        // Dokumen katalog tidak boleh dihapus — penerbitnya akan jatuh ke format bawaan di
        // kode, dan layar ini berhenti mencerminkan apa yang sebenarnya terjadi.
        abort_if(in_array($bo_numerator->code, BoDocumentNumbers::codes(), true), 422,
            'Numerator bawaan tidak bisa dihapus, hanya diubah.');

        $kode = $bo_numerator->code;
        $bo_numerator->delete();

        return back()->with('success', "Numerator {$kode} dihapus.");
    }

    /** @return array<string,mixed> */
    private function aturan(Request $request, ?Numerator $abaikan = null): array
    {
        return $request->validate([
            // Keunikan diperiksa lewat MODEL, bukan Rule::unique dengan ->where('tenant_id',
            // tenant_id()): helper itu memulangkan null di luar konteks request (console,
            // job, uji), dan filter "tenant_id IS NULL" tidak cocok dengan baris mana pun —
            // penjagaannya mati tanpa suara. Model membawa TenantScope-nya sendiri.
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9.\-_]+$/',
                function ($attr, $value, $gagal) use ($abaikan) {
                    $ada = Numerator::where('scope', BoDocumentNumbers::SCOPE)
                        ->where('code', $value)
                        ->when($abaikan, fn ($q) => $q->whereKeyNot($abaikan->id))
                        ->exists();
                    if ($ada) {
                        $gagal("Kode {$value} sudah dipakai numerator Back Office lain.");
                    }
                }],
            'prefix'      => ['required', 'string', 'max:32'],
            // {SEQ} WAJIB. Format tanpa nomor urut akan menghasilkan nomor yang sama
            // berulang-ulang, dan karena hampir semua kolom nomor dokumen ber-UNIQUE,
            // dokumen kedua akan GAGAL tersimpan — bukan sekadar tampil aneh.
            'format'      => ['required', 'string', 'max:128', 'regex:/\{SEQ\}/'],
            'seq_padding' => ['required', 'integer', 'min:1', 'max:10'],
            'reset_strategy' => ['required', Rule::in(['never', 'yearly', 'monthly', 'daily'])],
            // Boleh disetel, tetapi TIDAK boleh mundur: menurunkannya berarti menerbitkan
            // ulang nomor yang sudah dipakai, dan indeks unik akan menolak dokumennya.
            'current_seq' => ['nullable', 'integer', 'min:0',
                $abaikan ? 'min:'.(int) $abaikan->current_seq : 'min:0'],
        ], [
            'format.regex' => 'Format wajib memuat {SEQ} — tanpa nomor urut, dokumen kedua tidak akan bisa disimpan.',
            'code.regex'   => 'Kode hanya boleh huruf kapital, angka, titik, strip, dan garis bawah.',
            'current_seq.min' => 'Nomor urut tidak boleh diturunkan — nomor yang sudah terbit akan tertabrak.',
        ]);
    }
}
