<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/** Buat permission {area}.{action} dari config/access_matrix.php (idempotent). */
class AccessMatrixSeeder extends Seeder
{
    public function run(): void
    {
        $cfg = config('access_matrix');
        $actions = array_keys($cfg['actions']);

        foreach ($cfg['modules'] as $areas) {
            foreach ($areas as $areaKey => $label) {
                foreach ($actions as $action) {
                    Permission::firstOrCreate(['name' => $areaKey.'.'.$action, 'guard_name' => 'web']);
                }
            }
        }
    }
}
