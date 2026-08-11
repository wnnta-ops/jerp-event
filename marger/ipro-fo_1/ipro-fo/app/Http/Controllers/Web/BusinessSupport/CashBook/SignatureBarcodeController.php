<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsSignatureBarcode;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Barcode\Code128;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Set Up Barcode — barcode tanda tangan per pengguna.
 *
 * Barcode inilah yang muncul di kotak Persetujuan (TTD) pada SIP begitu pengguna tsb
 * menyetujui, menggantikan namanya.
 */
class SignatureBarcodeController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $rows = BsSignatureBarcode::with('user:id,name,email')
            ->orderBy('id')->get()
            ->map(fn ($b) => [
                'id'        => $b->id,
                'user_id'   => $b->user_id,
                'user_name' => $b->user?->name ?? '—',
                'user_email' => $b->user?->email,
                'code'      => $b->code,
                'label'     => $b->label,
                'is_active' => (bool) $b->is_active,
                'svg'       => $b->svg(),
            ]);

        return Inertia::render('BusinessSupport/CashBook/SignatureBarcode/Index', [
            'rows'  => $rows,
            'users' => User::orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn ($u) => ['id' => $u->id, 'label' => $u->name.($u->email ? ' — '.$u->email : '')]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $this->validated($request, null);

        BsSignatureBarcode::create($v + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Barcode tanda tangan disimpan.');
    }

    public function update(Request $request, BsSignatureBarcode $barcode): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $barcode->update($this->validated($request, $barcode->id));

        return back()->with('success', 'Barcode tanda tangan diperbarui.');
    }

    public function destroy(Request $request, BsSignatureBarcode $barcode): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $barcode->delete();

        return back()->with('success', 'Barcode tanda tangan dihapus. SIP yang sudah disetujui kembali menampilkan nama.');
    }

    /**
     * Pratinjau barcode sebelum disimpan — dipakai layar Set Up Barcode saat mengetik.
     */
    public function preview(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $code = trim((string) $request->input('code', ''));

        return response()->json([
            'ok'  => Code128::isEncodable($code),
            'svg' => Code128::svg($code),
        ]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, ?int $ignoreId): array
    {
        $tenantId = tenant_id() ?? default_tenant_id();

        return $request->validate([
            'user_id' => [
                'required', 'integer', 'exists:users,id',
                Rule::unique('bs_signature_barcodes', 'user_id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($ignoreId),
            ],
            'code' => [
                'required', 'string', 'max:64',
                // Hanya ASCII tercetak — di luar itu tidak bisa dibentuk jadi Code 128-B.
                'regex:/^[\x20-\x7E]+$/',
                Rule::unique('bs_signature_barcodes', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($ignoreId),
            ],
            'label'     => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'user_id.unique' => 'Pengguna ini sudah punya barcode tanda tangan.',
            'code.unique'    => 'Kode barcode ini sudah dipakai pengguna lain.',
            'code.regex'     => 'Kode hanya boleh berisi huruf, angka, dan tanda baca biasa.',
        ]);
    }
}
