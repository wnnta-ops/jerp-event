<?php

namespace App\Console\Commands\Akses;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Usulkan pemetaan peran → izin, lalu terapkan bila disetujui.
 *
 * Usulannya diturunkan dari NAMA peran, bukan dari pengetahuan tentang siapa
 * mengerjakan apa — itu hanya diketahui orang di perusahaan. Karena itu perintah
 * ini menampilkan usulan dulu dan baru mengubah data saat diberi --terapkan.
 *
 * Izin panel (panel.fo, panel.bo, …) ikut dibuat di sini: sebelumnya gerbang panel
 * hanya memeriksa is_active sehingga izin seperti itu belum pernah ada.
 */
class PetakanAksesCommand extends Command
{
    protected $signature = 'akses:petakan {--terapkan : Simpan usulan ini ke database}';

    protected $description = 'Usulkan (dan opsional terapkan) pemetaan peran → izin, termasuk izin panel.';

    /**
     * Peran → awalan izin yang boleh diaksesnya. Kunci dicocokkan sebagai AWALAN
     * nama peran, jadi 'fin' juga mencakup 'fin_01'.
     */
    private const USULAN = [
        'super_admin' => ['*'],
        // 'sup' = superuser, akses penuh (dikonfirmasi pemilik sistem).
        'sup' => ['*'],
        'it' => ['*'],
        'direktur' => ['fo.', 'bo.', 'bankloan.', 'panel.fo', 'panel.bo'],
        'auditor' => ['fo.', 'bo.', 'panel.fo', 'panel.bo'],
        'finance_manager' => ['fo.tts', 'fo.bkm', 'fo.bg', 'fo.memo', 'fo.fp', 'fo.refund', 'fo.va', 'fo.transfer_anonim', 'bo.', 'panel.fo', 'panel.bo'],
        'finance_staff' => ['fo.tts', 'fo.bkm', 'fo.bg', 'fo.memo', 'fo.fp', 'fo.va', 'panel.fo'],
        'fin' => ['fo.tts', 'fo.bkm', 'fo.bg', 'fo.memo', 'fo.fp', 'fo.va', 'panel.fo'],
        'acc' => ['bo.', 'panel.bo'],
        'accounting' => ['bo.', 'panel.bo'],
        'sales_manager' => ['fo.nup', 'fo.verifikasi_nup', 'fo.verifikasi_booked', 'fo.customer', 'fo.reservasi', 'fo.kontrak', 'fo.spr', 'fo.pilih_unit', 'fo.aktivasi_nup', 'fo.closing_nup', 'panel.fo'],
        'sales_staff' => ['fo.nup', 'fo.customer', 'fo.reservasi', 'fo.pilih_unit', 'panel.fo'],
        'mkt' => ['fo.nup', 'fo.customer', 'fo.reservasi', 'fo.pilih_unit', 'panel.fo'],
        'kasir_launching' => ['fo.nup', 'fo.aktivasi_nup', 'fo.pilih_unit', 'fo.tts', 'panel.fo'],
        'collector' => ['fo.customer', 'fo.kontrak', 'panel.fo'],
        'legal_staff' => ['fo.kontrak', 'fo.customer', 'panel.fo'],
        'lgl' => ['fo.kontrak', 'fo.customer', 'panel.fo'],
        'kpr' => ['bankloan.', 'fo.kontrak', 'panel.fo'],
        'kpr_officer' => ['bankloan.', 'fo.kontrak', 'panel.fo'],
        'hrga' => ['bo.hr', 'panel.bo'],
        'it_admin' => ['*'],
    ];

    public function handle(): int
    {
        $this->buatIzinPanel();

        $semua = Permission::pluck('name');
        $baris = [];
        $rencana = [];

        foreach (Role::orderBy('name')->get() as $peran) {
            $awalan = $this->awalanUntuk($peran->name);

            if ($awalan === null) {
                $baris[] = [$peran->name, $peran->permissions->count(), '—', '<fg=yellow>tanpa usulan</>'];

                continue;
            }

            $cocok = in_array('*', $awalan, true)
                ? $semua->all()
                : $semua->filter(fn ($n) => collect($awalan)->contains(fn ($a) => str_starts_with($n, $a)))->values()->all();

            $rencana[$peran->name] = $cocok;
            $baris[] = [$peran->name, $peran->permissions->count(), count($cocok),
                count($cocok) === $peran->permissions->count() ? 'tetap' : '<fg=green>berubah</>'];
        }

        $this->newLine();
        $this->line('  <options=bold>USULAN PEMETAAN PERAN → IZIN</>');
        $this->table(['Peran', 'Izin sekarang', 'Usulan', 'Status'], $baris);

        $tanpa = collect($baris)->filter(fn ($b) => str_contains((string) $b[3], 'tanpa usulan'))->count();
        if ($tanpa) {
            $this->newLine();
            $this->line("  <fg=yellow>{$tanpa} peran tidak punya usulan</> — namanya tidak dikenali.");
            $this->line('  Peran seperti itu DIBIARKAN apa adanya, bukan dikosongkan.');
        }

        if (! $this->option('terapkan')) {
            $this->newLine();
            $this->line('  Belum ada yang diubah. Jalankan ulang dengan <fg=cyan>--terapkan</fg=cyan> untuk menyimpan.');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach ($rencana as $namaPeran => $izin) {
            Role::where('name', $namaPeran)->first()?->syncPermissions($izin);
        }

        $this->newLine();
        $this->info('  Pemetaan diterapkan untuk '.count($rencana).' peran.');
        $this->line('  Penegakan masih mengikuti AKSES_TEGAKKAN di .env (default: mati).');
        $this->newLine();

        return self::SUCCESS;
    }

    private function buatIzinPanel(): void
    {
        foreach ((array) config('akses.panel') as $izin) {
            Permission::findOrCreate($izin, 'web');
        }
    }

    /** @return array<int,string>|null */
    private function awalanUntuk(string $peran): ?array
    {
        // Cocokkan yang paling spesifik lebih dulu supaya 'finance_manager' tidak
        // tertangkap oleh 'fin'.
        $kunci = collect(array_keys(self::USULAN))->sortByDesc(fn ($k) => strlen($k));

        foreach ($kunci as $k) {
            if (str_starts_with($peran, $k)) {
                return self::USULAN[$k];
            }
        }

        return null;
    }
}
