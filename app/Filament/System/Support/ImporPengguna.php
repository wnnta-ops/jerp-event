<?php

namespace App\Filament\System\Support;

use App\Models\Bank;
use App\Models\Bo\BoCostCenter;
use App\Models\Bo\BoRole;
use App\Models\Bo\BoUser;
use App\Models\User;
use App\Modules\BO\IproBridge\Services\IproMasterSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Import Excel Pengguna (panel System → Pengguna).
 *
 * Satu baris = satu pengguna, dicocokkan lewat EMAIL: yang sudah ada diperbarui, yang
 * belum dibuat. BERKAS ADALAH ACUAN:
 *  - grup_akses / grup_akses_bo yang DIISI menggantikan grup pengguna itu persis;
 *    sel kosong = grupnya tidak diubah.
 *  - Kolom data lain yang kosong = data lama dibiarkan (pada pengguna yang sudah ada).
 *  - Data karyawan di BO (Employee Center) ikut disesuaikan dengan berkas, termasuk
 *    karyawan yang sudah ada — pengecualian sadar dari aturan sinkron biasa.
 *  - Password hanya dipakai untuk pengguna BARU; pengguna lama tidak tertimpa passwordnya
 *    (mereka bisa sudah menggantinya sendiri).
 *
 * Seluruh berkas diperiksa LEBIH DULU; satu baris salah = tidak ada yang disimpan.
 */
class ImporPengguna
{
    public const KOLOM = [
        'nama_lengkap', 'username', 'email', 'password', 'nik', 'cost_center', 'bank',
        'no_rekening', 'npwp', 'grup_akses', 'grup_akses_bo', 'aktif',
    ];

    /** Kolom yang wajib ditulis sebagai TEKS di Excel (angka panjang rusak bila jadi number). */
    private const KOLOM_TEKS = ['nik', 'no_rekening', 'npwp', 'password', 'username'];

    /** Berkas contoh: sheet Pengguna (judul + satu contoh) dan sheet Daftar (nilai yang sah). */
    public static function format(): string
    {
        $buku = new Spreadsheet;
        $s = $buku->getActiveSheet()->setTitle('Pengguna');
        foreach (self::KOLOM as $i => $k) {
            $s->setCellValue([$i + 1, 1], $k);
            $s->getStyle([$i + 1, 1])->getFont()->setBold(true);
            // Seluruh kolom diformat TEKS: NIK 16 digit yang diketik sebagai angka berubah
            // menjadi 3,5E+15 dan digit belakangnya hilang selamanya.
            $s->getStyle([$i + 1, 1, $i + 1, 500])->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            $s->getColumnDimensionByColumn($i + 1)->setAutoSize(true);
        }
        $contoh = [
            'nama_lengkap' => 'CONTOH NAMA KARYAWAN', 'username' => 'contoh', 'email' => 'contoh@email.com',
            'password' => 'GantiSaya2026', 'nik' => '3515000000000001',
            'cost_center' => (string) (BoCostCenter::query()->where('is_active', true)->orderBy('code')->value('code') ?? ''),
            'bank' => (string) (Bank::orderBy('name')->value('name') ?? ''), 'no_rekening' => '1234567890', 'npwp' => '',
            'grup_akses' => (string) (self::modelPeran()::query()->orderBy('name')->value('name') ?? ''),
            'grup_akses_bo' => (string) (BoRole::query()->orderBy('name')->value('name') ?? ''), 'aktif' => '1',
        ];
        foreach (self::KOLOM as $i => $k) {
            $s->setCellValueExplicit([$i + 1, 2], $contoh[$k], DataType::TYPE_STRING);
        }

        $d = $buku->createSheet()->setTitle('Daftar');
        $daftar = [
            'grup_akses (FO)' => self::modelPeran()::query()->orderBy('name')->pluck('name')->all(),
            'grup_akses_bo' => BoRole::query()->orderBy('name')->pluck('name')->all(),
            'cost_center (kode)' => BoCostCenter::query()->where('is_active', true)->orderBy('code')->get()
                ->map(fn ($c) => $c->code)->all(),
            'cost_center (nama)' => BoCostCenter::query()->where('is_active', true)->orderBy('code')->pluck('name')->all(),
            'bank' => Bank::orderBy('name')->pluck('name')->all(),
        ];
        $kol = 1;
        foreach ($daftar as $judul => $isi) {
            $d->setCellValue([$kol, 1], $judul);
            $d->getStyle([$kol, 1])->getFont()->setBold(true);
            foreach (array_values($isi) as $r => $v) {
                $d->setCellValueExplicit([$kol, $r + 2], (string) $v, DataType::TYPE_STRING);
            }
            $d->getColumnDimensionByColumn($kol)->setAutoSize(true);
            $kol++;
        }
        $d->setCellValue([$kol + 1, 1], 'Beberapa grup ditulis dipisah koma, mis. "fin_01, kpr". Grup kosong = tidak diubah.');
        $buku->setActiveSheetIndex(0);

        $berkas = tempnam(sys_get_temp_dir(), 'pengguna').'.xlsx';
        (new Xlsx($buku))->save($berkas);

        return $berkas;
    }

    /**
     * @return array{baru:int,ubah:int,karyawan_baru:int,karyawan_ubah:int,gagal_bo:list<string>}
     *
     * @throws RuntimeException  berisi daftar galat per baris; tidak ada yang tersimpan
     */
    public static function jalankan(string $berkas, ?User $pelaku): array
    {
        $baris = self::baca($berkas);
        $siap = self::periksa($baris);

        $hasil = ['baru' => 0, 'ubah' => 0, 'karyawan_baru' => 0, 'karyawan_ubah' => 0, 'gagal_bo' => []];
        $tenant = (int) ($pelaku?->tenant_id ?? 1);

        // 1) Pengguna FO — satu transaksi: semua atau tidak sama sekali.
        $pengguna = DB::transaction(function () use ($siap, $tenant, &$hasil) {
            $tersimpan = [];
            foreach ($siap as $r) {
                $u = $r['user'] ?? new User(['tenant_id' => $tenant, 'is_active' => true]);
                $baru = ! $u->exists;
                $u->full_name = $r['nama_lengkap'];
                $u->email = $r['email'];
                if ($r['username'] !== '' || $baru) {
                    $u->name = $r['username'] !== '' ? $r['username'] : Str::before($r['email'], '@');
                }
                if ($baru) {
                    $u->password = $r['password'];   // cast 'hashed'
                }
                foreach (['nik' => 'nik', 'npwp' => 'npwp', 'rekening_number' => 'no_rekening'] as $kolom => $k) {
                    if ($r[$k] !== '') {
                        $u->{$kolom} = $r[$k];
                    }
                }
                if ($r['bank_id'] !== null) {
                    $u->bank_id = $r['bank_id'];
                }
                if ($r['cost_center_id'] !== null) {
                    $u->bo_cost_center_id = $r['cost_center_id'];
                }
                if ($r['aktif'] !== null) {
                    $u->is_active = $r['aktif'];
                }
                // Grup BO dari berkas = dipegang admin; sinkron peran FO→BO tidak boleh menimpanya.
                if ($r['grup_bo_ids'] !== null) {
                    $u->bo_roles_manual = true;
                }
                $u->save();
                if ($r['grup_fo'] !== null) {
                    $u->syncRoles($r['grup_fo']);
                }
                $baru ? $hasil['baru']++ : $hasil['ubah']++;
                $tersimpan[] = [$u, $r];
            }

            return $tersimpan;
        });

        // 2) BO — database lain, jadi sesudah commit. Gagal di sini tidak membatalkan pengguna
        //    FO yang sudah tersimpan; dilaporkan per orang supaya bisa diulang lewat
        //    "Sinkronkan ke BO".
        $sync = app(IproMasterSync::class);
        foreach ($pengguna as [$u, $r]) {
            try {
                $karyawanAda = $r['nik'] !== '' && DB::connection('ipro_bo')->table('bs_employees')->where('nik', $r['nik'])->exists();
                $boId = $sync->syncUser($u);
                if ($boId && $r['grup_bo_ids'] !== null) {
                    BoUser::find($boId)?->roles()->sync($r['grup_bo_ids']);
                }
                if ($r['nik'] !== '') {
                    if ($karyawanAda) {
                        $sync->perbaruiKaryawan($r['nik'], array_filter([
                            'name' => $r['nama_lengkap'],
                            'cost_center_id' => $r['cost_center_id'],
                            'bank_name' => $r['bank_nama'],
                            'bank_account' => $r['no_rekening'] !== '' ? $r['no_rekening'] : null,
                            'npwp' => $r['npwp'] !== '' ? $r['npwp'] : null,
                        ], fn ($v) => $v !== null));
                        $hasil['karyawan_ubah']++;
                    } elseif (DB::connection('ipro_bo')->table('bs_employees')->where('nik', $r['nik'])->exists()) {
                        $hasil['karyawan_baru']++;
                    }
                }
            } catch (\Throwable $e) {
                $hasil['gagal_bo'][] = $u->email.' ('.Str::limit($e->getMessage(), 120).')';
            }
        }

        return $hasil;
    }

    /** @return list<array<string,string>>  baris berkunci nama kolom, nomor baris di '_no' */
    private static function baca(string $berkas): array
    {
        try {
            $buku = IOFactory::load($berkas);
        } catch (\Throwable) {
            throw new RuntimeException('Berkas tidak bisa dibaca. Pastikan formatnya Excel (.xlsx).');
        }
        $sheet = $buku->getSheetByName('Pengguna') ?? $buku->getSheet(0);
        $isi = $sheet->toArray(null, true, false, false);
        if ($isi === []) {
            throw new RuntimeException('Berkas kosong.');
        }

        $kepala = array_map(fn ($x) => Str::of((string) $x)->lower()->trim()->replace(' ', '_')->toString(), array_shift($isi));
        $hilang = array_diff(['nama_lengkap', 'email'], $kepala);
        if ($hilang !== []) {
            throw new RuntimeException('Baris judul tidak dikenali (kolom '.implode(', ', $hilang).' tidak ada). Unduh format untuk contoh susunannya.');
        }

        $hasil = [];
        foreach ($isi as $i => $b) {
            $r = ['_no' => (string) ($i + 2)];
            foreach (self::KOLOM as $k) {
                $pos = array_search($k, $kepala, true);
                $v = $pos === false ? null : ($b[$pos] ?? null);
                // Angka panjang yang terlanjur jadi number di Excel sudah kehilangan digitnya.
                if (is_float($v) && in_array($k, self::KOLOM_TEKS, true) && $v >= 1e15) {
                    $r['_angka_rusak'][] = $k;
                }
                $r[$k] = is_float($v) && floor($v) == $v ? number_format($v, 0, '', '') : trim((string) $v);
            }
            if (implode('', array_intersect_key($r, array_flip(self::KOLOM))) === '') {
                continue;   // baris kosong
            }
            $hasil[] = $r;
        }
        if ($hasil === []) {
            throw new RuntimeException('Tidak ada baris pengguna di berkas itu.');
        }

        return $hasil;
    }

    /** @param list<array<string,mixed>> $baris */
    private static function periksa(array $baris): array
    {
        $peranFo = self::modelPeran()::query()->pluck('name')->mapWithKeys(fn ($n) => [Str::lower($n) => $n])->all();
        $peranBo = BoRole::query()->get(['id', 'name'])->mapWithKeys(fn ($r) => [Str::lower($r->name) => (int) $r->id])->all();
        $cc = [];
        foreach (BoCostCenter::query()->where('is_active', true)->get(['id', 'code', 'name']) as $c) {
            $cc[Str::lower((string) $c->code)] = (int) $c->id;
            $cc[Str::lower((string) $c->name)] ??= (int) $c->id;
        }
        $bank = Bank::query()->get(['id', 'name'])->mapWithKeys(fn ($b) => [Str::lower($b->name) => [(int) $b->id, $b->name]])->all();

        $galat = [];
        $siap = [];
        $emailDiBerkas = [];
        $nikDiBerkas = [];
        foreach ($baris as $r) {
            $no = $r['_no'];
            // Closure ber-referensi, BUKAN arrow fn: arrow fn menyalin $galat, sehingga galatnya
            // hilang dan berkas yang salah lolos tersimpan.
            $salah = function (string $p) use (&$galat, $no): void { $galat[] = "Baris {$no}: {$p}"; };

            foreach ($r['_angka_rusak'] ?? [] as $k) {
                $salah("{$k} tersimpan sebagai angka sehingga digit belakangnya hilang — format selnya sebagai Teks lalu ketik ulang.");
            }
            $email = Str::lower($r['email']);
            if ($r['nama_lengkap'] === '') { $salah('nama_lengkap kosong.'); }
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) { $salah("email \"{$r['email']}\" tidak valid."); continue; }
            if (isset($emailDiBerkas[$email])) { $salah("email {$email} sudah ada di baris {$emailDiBerkas[$email]}."); continue; }
            $emailDiBerkas[$email] = $no;

            $user = User::query()->where('email', $email)->first();
            if (! $user && $r['password'] === '') { $salah('pengguna baru wajib diisi password.'); }
            if (! $user && $r['nik'] === '') { $salah('pengguna baru wajib diisi nik.'); }

            if ($r['nik'] !== '') {
                if (isset($nikDiBerkas[$r['nik']])) { $salah("nik {$r['nik']} sudah dipakai di baris {$nikDiBerkas[$r['nik']]}."); }
                $nikDiBerkas[$r['nik']] = $no;
                $pemilik = User::query()->where('nik', $r['nik'])->where('email', '!=', $email)->value('email');
                if ($pemilik) { $salah("nik {$r['nik']} sudah dipakai pengguna {$pemilik}."); }
            }

            $ccId = null;
            if ($r['cost_center'] !== '') {
                $ccId = $cc[Str::lower($r['cost_center'])] ?? null;
                if ($ccId === null) { $salah("cost_center \"{$r['cost_center']}\" tidak ada (lihat sheet Daftar)."); }
            }
            [$bankId, $bankNama] = [null, null];
            if ($r['bank'] !== '') {
                [$bankId, $bankNama] = $bank[Str::lower($r['bank'])] ?? [null, null];
                if ($bankId === null) { $salah("bank \"{$r['bank']}\" tidak ada di master Bank (lihat sheet Daftar)."); }
            }

            $pecah = fn (string $s) => array_values(array_filter(array_map('trim', explode(',', $s)), fn ($x) => $x !== ''));
            $grupFo = null;
            if ($r['grup_akses'] !== '') {
                $grupFo = [];
                foreach ($pecah($r['grup_akses']) as $g) {
                    isset($peranFo[Str::lower($g)]) ? $grupFo[] = $peranFo[Str::lower($g)] : $salah("grup_akses \"{$g}\" tidak ada.");
                }
            }
            $grupBo = null;
            if ($r['grup_akses_bo'] !== '') {
                $grupBo = [];
                foreach ($pecah($r['grup_akses_bo']) as $g) {
                    isset($peranBo[Str::lower($g)]) ? $grupBo[] = $peranBo[Str::lower($g)] : $salah("grup_akses_bo \"{$g}\" tidak ada.");
                }
            }

            $aktif = null;
            if ($r['aktif'] !== '') {
                $aktif = ! in_array(Str::lower($r['aktif']), ['0', 'tidak', 'no', 'nonaktif', 'false', 'n'], true);
            }

            // array_merge, bukan `+`: `+` mempertahankan kunci kiri, jadi email yang sudah
            // dikecilkan dan aktif yang sudah diurai tidak akan menggantikan teks mentahnya.
            $siap[] = array_merge($r, [
                'email' => $email, 'user' => $user, 'cost_center_id' => $ccId, 'bank_id' => $bankId, 'bank_nama' => $bankNama,
                'grup_fo' => $grupFo, 'grup_bo_ids' => $grupBo, 'aktif' => $aktif,
            ]);
        }

        if ($galat !== []) {
            throw new RuntimeException("Import dibatalkan, tidak ada data yang diubah.\n".implode("\n", array_slice($galat, 0, 15))
                .(count($galat) > 15 ? "\n… dan ".(count($galat) - 15).' galat lain.' : ''));
        }

        return $siap;
    }

    /** @return class-string<\Spatie\Permission\Models\Role> */
    private static function modelPeran(): string
    {
        return config('permission.models.role');
    }
}
