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
            'jenis' => $this->jenisSupplier(),
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
            'files.*.berkas_id' => ['required', 'integer', \Illuminate\Validation\Rule::in($this->jenisSupplier()->pluck('id')->all())],
            'files.*.keterangan' => ['nullable', 'string', 'max:100'],
            'no_invoice' => ['required', 'string', 'max:80'],
            'catatan' => ['nullable', 'string', 'max:200'],
        ], [
            'no_invoice.required' => 'Isi nomor invoice Anda.',
            'files.required' => 'Unggah minimal satu file.',
            'files.*.file.required' => 'Pilih file untuk tiap baris.',
            'files.*.file.mimes' => 'File harus PDF, JPG, atau PNG.',
            'files.*.file.max' => 'Ukuran tiap file maksimal 10 MB.',
            'files.*.berkas_id.required' => 'Pilih jenis dokumen untuk tiap file.',
            'files.*.berkas_id.in' => 'Jenis dokumen tidak dikenal.',
        ]);
        try {
            $svc->terimaDariSupplier($link, BsApBerkas::rapikanUnggahan($v['files']), $v['catatan'] ?? null, $v['no_invoice']);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('berkas-supplier.show', $token);
    }

    /** Jenis dokumen tetap halaman ini: Invoice, Kuitansi, Faktur Pajak, Lainnya. */
    private function jenisSupplier(): \Illuminate\Support\Collection
    {
        return BsApBerkas::jenisTetap();
    }

    /** Link dari token — tenant ikut link (halaman ini tanpa login). */
    private function link(string $token): BsApBerkasLink
    {
        $link = BsApBerkasLink::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        app()->instance('tenant.id', (int) $link->tenant_id);

        return $link->load(['supplier:id,name']);
    }
}
