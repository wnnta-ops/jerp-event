<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Hrd;

use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsHrDepartment;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Http\Controllers\Controller;
use App\Support\Import\TabelImpor;
use App\Support\Xlsx\SimpleXlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('datacenter.employee.view'), 403);

        $rows = BsEmployee::query()
            // Departemen ikut dimuat: kolom pada layar kini menampilkan DEPARTEMEN, sumber yang
            // sama dengan Pengajuan Bon Sementara, SIP, dan Bukti Permohonan Pembayaran.
            ->with(['costCenter:id,code,name', 'department:id,code,name'])
            ->when($request->input('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('nik', 'like', "%{$s}%")->orWhere('position', 'like', "%{$s}%")))
            ->when($request->input('status') !== null && $request->input('status') !== '', fn ($q) => $q->where('is_active', $request->input('status') === 'active'))
            ->orderBy('name')->paginate(50)->withQueryString();

        return Inertia::render('BusinessSupport/Hrd/Employees', [
            'employees'   => $rows,
            'filters'     => $request->only(['search', 'status']),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'departments' => BsHrDepartment::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'summary'     => [
                'active'  => BsEmployee::where('is_active', true)->count(),
                'payroll' => (float) BsEmployee::where('is_active', true)->sum(\Illuminate\Support\Facades\DB::raw('base_salary + allowance')),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsEmployee::create($this->data($request) + ['created_by' => $request->user()->id]);

        return back()->with('success', 'Karyawan ditambahkan.');
    }

    public function update(Request $request, BsEmployee $employee): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $employee->update($this->data($request));

        return back()->with('success', 'Data karyawan diperbarui.');
    }

    public function destroy(Request $request, BsEmployee $employee): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $employee->delete();

        return back()->with('success', 'Karyawan dihapus.');
    }

    // ── Import massal ────────────────────────────────────────────────────────────────────────

    /**
     * Kolom template import, urut. Kunci = nama kolom di tabel, nilai = judul + kata kunci.
     *
     * @var array<string,array{0:string,1:array<int,string>,2:array<int,string>}>
     */
    private const KOLOM = [
        'nik'            => ['NIK', ['NIK', 'NIP', 'NO KARYAWAN', 'ID KARYAWAN'], []],
        'name'           => ['Nama Karyawan', ['NAMA KARYAWAN', 'NAMA PEGAWAI', 'NAMA'], ['BANK', 'REKENING', 'JABATAN', 'DEPART', 'NIK', 'NIP']],
        'position'       => ['Jabatan', ['JABATAN', 'POSISI', 'POSITION'], []],
        'department'     => ['Departemen', ['DEPARTEMEN', 'DEPARTMENT', 'DEPT', 'BAGIAN', 'DIVISI'], []],
        'cost_center'    => ['Cost Center', ['COST CENTER', 'COSTCENTER', 'CC', 'PUSAT BIAYA'], []],
        'join_date'      => ['Tgl Masuk', ['TGL MASUK', 'TANGGAL MASUK', 'JOIN', 'MULAI KERJA'], []],
        'base_salary'    => ['Gaji Pokok', ['GAJI POKOK', 'GAJI', 'BASIC', 'SALARY'], ['TUNJANGAN']],
        'allowance'      => ['Tunjangan', ['TUNJANGAN', 'ALLOWANCE'], []],
        'bank_name'      => ['Nama Bank', ['NAMA BANK', 'BANK'], ['REKENING', 'NO']],
        'bank_account'   => ['No. Rekening', ['NO REKENING', 'NO. REKENING', 'NOMOR REKENING', 'REKENING', 'ACCOUNT'], []],
        'is_active'      => ['Status (Aktif/Nonaktif)', ['STATUS', 'AKTIF', 'ACTIVE'], []],
    ];

    /** Unduh template .xlsx berisi judul kolom + satu baris contoh. */
    public function importTemplate(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('datacenter.employee.create'), 403);

        $dept = BsHrDepartment::where('is_active', true)->orderBy('code')->first();
        $cc   = BsCostCenter::where('is_active', true)->orderBy('code')->first();

        $judul  = array_map(fn (array $k) => $k[0], array_values(self::KOLOM));
        $contoh = [
            'EMP-999', 'Contoh Karyawan', 'Staff Accounting',
            $dept?->code ?? '', $cc?->code ?? '',
            now()->startOfYear()->toDateString(), '7000000', '1500000', 'BCA', '1234567890', 'Aktif',
        ];
        $catatan = array_fill(0, count($judul), '');
        $catatan[0] = 'CONTOH — hapus baris ini. Departemen & Cost Center diisi KODE atau NAMA yang sudah ada di master.';

        $bin = SimpleXlsx::toString([$judul, $contoh, $catatan]);

        return response($bin, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Template-Import-Karyawan.xlsx"',
        ]);
    }

    /**
     * Import massal karyawan dari .xlsx / .xls / .csv.
     *
     * Baris bermasalah DILAPORKAN beserta nomor barisnya, tidak dibuang diam-diam.
     *
     * Departemen & Cost Center dicocokkan lewat KODE dulu, baru NAMA. Yang tidak ketemu
     * membuat barisnya DITOLAK, bukan dibiarkan kosong: karyawan tanpa departemen akan
     * menghilang dari laporan payroll per departemen tanpa satu pun tanda, dan tak ada yang
     * mencarinya sampai ada yang menghitung ulang dengan tangan.
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        $request->validate([
            'file' => ['required', 'file', 'max:8192'],
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
            return back()->with('error', 'Kolom "Nama Karyawan" tidak ditemukan pada baris pertama. Unduh template untuk format yang benar.');
        }

        $mode = $request->input('mode', 'perbarui');
        $kunciPeta = fn ($v) => strtoupper(trim((string) $v));

        // Master dimuat sekali; mencari per baris akan jadi N query untuk berkas ribuan baris.
        $deptKode = BsHrDepartment::pluck('id', 'code')->mapWithKeys(fn ($id, $k) => [$kunciPeta($k) => $id])->all();
        $deptNama = BsHrDepartment::pluck('id', 'name')->mapWithKeys(fn ($id, $k) => [$kunciPeta($k) => $id])->all();
        $ccKode   = BsCostCenter::pluck('id', 'code')->mapWithKeys(fn ($id, $k) => [$kunciPeta($k) => $id])->all();
        $ccNama   = BsCostCenter::pluck('id', 'name')->mapWithKeys(fn ($id, $k) => [$kunciPeta($k) => $id])->all();
        $adaNik   = BsEmployee::withTrashed()->whereNotNull('nik')->pluck('id', 'nik')
            ->mapWithKeys(fn ($id, $k) => [$kunciPeta($k) => $id])->all();

        $baru = 0;
        $ubah = 0;
        $lewat = 0;
        $galat = [];
        $siap  = [];
        $nikDipakai = [];

        foreach ($rows as $n => $r) {
            $barisNo = $n + 2;
            $get = fn (string $f) => TabelImpor::sel($r, $idx[$f]);

            $nama = $get('name');
            if ($nama === '') {
                $galat[] = "Baris {$barisNo}: Nama Karyawan kosong.";
                $lewat++;

                continue;
            }

            $gajiRaw = $get('base_salary');
            $gaji    = $gajiRaw === '' ? 0.0 : TabelImpor::angka($gajiRaw);
            if ($gaji === null || $gaji < 0) {
                $galat[] = "Baris {$barisNo}: Gaji Pokok '{$gajiRaw}' tidak valid.";
                $lewat++;

                continue;
            }
            $tunjRaw = $get('allowance');
            $tunj    = $tunjRaw === '' ? 0.0 : TabelImpor::angka($tunjRaw);
            if ($tunj === null || $tunj < 0) {
                $galat[] = "Baris {$barisNo}: Tunjangan '{$tunjRaw}' tidak valid.";
                $lewat++;

                continue;
            }

            $tglRaw = $get('join_date');
            $tgl    = $tglRaw === '' ? null : TabelImpor::tanggal($tglRaw);
            if ($tglRaw !== '' && $tgl === null) {
                $galat[] = "Baris {$barisNo}: Tgl Masuk '{$tglRaw}' tidak terbaca (pakai YYYY-MM-DD).";
                $lewat++;

                continue;
            }

            $deptRaw = $get('department');
            $deptId  = null;
            if ($deptRaw !== '') {
                $deptId = $deptKode[$kunciPeta($deptRaw)] ?? $deptNama[$kunciPeta($deptRaw)] ?? null;
                if ($deptId === null) {
                    $galat[] = "Baris {$barisNo}: Departemen '{$deptRaw}' tidak ada di master.";
                    $lewat++;

                    continue;
                }
            }
            $ccRaw = $get('cost_center');
            $ccId  = null;
            if ($ccRaw !== '') {
                $ccId = $ccKode[$kunciPeta($ccRaw)] ?? $ccNama[$kunciPeta($ccRaw)] ?? null;
                if ($ccId === null) {
                    $galat[] = "Baris {$barisNo}: Cost Center '{$ccRaw}' tidak ada di master.";
                    $lewat++;

                    continue;
                }
            }

            $nik = $get('nik');
            if ($nik !== '' && isset($nikDipakai[$kunciPeta($nik)])) {
                $galat[] = "Baris {$barisNo}: NIK '{$nik}' muncul dua kali dalam berkas ini.";
                $lewat++;

                continue;
            }

            $adaId = $nik !== '' ? ($adaNik[$kunciPeta($nik)] ?? null) : null;
            if ($adaId !== null && $mode === 'lewati') {
                $galat[] = "Baris {$barisNo}: NIK '{$nik}' sudah ada — dilewati sesuai pilihan.";
                $lewat++;

                continue;
            }

            $data = [
                'nik'            => $nik !== '' ? mb_substr($nik, 0, 32) : null,
                'name'           => mb_substr($nama, 0, 150),
                'position'       => $get('position') ?: null,
                'department_id'  => $deptId,
                'cost_center_id' => $ccId,
                'join_date'      => $tgl,
                'base_salary'    => $gaji,
                'allowance'      => $tunj,
                'bank_name'      => $get('bank_name') ?: null,
                'bank_account'   => $get('bank_account') ?: null,
                'is_active'      => TabelImpor::boolean($get('is_active')) ?? true,
            ];

            // Kolom yang memang tidak ada di berkas tidak menimpa data lama dengan kosong.
            if ($adaId !== null) {
                foreach ($data as $f => $v) {
                    $sumber = match ($f) {
                        'department_id'  => 'department',
                        'cost_center_id' => 'cost_center',
                        default          => $f,
                    };
                    if ($f !== 'name' && ($idx[$sumber] ?? null) === null) {
                        unset($data[$f]);
                    }
                }
            }

            if ($nik !== '') {
                $nikDipakai[$kunciPeta($nik)] = true;
            }
            $siap[] = ['id' => $adaId, 'data' => $data];
            $adaId !== null ? $ubah++ : $baru++;
        }

        if ($siap === []) {
            return back()
                ->with('error', 'Tidak ada baris yang bisa diimpor.')
                ->with('import', ['baru' => 0, 'ubah' => 0, 'lewat' => $lewat, 'galat' => $galat]);
        }

        $userId = $request->user()->id;
        DB::transaction(function () use ($siap, $userId): void {
            foreach ($siap as $s) {
                if ($s['id'] !== null) {
                    BsEmployee::withTrashed()->whereKey($s['id'])->first()?->fill($s['data'])->save();

                    continue;
                }
                BsEmployee::create($s['data'] + ['created_by' => $userId]);
            }
        });

        $pesan = "Import selesai: {$baru} karyawan baru, {$ubah} diperbarui".($lewat ? ", {$lewat} dilewati" : '').'.';

        return back()
            ->with($lewat ? 'warning' : 'success', $pesan)
            ->with('import', ['baru' => $baru, 'ubah' => $ubah, 'lewat' => $lewat, 'galat' => $galat, 'catatan' => []]);
    }

    /** @return array<string,mixed> */
    private function data(Request $request): array
    {
        return $request->validate([
            'nik'            => ['nullable', 'string', 'max:32'],
            'name'           => ['required', 'string', 'max:150'],
            'position'       => ['nullable', 'string', 'max:100'],
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'department_id'  => ['nullable', 'integer', 'exists:bs_hr_departments,id'],
            'join_date'      => ['nullable', 'date'],
            'base_salary'    => ['required', 'numeric', 'min:0'],
            'allowance'      => ['nullable', 'numeric', 'min:0'],
            'bank_name'      => ['nullable', 'string', 'max:100'],
            'bank_account'   => ['nullable', 'string', 'max:60'],
            'is_active'      => ['boolean'],
        ]);
    }
}
