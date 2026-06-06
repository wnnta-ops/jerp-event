<?php

namespace Tests\Feature\FO;

use App\Models\Company;
use App\Models\Project;
use App\Modules\FO\Adminjual\Imports\UnitImport;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Models\UnitType;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class UnitImportTipeTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_tipe_column_creates_unit_type_and_links_unit(): void
    {
        $this->seed(TenantSeeder::class);
        $company = Company::create(['tenant_id' => 1, 'code' => 'MUP', 'name' => 'PT MUP', 'is_active' => true]);
        $project = Project::create(['tenant_id' => 1, 'company_id' => $company->id, 'code' => 'JR', 'name' => 'Java Residence', 'is_active' => true]);
        $lokasi = Lokasi::create(['tenant_id' => 1, 'project_id' => $project->id, 'kode' => 'JRK3', 'nama' => 'JRK 3', 'is_active' => true]);
        Tahap::create(['tenant_id' => 1, 'lokasi_id' => $lokasi->id, 'kode' => '004', 'nama' => 'Tahap 004', 'is_active' => true]);

        // Two rows, same Tipe → one shared UnitType; second tipe → its own type.
        $rows = new Collection([
            new Collection(['lokasi' => 'JRK3', 'tahap' => '004', 'blok' => 'XVII', 'nomor' => '17', 'tipe' => 'CAMAR 6 X 15', 'price_list_rumah' => 559000000, 'luas_tanah' => 90, 'luas_bangunan' => 36]),
            new Collection(['lokasi' => 'JRK3', 'tahap' => '004', 'blok' => 'XVII', 'nomor' => '18', 'tipe' => 'CAMAR 6 X 15', 'price_list_rumah' => 559000000]),
            new Collection(['lokasi' => 'JRK3', 'tahap' => '004', 'blok' => 'XVIII', 'nomor' => '1', 'tipe' => 'AKASIA 6X15', 'price_list_rumah' => 525000000]),
        ]);

        $import = new UnitImport($project->id, 1);
        $import->collection($rows);

        $this->assertSame([], $import->errors, 'Import errors: '.implode(' | ', $import->errors));

        // Master types auto-created (deduped): CAMAR + AKASIA = 2.
        $this->assertSame(2, UnitType::query()->where('project_id', $project->id)->count());

        $unit = Unit::query()->where('blok', 'XVII')->where('no_unit', '17')->firstOrFail();
        $this->assertNotNull($unit->unit_type_id);
        $this->assertSame('CAMAR 6 X 15', $unit->unitType->name);

        // Second unit reuses the same type id (dedup by normalised code).
        $unit2 = Unit::query()->where('blok', 'XVII')->where('no_unit', '18')->firstOrFail();
        $this->assertSame($unit->unit_type_id, $unit2->unit_type_id);

        // Type carries the dimensions captured from the first row.
        $camar = UnitType::query()->where('project_id', $project->id)->where('name', 'CAMAR 6 X 15')->firstOrFail();
        $this->assertEqualsWithDelta(90, (float) $camar->luas_tanah, 0.01);
    }
}
