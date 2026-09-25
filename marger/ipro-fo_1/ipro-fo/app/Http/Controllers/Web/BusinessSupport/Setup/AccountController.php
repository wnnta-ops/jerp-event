<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCashFlowCategory;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsComponentController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Domain\BusinessSupport\Import\CoaImporter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business Support → Setup → Chart of Account (General Ledger).
 */
class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('gl.account.view'), 403);

        $search = $request->input('search');
        $type   = $request->input('type');

        $rows = BsAccount::query()
            ->with(['cfDirectCategory:id,code,name', 'cfIndirectCategory:id,code,name', 'costCenter:id,code,name'])
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->when($type, fn ($q, $v) => $q->where('type', $v))
            // Kelompokkan per kategori (head), level-1 header lebih dulu, lalu urut kode.
            ->orderByRaw("COALESCE(category_code, substr(code,1,1)) asc, case when level = 1 then '0' else code end asc")
            ->get(['id', 'code', 'category_code', 'name', 'type', 'level', 'parent_id', 'parent_code', 'normal_balance', 'is_postable', 'is_active', 'cf_direct_category_id', 'cf_indirect_category_id', 'cost_center_id', 'cogs_component']);

        $cfCats = fn (string $kind) => BsCashFlowCategory::where('kind', $kind)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'activity']);

        return Inertia::render('BusinessSupport/Setup/Accounts', [
            'accounts'    => $rows,
            'types'       => BsAccount::TYPES,
            // Nama tingkatan datang dari model, bukan disalin ulang di layar: satu daftar,
            // satu tempat mengubahnya, dan pesan galat server memakai nama yang sama.
            'levels'      => BsAccount::LEVELS,
            // Calon induk untuk tiap level — seluruh akun, tidak disaring pencarian daftar,
            // supaya operator yang sedang menyaring tabel tetap bisa memilih induk mana pun.
            'parentOptions' => BsAccount::orderBy('code')->get(['id', 'code', 'name', 'level'])
                ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'level' => (int) $a->level])->values(),
            'cfDirect'    => $cfCats('direct'),
            'cfIndirect'  => $cfCats('indirect'),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            // Daftar komponen COGS datang dari SATU tempat (CogsComponentController::KOMPONEN),
            // bukan disalin di layar: nilainya yang tersimpan adalah kunci komponen HPP
            // ('tanah'|'bangunan'|'sarana'|'permit'), sedangkan yang dibaca operator adalah
            // labelnya. Disalin ke Vue, menambah satu komponen berarti mengingat dua tempat.
            'cogsComponents' => CogsComponentController::pilihanKomponen(),
            'filters'     => ['search' => $search, 'type' => $type],
            'stats'       => [
                'total'    => BsAccount::count(),
                'postable' => BsAccount::where('is_postable', true)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsAccount::create($this->data($request));

        return back()->with('success', 'Akun ditambahkan.');
    }

    public function update(Request $request, BsAccount $account): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $account->update($this->data($request, $account->id));

        return back()->with('success', 'Akun diperbarui.');
    }

    public function destroy(Request $request, BsAccount $account): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $account->delete();

        return back()->with('success', 'Akun dihapus.');
    }

    /** @return array<string,mixed> */
    private function data(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'code'           => ['required', 'string', 'max:32', Rule::unique('bs_accounts', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'           => ['required', 'string', 'max:150'],
            // Level dipilih operator, tidak lagi diterka dari panjang kode. Menerkanya membuat
            // kode sepanjang berapa pun yang tidak ganjil/≤11 diam-diam jatuh ke level 6 —
            // akun header bisa lahir sebagai Detil Sub Transaksi tanpa seorang pun diberi tahu.
            'level'          => ['required', 'integer', 'between:1,'.count(BsAccount::LEVELS)],
            // Induk WAJIB kecuali level 1: itulah yang membuat bagan akun benar-benar bertingkat,
            // bukan sekadar terlihat bertingkat karena kodenya kebetulan berawalan sama.
            'parent_id'      => [Rule::requiredIf(fn () => (int) $request->input('level') > 1), 'nullable', 'integer', 'exists:bs_accounts,id'],
            'type'           => ['required', Rule::in(array_keys(BsAccount::TYPES))],
            'normal_balance' => ['required', Rule::in(['D', 'K'])],
            'is_postable'    => ['boolean'],
            'is_active'      => ['boolean'],
            'cf_direct_category_id'   => ['nullable', 'integer', Rule::exists('bs_cash_flow_categories', 'id')->where('kind', 'direct')],
            'cf_indirect_category_id' => ['nullable', 'integer', Rule::exists('bs_cash_flow_categories', 'id')->where('kind', 'indirect')],
            'cost_center_id'          => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            // Kunci komponen HPP, bukan slug alamat layar. Daftar sahnya diambil dari
            // CogsComponentController supaya tidak lahir kosakata ketiga.
            'cogs_component'          => ['nullable', Rule::in(array_keys(CogsComponentController::pilihanKomponen()))],
        ]);

        $level  = (int) $data['level'];
        $parent = $data['parent_id'] ? BsAccount::find($data['parent_id']) : null;
        $galat  = [];

        // Induk harus TEPAT satu tingkat di atas — bukan sekadar ada. Ledger yang beribu Kategori
        // (melompati Sub Kategori) akan membuat penjumlahan bertingkat kehilangan satu lapis.
        if ($level === 1 && $parent) {
            $galat['parent_id'] = 'Akun level 1 ('.BsAccount::LEVELS[1].') adalah puncak bagan akun — ia tidak boleh punya induk.';
        } elseif ($level > 1 && (! $parent || (int) $parent->level !== $level - 1)) {
            $galat['parent_id'] = 'Induk harus akun berlevel '.($level - 1).' ('.BsAccount::LEVELS[$level - 1].')'
                .($parent ? ', sedangkan yang dipilih berlevel '.$parent->level.' ('.(BsAccount::LEVELS[(int) $parent->level] ?? '?').').' : '.');
        }

        // Panjang kode ditentukan levelnya: tiap turun satu tingkat menambah dua digit.
        $panjang = 2 * $level - 1;
        if (! ctype_digit($data['code'])) {
            $galat['code'] = 'Kode akun hanya boleh berisi angka.';
        } elseif (strlen($data['code']) !== $panjang) {
            $galat['code'] = 'Kode akun level '.$level.' ('.BsAccount::LEVELS[$level].') harus '.$panjang.' digit, bukan '.strlen($data['code']).'.';
        } elseif ($parent && ! str_starts_with($data['code'], (string) $parent->code)) {
            // Kode anak MELANJUTKAN kode induknya. Tanpa syarat ini, akun bisa terdaftar di
            // bawah induk yang satu tetapi terbaca milik cabang lain oleh apa pun yang
            // menyusun hierarki dari awalan kode — dan di aplikasi ini itu banyak.
            $galat['code'] = 'Kode akun harus melanjutkan kode induknya, yaitu diawali "'.$parent->code.'".';
        }

        // Akun yang sudah punya anak tidak boleh dipindah levelnya: anaknya akan tergantung.
        if ($id && $level !== (int) (BsAccount::find($id)?->level ?? $level)
            && BsAccount::where('parent_id', $id)->orWhere('parent_code', BsAccount::find($id)?->code)->exists()) {
            $galat['level'] = 'Akun ini sudah punya akun turunan — levelnya tidak bisa diubah sebelum turunannya dipindahkan.';
        }

        if ($galat) {
            throw ValidationException::withMessages($galat);
        }

        unset($data['parent_id']);
        $data['parent_id']     = $parent?->id;
        $data['parent_code']   = $parent?->code;
        $data['category_code'] = BsAccount::categoryOf($data['code']);

        return $data;
    }

    /**
     * Unduh contoh format impor Chart of Account.
     *
     * Kolomnya mengikuti lampiran "COA Aktiva" apa adanya, sehingga berkas yang sudah ada
     * di tangan operator bisa langsung dipakai tanpa disusun ulang. Baris contohnya diambil
     * dari akun NYATA bila ada — supaya kode & tingkatan pada contoh memang berlaku di sini.
     */
    public function template(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can('gl.account.view'), 403);

        // Contohnya sengaja SATU RANTAI penuh level 1 sampai 6, bukan enam akun teratas:
        // hanya rantai yang memperlihatkan bagaimana kode anak melanjutkan kode induknya,
        // dan hanya baris level 6 yang memperlihatkan kolom Postable terisi.
        $rantai = BsAccount::where('is_postable', true)->where('level', 6)->orderBy('code')->first();
        $urut   = [];
        while ($rantai) {
            array_unshift($urut, $rantai);
            $rantai = $rantai->parent_id ? BsAccount::find($rantai->parent_id) : null;
        }
        if ($urut === []) {
            $urut = BsAccount::orderBy('level')->orderBy('code')->limit(6)->get()->all();
        }

        $contoh = collect($urut)->map(fn ($a) => [
            $a->level, $a->code, $a->name, BsAccount::LEVELS[(int) $a->level] ?? '',
            // Tipe ditulis dengan kata Indonesia seperti pada lampiran, bukan nilai kolomnya.
            BsAccount::TYPES[$a->type] ?? $a->type,
            $a->normal_balance, $a->is_postable ? '√' : '', '', '', '',
        ])->all();

        return response()->streamDownload(function () use ($contoh) {
            $out = fopen("php://output", "w");
            // BOM supaya Excel membaca tanda centang & huruf beraksen dengan benar.
            fwrite($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, CoaImporter::KOLOM, ",", chr(34), "");
            foreach ($contoh as $b) {
                fputcsv($out, $b, ",", chr(34), "");
            }
            fclose($out);
        }, "format-impor-coa.csv", ["Content-Type" => "text/csv; charset=UTF-8"]);
    }

    /**
     * Impor Chart of Account dari CSV.
     *
     * Pemeriksaan & penulisannya diserahkan ke CoaImporter — perkakas yang sama dengan
     * perintah `coa:impor`, jadi berkas yang ditolak di sini juga ditolak di sana.
     * Bila ada satu baris pun yang salah, TIDAK ADA yang ditulis.
     */
    public function import(Request $request, CoaImporter $importer): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $request->validate(["berkas" => ["required", "file", "mimes:csv,txt", "max:4096"]]);

        $baris = $importer->csv((string) file_get_contents($request->file("berkas")->getRealPath()));
        if ($baris === []) {
            return back()->with("error", "Berkas impor kosong.");
        }

        $hasil = $importer->baca($baris);
        if ($hasil["galat"] !== []) {
            return back()->with("error", "Impor dibatalkan, tidak ada data yang diubah — "
                .implode(" ", array_slice($hasil["galat"], 0, 5))
                .(count($hasil["galat"]) > 5 ? " (dan ".(count($hasil["galat"]) - 5)." galat lain)" : ""));
        }
        if ($hasil["akun"] === []) {
            return back()->with("error", "Tidak ada baris yang bisa diimpor dari berkas itu.");
        }

        $t = $importer->tulis($hasil["akun"]);

        return back()->with("success", "Impor selesai — {$t['baru']} akun baru, {$t['ubah']} diperbarui.");
    }
}
