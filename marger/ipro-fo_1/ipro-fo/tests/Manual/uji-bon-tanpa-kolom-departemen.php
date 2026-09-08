<?php

declare(strict_types=1);

/*
 * Uji: kolom Departemen dihapus dari layar Pengajuan Bon Sementara — TANPA menghilangkan
 * datanya.
 *
 * INI YANG MUDAH SALAH. Departemen bukan sekadar hiasan di form itu: bs_cash_advances
 * menyimpannya, Laporan Bon Sementara menyaring dengannya, dan kartu bon menampilkannya.
 * Menghapus kolomnya begitu saja membuat setiap pengajuan baru tersimpan tanpa departemen —
 * penyaringnya lalu memulangkan kosong untuk bon-bon terbaru, dan tidak ada satu pun galat
 * yang menandainya. Yang terlihat hanya laporan yang makin lama makin bolong.
 *
 * Yang membuatnya aman: CashAdvanceService::issue() sudah menurunkan departemen dari KARYAWAN
 * yang dipilih bila tidak dikirim (fallback #150). Uji ini menjaga rantai itu tetap utuh.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan:  docker exec ipro-fo-app php tests/Manual/uji-bon-tanpa-kolom-departemen.php
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\BusinessSupport\Services\CashAdvanceService;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$tenantId = (int) DB::table('tenants')->orderBy('id')->value('id');
app()->instance('tenant.id', $tenantId);

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};

$ctrl = new CashAdvanceController();
$user = \App\Models\User::query()->find((int) DB::table('users')->orderBy('id')->value('id'));
auth()->setUser($user);

/** Props Inertia yang dikirim ke layar pengajuan. */
$propsForm = function () use ($ctrl, $user): array {
    $req = Request::create('/x', 'GET');
    $req->setUserResolver(fn () => $user);
    $req->headers->set('X-Inertia', 'true');
    $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $isi = json_decode((string) $ctrl->create($req)->toResponse($req)->getContent(), true) ?: [];

    return $isi['props'] ?? [];
};

DB::beginTransaction();

try {
    $deptId = (int) DB::table('bs_hr_departments')->orderBy('id')->value('id');
    if (! $deptId) {
        throw new RuntimeException('tidak ada departemen HR untuk dijadikan fikstur');
    }
    // Karyawan uji BER-DEPARTEMEN — dari sinilah departemen bonnya nanti diturunkan.
    $pegawaiId = (int) DB::table('bs_employees')->insertGetId([
        'tenant_id' => $tenantId, 'name' => 'UJI Pemegang Bon',
        'position' => 'Staff Uji', 'department_id' => $deptId, 'is_active' => 1,
        'base_salary' => 0, 'allowance' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    echo "\n=== 1. Kolom Departemen tidak ada lagi di layarnya ===\n";

    $berkas = file_get_contents(resource_path('js/Pages/BusinessSupport/CashBook/Advances/Form.vue')) ?: '';
    // Komentar dibuang dulu — penjelasan mengapa kolomnya dihapus memang menyebut kata itu.
    $tanpaKomentar = preg_replace('#/\*[\s\S]*?\*/|//[^\n]*#', '', $berkas) ?? '';
    $cek('tidak ada isian departemen di formnya', ! str_contains($tanpaKomentar, 'form.department_id'),
        'masih ada v-model departemen');
    $cek('tidak ada daftar pilihan departemen', ! str_contains($tanpaKomentar, 'v-for="d in departments"'));
    $cek('departemen tidak lagi jadi prop layarnya', ! str_contains($tanpaKomentar, 'departments: Department[]'));
    $cek('kiriman formnya tidak lagi memuat department_id',
        ! preg_match('/useForm[^)]*department_id/', $tanpaKomentar));
    /* employee_id HARUS tetap dikirim — dari situlah server menurunkan departemennya. Tanpa
       pemeriksaan ini, menghapusnya dari kiriman form akan memutus rantainya diam-diam:
       bonnya tetap tersimpan, hanya departemennya kosong selamanya. Uji mutasi menemukan
       celah ini — bagian 3 memanggil servicenya langsung, jadi ia tidak melihat isi kiriman. */
    $cek('employee_id tetap dikirim formnya', (bool) preg_match('/useForm[^)]*employee_id/', $tanpaKomentar),
        'employee_id hilang dari kiriman — departemen tidak akan pernah terisi');
    $cek('karyawan dipilih lewat SearchSelect yang terikat employee_id',
        str_contains($tanpaKomentar, 'v-model="form.employee_id"'));

    echo "\n=== 2. Server tidak lagi mengirim daftar departemen ke layar itu ===\n";

    $props = $propsForm();
    $cek('props departments sudah tidak dikirim', ! array_key_exists('departments', $props),
        implode(', ', array_keys($props)));
    $cek('daftar karyawan tetap dikirim', is_array($props['employees'] ?? null) && count($props['employees']) > 0,
        'employees kosong');
    $pegawai = collect($props['employees'] ?? [])->firstWhere('id', $pegawaiId);
    $cek('karyawan uji ikut terkirim', $pegawai !== null);
    // department_id karyawan tak lagi dipakai layarnya; mengirimnya hanya jadi muatan mati.
    $cek('department_id karyawan tidak ikut dikirim', $pegawai !== null && ! array_key_exists('department_id', $pegawai),
        json_encode($pegawai));

    echo "\n=== 3. DEPARTEMENNYA TETAP TERCATAT — ini intinya ===\n";

    // Persis seperti kiriman layarnya sekarang: TANPA department_id, hanya employee_id.
    $svc = app(CashAdvanceService::class);
    // Cost center WAJIB sejak bon ditautkan ke Budget Control — fikstur ini hanya perlu satu
    // yang sah; yang diuji berkas ini soal DEPARTEMENNYA, bukan dimensi anggarannya.
    $ccUji = (int) \App\Domain\BusinessSupport\Models\BsCostCenter::where('is_active', true)->value('id');
    $cek('ada cost center aktif untuk fikstur', $ccUji > 0, (string) $ccUji);
    $adv = $svc->issue([
        'advance_date' => '2026-09-03', 'holder' => 'UJI Pemegang Bon — Staff Uji',
        'purpose' => 'UJI tanpa kolom departemen', 'amount' => 500000,
        'employee_id' => $pegawaiId, 'cost_center_id' => $ccUji,
    ], (int) $user->id);

    $tersimpan = DB::table('bs_cash_advances')->where('id', $adv->id)->first(['department_id', 'amount']);
    $cek('bonnya tersimpan', $tersimpan !== null);
    $cek('departemennya terisi dari karyawan yang dipilih',
        (int) ($tersimpan->department_id ?? 0) === $deptId,
        'dapat '.var_export($tersimpan->department_id ?? null, true).' harusnya '.$deptId);

    echo "\n=== 4. Penyaring Departemen pada laporannya tetap menemukan bon itu ===\n";

    // Inilah akibat yang paling mahal bila rantainya putus: laporan yang diam-diam bolong.
    $ketemu = DB::table('bs_cash_advances')->where('department_id', $deptId)->where('id', $adv->id)->exists();
    $cek('bon barunya ikut tersaring per departemen', $ketemu);

    echo "\n=== 5. Karyawan tanpa departemen tetap bisa mengajukan ===\n";

    // Tidak semua karyawan berdepartemen. Menjadikannya syarat berarti mengunci pengajuan
    // untuk orang yang datanya belum lengkap — padahal kolomnya baru saja dihapus.
    $tanpaDept = (int) DB::table('bs_employees')->insertGetId([
        'tenant_id' => $tenantId, 'name' => 'UJI Tanpa Departemen',
        'position' => 'Staff Uji', 'department_id' => null, 'is_active' => 1,
        'base_salary' => 0, 'allowance' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $adv2 = $svc->issue([
        'advance_date' => '2026-09-03', 'holder' => 'UJI Tanpa Departemen — Staff Uji',
        'purpose' => 'UJI tanpa departemen', 'amount' => 250000,
        'employee_id' => $tanpaDept, 'cost_center_id' => $ccUji,
    ], (int) $user->id);
    $cek('pengajuannya tetap tersimpan', $adv2 !== null);
    $cek('departemennya kosong, bukan galat',
        DB::table('bs_cash_advances')->where('id', $adv2->id)->value('department_id') === null);

    echo "\n=== 6. Laporan masih menerima daftar departemennya ===\n";

    // Daftar departemen dilepas dari layar PENGAJUAN saja; layar Laporan masih memerlukannya
    // untuk mengisi dropdown penyaringnya.
    $req = Request::create('/r', 'GET');
    $req->setUserResolver(fn () => $user);
    $req->headers->set('X-Inertia', 'true');
    $req->headers->set('X-Inertia-Version', (string) \Inertia\Inertia::getVersion());
    $isiLap = json_decode((string) $ctrl->report($req)->toResponse($req)->getContent(), true) ?: [];
    $cek('Laporan Bon Sementara tetap menerima daftar departemen',
        is_array($isiLap['props']['departments'] ?? null) && count($isiLap['props']['departments']) > 0,
        json_encode(array_keys($isiLap['props'] ?? [])));

    echo "\n".str_repeat('─', 78)."\n";
    echo $gagal === 0 ? "SEMUA LULUS ({$lulus})\n" : "GAGAL: {$gagal}, lulus: {$lulus}\n";
} catch (\Throwable $e) {
    echo "\nGALAT: ".$e->getMessage()."\n  di ".$e->getFile().':'.$e->getLine()."\n";
    $gagal++;
} finally {
    DB::rollBack();
    echo "(data uji dibatalkan)\n";
}

exit($gagal === 0 ? 0 : 1);
