<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $rows = BsApCategory::with(['debitAccount:id,code,name', 'payableAccount:id,code,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy('name')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_active' => $c->is_active,
                'debit_account_id' => $c->debit_account_id, 'payable_account_id' => $c->payable_account_id,
                'debit' => $c->debitAccount?->code.' - '.$c->debitAccount?->name,
                'payable' => $c->payableAccount?->code.' - '.$c->payableAccount?->name,
            ]);

        return Inertia::render('BusinessSupport/Payable/Categories', [
            'categories' => $rows,
            'filters'    => $request->only('search'),
            // Hanya akun transaksi (is_postable) — CoA induk/header tidak ditampilkan. Tetap bisa dicari via SearchSelect.
            'accounts'   => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'defaultPayable' => BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsApCategory::create($this->data($request));

        return back()->with('success', 'Kategori invoice ditambahkan.');
    }

    public function update(Request $request, BsApCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->update($this->data($request, $category->id));

        return back()->with('success', 'Kategori invoice diperbarui.');
    }

    public function destroy(Request $request, BsApCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $category->delete();

        return back()->with('success', 'Kategori invoice dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'code'               => ['required', 'string', 'max:40', Rule::unique('bs_ap_categories', 'code')->ignore($id)->where('tenant_id', tenant_id())],
            'name'               => ['required', 'string', 'max:150'],
            'debit_account_id'   => ['required', 'integer', 'exists:bs_accounts,id'],
            'payable_account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'is_active'          => ['boolean'],
        ]);
    }

    /** Kolom berkas impor, berikut urutannya. Dipakai contoh format maupun pembacanya. */
    private const KOLOM = ['kode', 'keterangan', 'akun_debit', 'akun_hutang', 'aktif'];

    /**
     * Unduh contoh format impor.
     *
     * Berisi baris judul + dua baris contoh yang diambil dari data NYATA bila ada, supaya
     * operator melihat kode akun yang memang berlaku di sistem ini — bukan contoh karangan
     * yang akan ditolak begitu diunggah kembali.
     */
    public function template(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->canModule("ap"), 403);

        $contoh = BsApCategory::with(["debitAccount:id,code", "payableAccount:id,code"])
            ->orderBy("code")->limit(2)->get()
            ->map(fn ($c) => [$c->code, $c->name, $c->debitAccount?->code, $c->payableAccount?->code, $c->is_active ? 1 : 0])
            ->all();
        if ($contoh === []) {
            $debit = BsAccount::where("is_postable", true)->where("type", "expense")->value("code");
            $hutang = BsAccount::where("code", config("business_support.gl_accounts.payable"))->value("code");
            $contoh = [["BIAYA-LISTRIK", "BIAYA LISTRIK KANTOR", $debit, $hutang, 1]];
        }

        return response()->streamDownload(function () use ($contoh) {
            $out = fopen("php://output", "w");
            // BOM supaya Excel membaca huruf beraksen dengan benar.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, self::KOLOM, ",", chr(34), "");
            foreach ($contoh as $b) {
                fputcsv($out, $b, ",", chr(34), "");
            }
            fclose($out);
        }, "format-impor-kategori-invoice.csv", ["Content-Type" => "text/csv; charset=UTF-8"]);
    }

    /**
     * Impor kategori invoice dari CSV.
     *
     * Akun ditulis sebagai KODE, bukan id: kode itulah yang terbaca operator di layar dan di
     * bagan akun, sedangkan id hanya bermakna di dalam basis data ini.
     *
     * Dicocokkan lewat KODE kategori: yang sudah ada diperbarui, yang belum dibuat. Seluruh
     * berkas diperiksa LEBIH DULU; bila ada satu baris pun yang salah, TIDAK ADA yang ditulis.
     * Impor separuh jalan meninggalkan master yang setengah berubah dan tidak jelas sampai
     * mana — jauh lebih sulit dibereskan daripada mengunggah ulang berkas yang sudah dibetulkan.
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can("businesssupport.setup.manage"), 403);
        $request->validate(["berkas" => ["required", "file", "mimes:csv,txt", "max:2048"]]);

        $isi = array_map(fn ($b) => str_getcsv($b, ",", chr(34), ""), file($request->file("berkas")->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        if ($isi === []) {
            return back()->with("error", "Berkas impor kosong.");
        }

        // Baris judul dibuang bila memang judul; berkas tanpa judul tetap diterima.
        // BOM UTF-8 di awal berkas (ditulis Excel) dibuang eksplisit  tanpa itu kolom
        // pertama terbaca sebagai "&kode" dan baris judulnya tidak dikenali.
        $bom = chr(0xEF).chr(0xBB).chr(0xBF);
        $kepala = array_map(fn ($x) => strtolower(trim(str_replace($bom, '', (string) $x))), $isi[0]);
        if (in_array("kode", $kepala, true)) {
            array_shift($isi);
        }

        $kodeAkun = BsAccount::pluck("id", "code");
        $baris = [];
        $galat = [];
        foreach ($isi as $i => $b) {
            $no = $i + 2;
            $b = array_pad(array_map(fn ($x) => trim((string) $x), $b), 5, "");
            [$kode, $nama, $debit, $hutang, $aktif] = $b;
            if ($kode === "" && $nama === "") {
                continue;
            }
            if ($kode === "") { $galat[] = "Baris {$no}: kode kategori kosong."; continue; }
            if ($nama === "") { $galat[] = "Baris {$no}: keterangan kosong."; continue; }
            if (! $kodeAkun->has($debit)) { $galat[] = "Baris {$no}: akun debit \"{$debit}\" tidak ada di bagan akun."; continue; }
            if (! $kodeAkun->has($hutang)) { $galat[] = "Baris {$no}: akun hutang \"{$hutang}\" tidak ada di bagan akun."; continue; }

            $baris[] = [
                "code" => $kode, "name" => $nama,
                "debit_account_id" => $kodeAkun[$debit], "payable_account_id" => $kodeAkun[$hutang],
                "is_active" => ! in_array(strtolower($aktif), ["0", "tidak", "no", "nonaktif", "false"], true),
            ];
        }

        if ($galat !== []) {
            return back()->with("error", "Impor dibatalkan, tidak ada data yang diubah — ".implode(" ", array_slice($galat, 0, 5))
                .(count($galat) > 5 ? " (dan ".(count($galat) - 5)." galat lain)" : ""));
        }
        if ($baris === []) {
            return back()->with("error", "Tidak ada baris yang bisa diimpor dari berkas itu.");
        }

        $baru = 0;
        $ubah = 0;
        DB::transaction(function () use ($baris, &$baru, &$ubah): void {
            foreach ($baris as $b) {
                $ada = BsApCategory::where("code", $b["code"])->first();
                $ada ? $ubah++ : $baru++;
                $ada ? $ada->update($b) : BsApCategory::create($b);
            }
        });

        return back()->with("success", "Impor selesai — {$baru} kategori baru, {$ubah} diperbarui.");
    }
}
