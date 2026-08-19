<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsHrDepartment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pindahkan "Divisi" karyawan dari cost center ke DEPARTEMEN.
 *
 * Employee Center dulu menyimpan divisi karyawan pada bs_employees.cost_center_id — menunjuk
 * tabel Cost Center. Sementara Pengajuan Bon Sementara, SIP, dan Bukti Permohonan Pembayaran
 * membaca bs_employees.department_id → bs_hr_departments. Dua kolom berbeda, dua tabel
 * berbeda, sehingga department_id SELALU kosong dan ketiga layar itu tidak pernah bisa
 * mengisi departemen karyawannya sendiri.
 *
 * Perintah ini memindahkan isinya, bukan mengarangnya: untuk tiap cost center yang benar-benar
 * dipakai karyawan, dibuatkan departemen bernama & berkode SAMA, lalu karyawannya ditautkan
 * ke sana. Apa yang selama ini tampil di layar tetap tampil — hanya pindah ke tabel yang
 * memang dibaca modul lain.
 *
 * cost_center_id-nya TIDAK dihapus. Kalau pemindahan ini keliru, datanya masih utuh.
 *
 * Idempoten: departemen dicocokkan lewat kode, karyawan yang sudah berdepartemen dilewati.
 */
final class PindahDivisiKaryawanCommand extends Command
{
    protected $signature = 'karyawan:pindah-divisi-ke-departemen
        {--apply : Tulis ke database. Tanpa ini perintahnya hanya melaporkan rencana.}';

    protected $description = 'Bawa Divisi (cost center) karyawan menjadi Departemen, sumber yang dibaca Bon Sementara & SIP.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $karyawan = BsEmployee::with('costCenter:id,code,name')
            ->whereNotNull('cost_center_id')->whereNull('department_id')->get();

        if ($karyawan->isEmpty()) {
            $this->info('  Tidak ada karyawan yang perlu dipindahkan — semuanya sudah berdepartemen atau memang tanpa divisi.');

            return self::SUCCESS;
        }

        $perCc = $karyawan->groupBy('cost_center_id');
        $deptBaru = 0;
        $ditautkan = 0;

        DB::transaction(function () use ($perCc, $apply, &$deptBaru, &$ditautkan): void {
            foreach ($perCc as $rows) {
                $cc = $rows->first()->costCenter;
                if (! $cc) {
                    continue;
                }

                $dept = BsHrDepartment::where('code', $cc->code)->first();
                if (! $dept) {
                    $deptBaru++;
                    $this->line('  + departemen '.$cc->code.' — '.$cc->name);
                    if ($apply) {
                        $dept = BsHrDepartment::create(['code' => $cc->code, 'name' => $cc->name, 'is_active' => true]);
                    }
                }

                foreach ($rows as $e) {
                    $ditautkan++;
                    $this->line('      '.str_pad((string) $e->nik, 10).$e->name.'  →  '.$cc->name);
                    if ($apply && $dept) {
                        $e->update(['department_id' => $dept->id]);
                    }
                }
            }

            if (! $apply) {
                // Rencana saja: batalkan apa pun yang tanpa sengaja tersentuh.
                DB::rollBack();
            }
        });

        $this->newLine();
        $this->line('  Departemen baru : '.$deptBaru);
        $this->line('  Karyawan ditaut : '.$ditautkan);
        $this->newLine();

        if (! $apply) {
            $this->warn('  Belum ada yang ditulis. Jalankan ulang dengan --apply untuk menyimpannya.');

            return self::SUCCESS;
        }
        $this->info('  Selesai — Departemen karyawan kini terbaca Pengajuan Bon Sementara, SIP, dan BPP.');

        return self::SUCCESS;
    }
}
