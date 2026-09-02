<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Setup;

use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Http\Controllers\Controller;
use App\Support\Import\TabelImpor;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Hapus supplier — DITOLAK bila ia masih memegang hutang.
     *
     * Menghapus supplier tidak menghapus invoicenya; yang terjadi hanyalah relasinya
     * memulangkan null, sehingga hutang itu muncul di Aging Hutang sebagai baris "—".
     * Baris itu menggabungkan hutang milik SIAPA PUN yang supplier-nya hilang jadi satu
     * angka gelondongan: tidak bisa ditagih, dan tidak ada yang tahu itu milik siapa.
     * Kesalahannya ditolak di sini, bukan muncul sebagai baris tanpa nama berbulan kemudian.
     */
    public function destroy(Request $request, BsSupplier $supplier): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        // Termasuk invoice yang sudah lunas: namanya tetap dibutuhkan riwayat Kartu Hutang,
        // dan foreign key di basis data juga menolak penghapusan permanennya.
        $jml = BsApInvoice::withTrashed()->where('supplier_id', $supplier->id)->count();
        if ($jml > 0) {
            $belum = BsApInvoice::where('supplier_id', $supplier->id)
                ->whereColumn('paid_amount', '<', 'total')->count();

            return back()->with('error',
                "Supplier {$supplier->code} tidak bisa dihapus — masih tertaut {$jml} invoice hutang"
                .($belum > 0 ? " ({$belum} di antaranya belum lunas)" : '')
                .'. Nonaktifkan saja lewat Edit bila tidak dipakai lagi; menghapusnya akan membuat hutang itu kehilangan nama pemiliknya di Aging Hutang.');
        }

        $supplier->delete();

        return back()->with('success', 'Supplier dihapus.');
    }

    // ── Import massal ────────────────────────────────────────────────────────────────────────

    /**
     * Kolom template import, urut. Kunci = nama kolom di tabel, nilai = judul di berkas.
     *
     * Judulnya dicocokkan sebagai KATA KUNCI, bukan harus persis: berkas yang dikirim orang
     * lapangan hampir tidak pernah memakai judul yang sama persis, dan menolak seluruh berkas
     * karena "Telepon" ditulis "No. Telp" membuat fiturnya tidak terpakai.
     *
     * @var array<string,array{0:string,1:array<int,string>,2:array<int,string>}>
     */
    private const KOLOM = [
        'code'              => ['Kode', ['KODE', 'CODE'], []],
        'name'              => ['Nama Supplier', ['NAMA SUPPLIER', 'NAMA VENDOR', 'NAMA', 'SUPPLIER', 'VENDOR'], ['BANK', 'USAHA', 'REKENING', 'KONTAK', 'KODE', 'CODE']],
        'category'          => ['Kategori', ['KATEGORI', 'CATEGORY', 'JENIS'], []],
        'contact_person'    => ['Kontak Person', ['KONTAK', 'CONTACT', 'PIC', 'NARAHUBUNG'], []],
        'phone'             => ['Telepon', ['TELEPON', 'TELP', 'PHONE', 'HP', 'HANDPHONE'], ['2', 'II', 'ALT']],
        'phone2'            => ['Telepon 2', ['TELEPON 2', 'TELP 2', 'PHONE 2', 'TELEPON ALT'], []],
        'fax'               => ['Fax', ['FAX', 'FAKS'], []],
        'email'             => ['Email', ['EMAIL', 'E-MAIL', 'SUREL'], []],
        'address'           => ['Alamat', ['ALAMAT', 'ADDRESS'], ['TAGIH', 'SURAT', 'IDENTITAS', 'BILLING', 'MAIL']],
        'city'              => ['Kota', ['KOTA', 'CITY', 'KABUPATEN'], []],
        'business_name'     => ['Nama Usaha', ['NAMA USAHA', 'BADAN USAHA', 'BUSINESS'], []],
        'npwp'              => ['NPWP', ['NPWP'], []],
        'bank_name'         => ['Nama Bank', ['NAMA BANK', 'BANK'], ['REKENING', 'ATAS NAMA', 'NO']],
        'bank_account_no'   => ['No. Rekening', ['NO REKENING', 'NO. REKENING', 'NOMOR REKENING', 'REKENING', 'ACCOUNT NO'], ['ATAS NAMA', 'NAMA REK']],
        'bank_account_name' => ['Atas Nama Rekening', ['ATAS NAMA', 'NAMA REKENING', 'PEMILIK REKENING'], []],
        'is_active'         => ['Status (Aktif/Nonaktif)', ['STATUS', 'AKTIF', 'ACTIVE'], []],
    ];

    /** Unduh template .xlsx berisi judul kolom + satu baris contoh. */
    public function importTemplate(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canModule('datacenter'), 403);

        $judul  = array_map(fn (array $k) => $k[0], array_values(self::KOLOM));
        $contoh = [
            '', 'CV Contoh Sejahtera', 'material', 'Budi', '031-1234567', '0811-2345678', '031-7654321',
            'kontak@contoh.co.id', 'Jl. Contoh No. 1', 'Surabaya', 'CV Contoh Sejahtera',
            '1234567890123456', 'BCA', '1234567890', 'CV Contoh Sejahtera', 'Aktif',
        ];
        $catatan = array_fill(0, count($judul), '');
        $catatan[0] = 'CONTOH — hapus baris ini. Kode boleh dikosongkan; sistem membuatkan nomor berurutan.';

        $bin = SimpleXlsx::toString([$judul, $contoh, $catatan]);

        return response($bin, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template-Import-Supplier.xlsx"',
        ]);
    }

    /**
     * Import massal supplier dari .xlsx / .xls / .csv.
     *
     * Baris yang bermasalah DILAPORKAN satu per satu beserta nomor barisnya, tidak dibuang
     * diam-diam. Import massal yang hanya berkata "120 supplier diproses" atas berkas berisi
     * 200 baris menyembunyikan 80 kegagalan, dan tak seorang pun akan mencarinya.
     *
     * NPWP & data bank TIDAK diwajibkan di sini — berbeda dengan form manualnya. Itu keputusan
     * sadar: dari 762 supplier yang sudah ada, seluruhnya belum punya data bank dan 308 belum
     * punya NPWP. Mewajibkannya akan menolak hampir seluruh berkas data lama. Yang datang
     * belum lengkap tetap dihitung dan disebut jumlahnya di hasil.
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $request->validate([
            'file' => ['required', 'file', 'max:8192'],
            // Bawaannya PERBARUI, bukan lewati: berkas perbaikan data adalah alasan paling
            // sering orang mengimpor ulang.
            'mode' => ['nullable', 'in:perbarui,lewati'],
        ]);

        try {
            $rows = TabelImpor::baca($request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
        if (count($rows) < 2) {
            return back()->with('error', 'File kosong atau hanya berisi baris header.');
        }

        $header = TabelImpor::header(array_shift($rows));
        $idx = [];
        $dipakai = [];
        foreach (self::KOLOM as $field => [$judul, $kunci, $kecuali]) {
            // Kolom yang sudah diklaim field sebelumnya tidak boleh dipakai lagi — urutan
            // KOLOM menentukan siapa yang lebih berhak. Kode disebut lebih dulu daripada Nama,
            // jadi judul "KODE SUPPLIER" menjadi Kode dan tidak lagi tertangkap sebagai Nama.
            $i = TabelImpor::kolom($header, $kunci, $kecuali, $dipakai);
            $idx[$field] = $i;
            if ($i !== null) {
                $dipakai[$i] = true;
            }
        }
        if ($idx['name'] === null) {
            return back()->with('error', 'Kolom "Nama Supplier" tidak ditemukan pada baris pertama. Unduh template untuk format yang benar.');
        }

        $mode = $request->input('mode', 'perbarui');

        // Dimuat SEKALI; mencari per baris akan jadi N query untuk berkas ribuan baris.
        $adaKode = BsSupplier::withTrashed()->pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [strtoupper(trim((string) $code)) => $id])->all();

        $baru = 0;
        $ubah = 0;
        $lewat = 0;
        $tanpaBank = 0;
        $tanpaNpwp = 0;
        $npwpDitambal = 0;
        $galat = [];
        $siap  = [];
        $kodeDipakai = [];

        foreach ($rows as $n => $r) {
            $barisNo = $n + 2; // baris 1 = header
            $get = fn (string $f) => TabelImpor::sel($r, $idx[$f]);

            $nama = $get('name');
            if ($nama === '') {
                $galat[] = "Baris {$barisNo}: Nama Supplier kosong.";
                $lewat++;

                continue;
            }

            [$npwp, $npwpDipulihkan, $npwpGalat] = TabelImpor::npwp($get('npwp'));
            if ($npwpGalat !== null) {
                $galat[] = "Baris {$barisNo}: {$npwpGalat}";
                $lewat++;

                continue;
            }
            if ($npwpDipulihkan) {
                $npwpDitambal++;
            }
            $email = $get('email');
            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $galat[] = "Baris {$barisNo}: Email '{$email}' tidak valid.";
                $lewat++;

                continue;
            }

            $kode = strtoupper($get('code'));
            if ($kode !== '' && isset($kodeDipakai[$kode])) {
                $galat[] = "Baris {$barisNo}: kode '{$kode}' muncul dua kali dalam berkas ini.";
                $lewat++;

                continue;
            }

            $adaId = $kode !== '' ? ($adaKode[$kode] ?? null) : null;
            if ($adaId !== null && $mode === 'lewati') {
                $galat[] = "Baris {$barisNo}: kode '{$kode}' sudah ada — dilewati sesuai pilihan.";
                $lewat++;

                continue;
            }

            $data = [
                'name'              => mb_substr($nama, 0, 150),
                'category'          => $get('category') ?: null,
                'contact_person'    => $get('contact_person') ?: null,
                'phone'             => $get('phone') ?: null,
                'phone2'            => $get('phone2') ?: null,
                'fax'               => $get('fax') ?: null,
                'email'             => $email ?: null,
                'address'           => $get('address') ?: null,
                'city'              => $get('city') ?: null,
                'business_name'     => $get('business_name') ?: null,
                'npwp'              => $npwp,
                'bank_name'         => $get('bank_name') ?: null,
                'bank_account_no'   => $get('bank_account_no') ?: null,
                'bank_account_name' => $get('bank_account_name') ?: null,
                'is_active'         => TabelImpor::boolean($get('is_active')) ?? true,
            ];

            // Kolom yang memang tidak ada di berkas TIDAK ikut menimpa data lama dengan NULL.
            // Berkas berisi dua kolom (kode + nama) untuk membetulkan ejaan tidak boleh
            // menghapus NPWP dan rekening yang sudah tersimpan.
            if ($adaId !== null) {
                foreach ($data as $f => $v) {
                    if ($f !== 'name' && $idx[$f] === null) {
                        unset($data[$f]);
                    }
                }
            }

            if (($data['bank_account_no'] ?? null) === null) {
                $tanpaBank++;
            }
            if ($npwp === null) {
                $tanpaNpwp++;
            }

            if ($kode !== '') {
                $kodeDipakai[$kode] = true;
            }
            $siap[] = ['id' => $adaId, 'code' => $kode, 'data' => $data];
            $adaId !== null ? $ubah++ : $baru++;
        }

        if ($siap === []) {
            return back()
                ->with('error', 'Tidak ada baris yang bisa diimpor.')
                ->with('import', ['baru' => 0, 'ubah' => 0, 'lewat' => $lewat, 'galat' => $galat]);
        }

        DB::transaction(function () use ($siap): void {
            foreach ($siap as $s) {
                if ($s['id'] !== null) {
                    BsSupplier::withTrashed()->whereKey($s['id'])->first()?->fill($s['data'])->save();

                    continue;
                }
                // Kode dibuat lewat nextCode() yang sama dengan tombol Tambah Supplier, bukan
                // count()+1: dua petugas mengimpor bersamaan dengan count()+1 akan menghasilkan
                // kode kembar, dan kolomnya UNIQUE — simpanannya ditolak, bukan diam-diam dobel.
                BsSupplier::create($s['data'] + ['code' => $s['code'] !== '' ? $s['code'] : $this->nextCode()]);
            }
        });

        $pesan = "Import selesai: {$baru} supplier baru, {$ubah} diperbarui".($lewat ? ", {$lewat} dilewati" : '').'.';

        return back()
            ->with($lewat ? 'warning' : 'success', $pesan)
            ->with('import', [
                'baru' => $baru, 'ubah' => $ubah, 'lewat' => $lewat, 'galat' => $galat,
                'catatan' => array_values(array_filter([
                    $tanpaBank ? "{$tanpaBank} baris tanpa data rekening bank — form Edit Supplier mewajibkannya, jadi baris itu perlu dilengkapi sebelum bisa disimpan ulang lewat layar." : null,
                    $tanpaNpwp ? "{$tanpaNpwp} baris tanpa NPWP." : null,
                    // Penambalan nol depan TIDAK boleh diam-diam: yang tersimpan berbeda dari
                    // yang tertulis di berkasnya, dan operator berhak tahu berapa banyak.
                    $npwpDitambal ? "{$npwpDitambal} NPWP dipulihkan nol depannya (Excel membuangnya karena kolomnya bertipe angka). Jadikan kolom NPWP bertipe Teks agar tidak terulang." : null,
                ])),
            ]);
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
