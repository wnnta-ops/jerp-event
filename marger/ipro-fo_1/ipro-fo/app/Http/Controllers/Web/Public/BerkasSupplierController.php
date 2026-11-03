<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Public;

use App\Domain\BusinessSupport\Models\BsApBerkas;
use App\Domain\BusinessSupport\Models\BsApBerkasLink;
use App\Domain\BusinessSupport\Services\ApBerkasService;
use App\Domain\BusinessSupport\Services\LinkBerkasSupplier;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Halaman PUBLIK (tanpa login) untuk supplier mengunggah kelengkapan dokumen tagihan lewat link
 * yang dibuat di Registrasi Invoice Hutang › Detil Tagihan › "Generate Link". Pengaman: token acak
 * 48 karakter + throttle; satu link hanya menerima satu kiriman.
 */
class BerkasSupplierController extends Controller
{
    public function show(string $token): Response
    {
        $link = $this->link($token);

        return response(view('public.berkas-supplier', [
            'link' => $link,
            'jenis' => BsApBerkas::query()->where('is_active', true)->orderByDesc('is_mandatory')->orderBy('id')->get(['id', 'name', 'is_mandatory']),
            'maxFiles' => ApBerkasService::MAX_FILES,
            'perusahaan' => (string) (\Illuminate\Support\Facades\DB::table('parameters')->where('tenant_id', $link->tenant_id)
                ->where('group', 'company')->where('key', 'name')->value('value') ?: 'PT MUP'),
        ])->render());
    }

    public function store(Request $request, string $token, LinkBerkasSupplier $svc): RedirectResponse
    {
        $link = $this->link($token);
        $v = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.ApBerkasService::MAX_FILES],
            'files.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'files.*.berkas_id' => ['required', 'integer', 'exists:bs_ap_berkas,id'],
            'catatan' => ['nullable', 'string', 'max:200'],
        ], [
            'files.required' => 'Unggah minimal satu file.',
            'files.*.file.required' => 'Pilih file untuk tiap baris.',
            'files.*.file.mimes' => 'File harus PDF, JPG, atau PNG.',
            'files.*.file.max' => 'Ukuran tiap file maksimal 10 MB.',
            'files.*.berkas_id.required' => 'Pilih jenis dokumen untuk tiap file.',
        ]);

        try {
            $svc->terimaDariSupplier($link, array_map(fn ($f) => ['file' => $f['file'], 'berkas_id' => (int) $f['berkas_id']], $v['files']), $v['catatan'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('berkas-supplier.show', $token);
    }

    /** Link dari token — tenant ikut link (halaman ini tanpa login). */
    private function link(string $token): BsApBerkasLink
    {
        $link = BsApBerkasLink::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        app()->instance('tenant.id', (int) $link->tenant_id);

        return $link->load(['supplier:id,name']);
    }
}
