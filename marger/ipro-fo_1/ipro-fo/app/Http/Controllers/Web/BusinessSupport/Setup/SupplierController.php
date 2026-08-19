<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business Support → Setup → Supplier / Vendor.
 */
class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('datacenter'), 403);

        $rows = BsSupplier::query()
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")->orWhere('city', 'like', "%{$s}%")))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('BusinessSupport/Setup/Suppliers', [
            'suppliers' => $rows,
            'filters'   => $request->only(['search']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $data = $this->data($request);
        // Kode dibuat berurutan oleh sistem (anti-duplikat) bila tidak diisi.
        if (empty($data['code'])) {
            $data['code'] = $this->nextCode();
        }
        BsSupplier::create($data);

        return back()->with('success', "Supplier ditambahkan (kode {$data['code']}).");
    }

    /**
     * Kode supplier berikutnya: MENERUSKAN nomor SP tertinggi yang ada — SP.00765, SP.00766, …
     *
     * Data supplier dari software lama memakai pola SP.##### dan berhenti di SP.00764. Kode
     * baru melanjutkan deret itu, bukan memulai deret sendiri, supaya satu master supplier
     * tidak berisi dua gaya penomoran dan orang tidak perlu mengingat mana yang lama.
     *
     * Perbandingannya ANGKA (CAST), bukan teks: diurutkan sebagai teks, "SP.00764" kalah oleh
     * "SP.0099" dan nomor berikutnya akan menabrak kode yang sudah terpakai.
     *
     * Kode gaya lama SUP-#### yang mungkin tersisa dari data contoh sengaja tidak ikut
     * dihitung — kalau ikut, satu baris SUP-9999 akan menyeret seluruh deret SP.
     */
    private function nextCode(): string
    {
        $n = (int) BsSupplier::withTrashed()->where('code', 'like', 'SP.%')
            ->selectRaw('MAX(CAST(SUBSTRING(code, 4) AS UNSIGNED)) AS n')->value('n');
        do {
            $n++;
            $code = 'SP.'.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
        } while (BsSupplier::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    public function update(Request $request, BsSupplier $supplier): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $supplier->update($this->data($request, $supplier->id));

        return back()->with('success', 'Supplier diperbarui.');
    }

    public function destroy(Request $request, BsSupplier $supplier): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $supplier->delete();

        return back()->with('success', 'Supplier dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        // NPWP & no rekening dibersihkan jadi angka saja sebelum divalidasi.
        $request->merge([
            'npwp'            => preg_replace('/\D/', '', (string) $request->input('npwp')),
            'bank_account_no' => preg_replace('/[^\dxX-]/', '', (string) $request->input('bank_account_no')),
        ]);

        return $request->validate([
            'code'              => ['nullable', 'string', 'max:32', Rule::unique('bs_suppliers', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'              => ['required', 'string', 'max:150'],
            'category'          => ['nullable', 'string', 'max:64'],
            'contact_person'    => ['nullable', 'string', 'max:150'],
            'phone'             => ['nullable', 'string', 'max:30'],
            'email'             => ['nullable', 'email', 'max:150'],
            'address'           => ['nullable', 'string', 'max:255'],
            'city'              => ['nullable', 'string', 'max:100'],
            'business_name'     => ['nullable', 'string', 'max:255'],
            'phone2'            => ['nullable', 'string', 'max:30'],
            'fax'               => ['nullable', 'string', 'max:30'],
            'address_billing'   => ['nullable', 'string', 'max:255'],
            'address_mail'      => ['nullable', 'string', 'max:255'],
            'address_identity'  => ['nullable', 'string', 'max:255'],
            'npwp'              => ['required', 'digits:16'],
            'bank_name'         => ['required', 'string', 'max:64'],
            'bank_account_no'   => ['required', 'string', 'max:64'],
            'bank_account_name' => ['required', 'string', 'max:150'],
            'is_active'         => ['boolean'],
        ], [
            'npwp.required'              => 'NPWP wajib diisi.',
            'npwp.digits'               => 'NPWP harus 16 angka.',
            'bank_name.required'        => 'Nama bank wajib diisi.',
            'bank_account_no.required'  => 'No. rekening wajib diisi.',
            'bank_account_name.required'=> 'Atas nama rekening wajib diisi.',
        ]);
    }
}
