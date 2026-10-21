<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as BerkasResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ApCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('ap.category.view'), 403);

        $rows = BsApCategory::with(['debitAccount:id,code,name', 'usages.payableAccount:id,code,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderBy('name')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_active' => $c->is_active,
                'debit_account_id' => $c->debit_account_id,
                'debit' => $c->debitAccount?->code.' - '.$c->debitAccount?->name,
                // Satu baris per modul yang dicentang, urut seperti daftar modul.
                'pemakaian' => $c->usages
                    ->sortBy(fn ($u) => array_search($u->jenis, array_keys(BsApInvoice::JENIS), true))
                    ->map(fn ($u) => [
                        'jenis' => $u->jenis,
                        'credit_position' => $u->credit_position ?: BsApCategory::POSISI_HUTANG,
                        'payable_account_id' => $u->payable_account_id,
                        'payable' => $u->payableAccount?->code.' - '.$u->payableAccount?->name,
                    ])->values(),
            ]);
        $akunBeban = BsApCategory::akunBeban();

        return Inertia::render('BusinessSupport/Payable/Categories', [
            'categories' => $rows,
            'filters'    => $request->only('search'),
            // Hanya akun transaksi (is_postable) — CoA induk/header tidak ditampilkan. Tetap bisa dicari via SearchSelect.
            'accounts'   => BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'defaultPayable' => BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id'),
            'posisiKredit' => BsApCategory::POSISI,
            // Kategori untuk modul mana: Invoice Hutang, Reimburse Karyawan, atau Deposit Renovasi.
            // Akun debit & hutangnya inilah yang membedakan jurnal ketiga modul.
            'jenisOpsi' => collect(BsApInvoice::JENIS)->map(fn ($j) => $j['judul'])->all(),
            /* Akun untuk posisi "Beban Masih Harus Dibayar". Dikirim ke layar supaya kolom
               akun kreditnya bisa dikunci menampilkan akun yang SESUNGGUHNYA akan dipakai —
               bukan sekadar menyembunyikan pilihannya dan membuat operator menebak. */
            'akunBeban' => $akunBeban ? ['id' => $akunBeban->id, 'label' => $akunBeban->code.' - '.$akunBeban->name] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['ap.category.create', 'businesssupport.setup.manage']), 403);
        [$data, $pemakaian] = $this->data($request);
        DB::transaction(fn () => $this->simpanPemakaian(BsApCategory::create($data), $pemakaian));

        return back()->with('success', 'Kategori invoice ditambahkan.');
    }

    public function update(Request $request, BsApCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['ap.category.update', 'businesssupport.setup.manage']), 403);
        [$data, $pemakaian] = $this->data($request, $category->id);
        DB::transaction(function () use ($category, $data, $pemakaian): void {
            $category->update($data);
            $this->simpanPemakaian($category, $pemakaian);
        });

        return back()->with('success', 'Kategori invoice diperbarui.');
    }

    public function destroy(Request $request, BsApCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->canAny(['ap.category.delete', 'businesssupport.setup.manage']), 403);
        $category->delete();

        return back()->with('success', 'Kategori invoice dihapus.');
    }

    /**
     * Isian kategori + daftar modul pemakainya.
     *
     * Setiap modul yang dicentang membawa posisi kredit dan akun hutangnya sendiri, dipilih
     * user. Akun hanya dituntut pada posisi "Hutang"; pada "Beban Masih Harus Dibayar"
     * akunnya ditentukan server (akunKredit).
     *
     * @return array{0: array<string,mixed>, 1: list<array{jenis:string,credit_position:string,payable_account_id:int}>}
     */
    private function data(Request $request, ?int $id = null): array
    {
        $v = $request->validate([
            'code'               => ['required', 'string', 'max:40', Rule::unique('bs_ap_categories', 'code')->ignore($id)->where('tenant_id', tenant_id())],
            'name'               => ['required', 'string', 'max:150'],
            'debit_account_id'   => ['required', 'integer', 'exists:bs_accounts,id'],
            'is_active'          => ['boolean'],
            'pemakaian'          => ['required', 'array', 'min:1'],
            'pemakaian.*.jenis'  => ['required', 'distinct', Rule::in(array_keys(BsApInvoice::JENIS))],
            'pemakaian.*.credit_position'    => ['required', Rule::in(array_keys(BsApCategory::POSISI))],
            'pemakaian.*.payable_account_id' => ['required_if:pemakaian.*.credit_position,'.BsApCategory::POSISI_HUTANG, 'nullable', 'integer', 'exists:bs_accounts,id'],
        ], [
            'pemakaian.required' => 'Centang minimal satu modul di "Dipakai di".',
            'pemakaian.min' => 'Centang minimal satu modul di "Dipakai di".',
            'pemakaian.*.payable_account_id.required_if' => 'Akun hutang wajib dipilih untuk posisi kredit Hutang.',
        ]);

        $pemakaian = array_map(fn ($p) => [
            'jenis' => $p['jenis'],
            'credit_position' => $p['credit_position'],
            'payable_account_id' => $this->akunKredit($p['credit_position'], isset($p['payable_account_id']) ? (int) $p['payable_account_id'] : null),
        ], array_values($v['pemakaian']));

        // Kolom lama kategori mengikuti pemakaian pertama; disamakan lagi oleh
        // selaraskanKolomLama() sesudah pemakaiannya tersimpan.
        $data = [
            'code' => $v['code'], 'name' => $v['name'], 'debit_account_id' => $v['debit_account_id'],
            'is_active' => $v['is_active'] ?? true,
            'jenis' => $pemakaian[0]['jenis'],
            'credit_position' => $pemakaian[0]['credit_position'],
            'payable_account_id' => $pemakaian[0]['payable_account_id'],
        ];

        return [$data, $pemakaian];
    }

    /**
     * Pemakaian kategori disamakan PERSIS dengan centangan: modul yang tidak dicentang lagi
     * dilepas. Invoice yang sudah terbit tidak terpengaruh — akun & posisinya beku di invoice.
     *
     * @param  list<array{jenis:string,credit_position:string,payable_account_id:int}>  $pemakaian
     */
    private function simpanPemakaian(BsApCategory $kategori, array $pemakaian): void
    {
        $kategori->usages()->whereNotIn('jenis', array_column($pemakaian, 'jenis'))->delete();
        foreach ($pemakaian as $p) {
            $kategori->usages()->updateOrCreate(['jenis' => $p['jenis']], $p + ['tenant_id' => $kategori->tenant_id]);
        }
        $kategori->selaraskanKolomLama();
    }

    /**
     * Akun yang benar-benar dikredit, ditentukan SERVER.
     *
     * Posisi "Beban Masih Harus Dibayar" memakai COA dari config — bukan akun kiriman layar.
     * Kalau kiriman layar dipercaya, satu permintaan yang dirakit sendiri bisa menyimpan
     * posisi "beban" sambil mengkredit akun mana pun, dan tidak ada satu pun layar yang
     * memperlihatkan ketidakcocokannya.
     */
    private function akunKredit(string $posisi, ?int $pilihan): int
    {
        if ($posisi !== BsApCategory::POSISI_BEBAN) {
            return (int) $pilihan;
        }
        $akun = BsApCategory::akunBeban();
        abort_unless($akun !== null, 422, 'Akun '.config('business_support.gl_accounts.accrued_expense').' (Beban Masih Harus Dibayar) belum ada di bagan akun.');

        return (int) $akun->id;
    }

    /** Kolom berkas impor, berikut urutannya. Dipakai contoh format maupun pembacanya. */
    private const KOLOM = ['kode', 'keterangan', 'akun_debit', 'akun_hutang', 'aktif', 'posisi_kredit', 'dipakai_di'];

    /**
     * Unduh contoh format impor.
     *
     * Satu baris = satu kategori di satu modul. Kategori yang dipakai di dua modul ditulis
     * dua baris berkode sama. Contohnya diambil dari data NYATA bila ada, supaya operator
     * melihat kode akun yang memang berlaku di sistem ini.
     */
    public function template(Request $request): BerkasResponse
    {
        abort_unless($request->user()?->can("ap.category.view"), 403);

        $contoh = BsApCategory::with(["debitAccount:id,code", "usages.payableAccount:id,code"])
            ->orderBy("code")->limit(2)->get()
            ->flatMap(fn ($c) => $c->usages->map(fn ($u) => [
                $c->code, $c->name, $c->debitAccount?->code, $u->payableAccount?->code, $c->is_active ? 1 : 0,
                $u->credit_position ?: BsApCategory::POSISI_HUTANG, $u->jenis,
            ]))
            ->values()->all();
        if ($contoh === []) {
            $debit = BsAccount::where("is_postable", true)->where("type", "expense")->value("code");
            $hutang = BsAccount::where("code", config("business_support.gl_accounts.payable"))->value("code");
            $contoh = [
                ["BIAYA-LISTRIK", "BIAYA LISTRIK KANTOR", $debit, $hutang, 1, BsApCategory::POSISI_HUTANG, "invoice"],
                ["BIAYA-LISTRIK", "BIAYA LISTRIK KANTOR", $debit, "", 1, BsApCategory::POSISI_BEBAN, "reimburse"],
            ];
        }

        // Kode akun sengaja ditulis sebagai TEKS: sebagai angka, Excel menampilkan kode 11 digit
        // seperti 50302020217 dalam notasi ilmiah, dan orang menyalinnya apa adanya.
        $contoh = array_map(fn ($b) => [$b[0], $b[1], (string) $b[2], (string) $b[3], $b[4], $b[5], $b[6]], $contoh);

        return response(Xlsx::tulis(self::KOLOM, $contoh, 'Kategori Invoice'), 200, [
            "Content-Type" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            "Content-Disposition" => 'attachment; filename="format-impor-kategori-invoice.xlsx"',
        ]);
    }

    /**
     * Impor kategori invoice dari Excel (.xlsx).
     *
     * Satu baris = satu kategori di satu modul (kolom dipakai_di; kosong = invoice). Baris-baris
     * berkode sama digabung menjadi satu kategori; keterangan, akun debit, dan status aktifnya
     * harus sama di setiap baris itu. Modul yang tidak disebut di berkas TIDAK dilepas —
     * impor hanya menambah atau memperbarui.
     *
     * Akun ditulis sebagai KODE, bukan id. Seluruh berkas diperiksa LEBIH DULU; bila ada satu
     * baris pun yang salah, TIDAK ADA yang ditulis.
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can("businesssupport.setup.manage"), 403);
        $request->validate(["berkas" => ["required", "file", "mimes:xlsx", "max:2048"]], [
            "berkas.mimes" => "Berkas harus Excel (.xlsx). Unduh format untuk contoh susunannya.",
        ]);

        try {
            $isi = Xlsx::baca($request->file("berkas")->getRealPath());
        } catch (RuntimeException $e) {
            return back()->with("error", $e->getMessage());
        }
        if ($isi === []) {
            return back()->with("error", "Berkas impor kosong.");
        }

        // Baris judul dibuang bila memang judul; berkas tanpa judul tetap diterima.
        $kepala = array_map(fn ($x) => strtolower(trim((string) $x)), $isi[0]);
        if (in_array("kode", $kepala, true)) {
            array_shift($isi);
        }

        // dipakai_di boleh ditulis kodenya (invoice) atau namanya (Invoice Hutang).
        $modul = [];
        foreach (BsApInvoice::JENIS as $kode => $j) {
            $modul[$kode] = $kode;
            $modul[strtolower($j["judul"])] = $kode;
        }

        $kodeAkun = BsAccount::pluck("id", "code");
        $kategori = [];
        $galat = [];
        foreach ($isi as $i => $b) {
            $no = $i + 2;
            $b = array_pad(array_map(fn ($x) => trim((string) $x), $b), 7, "");
            [$kode, $nama, $debit, $hutang, $aktif, $posisi, $dipakai] = $b;
            if ($kode === "" && $nama === "") {
                continue;
            }
            /* Kolom keenam & ketujuh OPSIONAL: berkas lama yang berkolom lima tetap terbaca dan
               jatuh ke posisi "hutang" di Invoice Hutang — sama dengan sebelum fitur ini ada. */
            $posisi = $posisi === "" ? BsApCategory::POSISI_HUTANG : strtolower($posisi);
            if (! array_key_exists($posisi, BsApCategory::POSISI)) {
                $galat[] = "Baris {$no}: posisi kredit \"{$posisi}\" tidak dikenal (isi ".implode(" atau ", array_keys(BsApCategory::POSISI)).", atau kosongkan).";
                continue;
            }
            $jenis = $modul[strtolower($dipakai === "" ? "invoice" : $dipakai)] ?? null;
            if ($jenis === null) {
                $galat[] = "Baris {$no}: dipakai_di \"{$dipakai}\" tidak dikenal (isi ".implode(", ", array_keys(BsApInvoice::JENIS)).", atau kosongkan).";
                continue;
            }
            if ($kode === "") { $galat[] = "Baris {$no}: kode kategori kosong."; continue; }
            if ($nama === "") { $galat[] = "Baris {$no}: keterangan kosong."; continue; }
            if (! $kodeAkun->has($debit)) { $galat[] = "Baris {$no}: akun debit \"{$debit}\" tidak ada di bagan akun."; continue; }
            // Pada posisi "beban", kolom akun hutang boleh kosong — akunnya ditentukan sistem.
            if ($posisi === BsApCategory::POSISI_HUTANG && ! $kodeAkun->has($hutang)) {
                $galat[] = "Baris {$no}: akun hutang \"{$hutang}\" tidak ada di bagan akun.";
                continue;
            }
            if ($posisi === BsApCategory::POSISI_BEBAN && BsApCategory::akunBeban() === null) {
                $galat[] = "Baris {$no}: akun ".config("business_support.gl_accounts.accrued_expense")." (Beban Masih Harus Dibayar) belum ada di bagan akun.";
                continue;
            }

            $kepalaKategori = [
                "code" => $kode, "name" => $nama,
                "debit_account_id" => $kodeAkun[$debit],
                "is_active" => ! in_array(strtolower($aktif), ["0", "tidak", "no", "nonaktif", "false"], true),
            ];
            if (isset($kategori[$kode]) && $kategori[$kode]["kepala"] != $kepalaKategori) {
                $galat[] = "Baris {$no}: kode {$kode} sudah ada di baris lain dengan keterangan / akun debit / aktif yang berbeda.";
                continue;
            }
            if (isset($kategori[$kode]["pemakaian"][$jenis])) {
                $galat[] = "Baris {$no}: kode {$kode} untuk {$jenis} ditulis dua kali.";
                continue;
            }
            $kategori[$kode]["kepala"] = $kepalaKategori;
            $kategori[$kode]["pemakaian"][$jenis] = [
                "jenis" => $jenis,
                "credit_position" => $posisi,
                "payable_account_id" => $this->akunKredit($posisi, $kodeAkun[$hutang] ?? null),
            ];
        }

        if ($galat !== []) {
            return back()->with("error", "Impor dibatalkan, tidak ada data yang diubah — ".implode(" ", array_slice($galat, 0, 5))
                .(count($galat) > 5 ? " (dan ".(count($galat) - 5)." galat lain)" : ""));
        }
        if ($kategori === []) {
            return back()->with("error", "Tidak ada baris yang bisa diimpor dari berkas itu.");
        }

        $baru = 0;
        $ubah = 0;
        DB::transaction(function () use ($kategori, &$baru, &$ubah): void {
            foreach ($kategori as $k) {
                $pertama = reset($k["pemakaian"]);
                $ada = BsApCategory::where("code", $k["kepala"]["code"])->first();
                $ada ? $ubah++ : $baru++;
                if ($ada) {
                    $ada->update($k["kepala"]);
                }
                $c = $ada ?? BsApCategory::create($k["kepala"] + [
                    "jenis" => $pertama["jenis"], "credit_position" => $pertama["credit_position"], "payable_account_id" => $pertama["payable_account_id"],
                ]);
                foreach ($k["pemakaian"] as $p) {
                    $c->usages()->updateOrCreate(["jenis" => $p["jenis"]], $p + ["tenant_id" => $c->tenant_id]);
                }
                $c->selaraskanKolomLama();
            }
        });

        return back()->with("success", "Impor selesai — {$baru} kategori baru, {$ubah} diperbarui.");
    }
}
