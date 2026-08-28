<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Foundation\Tenant\Models\Cluster;
use App\Domain\Foundation\Tenant\Models\Company;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        $tenant  = Tenant::where('code', 'MUP')->firstOrFail();
        $company = Company::where('code', 'MUP')->firstOrFail();

        $project = Project::updateOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'JRES'],
            [
                'company_id'    => $company->id,
                'name'          => 'Java Residence',
                'location'      => 'Bandung, Jawa Barat',
                'launched_at'   => '2024-01-01',
                'siteplan_meta' => ['canvas_w' => 1200, 'canvas_h' => 800],
                'is_active'     => true,
            ],
        );

        // Cluster TIDAK di-seed lagi.
        //
        // Cluster adalah cerminan master Lokasi di FO (Setup Unit → Lokasi) dan satu-satunya
        // penulisnya IproMasterSync::syncLokasi() di FO. "Cluster A/B/C" yang dulu di-seed di
        // sini muncul sebagai pilihan pada Setup Kategori Gimmick — tiga cluster yang tidak
        // pernah ada di Master Unit. Kategori yang telanjur ditautkan ke salah satunya tidak
        // akan pernah cocok saat Posting Invoice dari FO, karena FO hanya mengenal Lokasi yang
        // sesungguhnya, sehingga biayanya diam-diam jatuh ke kategori cadangan.
        //
        // Isi daftarnya dengan:  php artisan ipro:backfill lokasi   (dijalankan DI FO)
    }
}
