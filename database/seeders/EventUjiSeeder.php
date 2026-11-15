<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Akun uji coba modul Event — KHUSUS lokal/testing.
 *
 *   event.super@jerp.test    peran super_admin (hak sama dengan admin)
 *   event.scanner@jerp.test  tanpa peran; hak scan diberikan per event lewat
 *                            langkah Akses di wizard (et_access)
 *
 * Password dibaca dari .env (EVENT_UJI_PASSWORD_SUPER / EVENT_UJI_PASSWORD_SCANNER),
 * tidak pernah ditulis di kode. Dibuat tanpa event model supaya User::saved tidak
 * ikut membuat akun di BO (IproMasterSync).
 *
 *   php artisan db:seed --class=EventUjiSeeder
 */
class EventUjiSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('EventUjiSeeder hanya untuk lingkungan lokal/testing.');
        }

        $akun = [
            ['event.super@jerp.test', 'Uji Event · Superadmin', 'EVENT_UJI_PASSWORD_SUPER', 'super_admin'],
            ['event.scanner@jerp.test', 'Uji Event · Scanner', 'EVENT_UJI_PASSWORD_SCANNER', null],
        ];

        foreach ($akun as [$email, $nama, $kunciEnv, $peran]) {
            $password = (string) env($kunciEnv, '');
            if (strlen($password) < 12) {
                throw new RuntimeException("{$kunciEnv} belum diisi di .env (min. 12 karakter).");
            }

            $user = User::withoutEvents(function () use ($email, $nama, $password) {
                $u = User::query()->firstOrNew(['email' => $email]);
                $u->forceFill([
                    'tenant_id' => 1,
                    'name' => $nama,
                    'full_name' => $nama,
                    'password' => Hash::make($password),
                    'is_active' => true,
                ])->save();

                return $u;
            });

            if ($peran !== null) {
                $user->syncRoles([Role::findOrCreate($peran, 'web')]);
            }

            $this->command?->info("  {$email} siap".($peran ? " (peran {$peran})" : ' (tanpa peran)'));
        }
    }
}
