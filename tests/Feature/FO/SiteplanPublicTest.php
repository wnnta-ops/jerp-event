<?php

namespace Tests\Feature\FO;

use App\Models\Company;
use App\Models\Project;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Models\UnitPricelist;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteplanPublicTest extends TestCase
{
    use RefreshDatabase;

    private function seedSiteplan(): Project
    {
        $this->seed(TenantSeeder::class);
        $company = Company::create(['tenant_id' => 1, 'code' => 'MUP', 'name' => 'PT MUP', 'is_active' => true]);

        $project = Project::create([
            'tenant_id' => 1, 'company_id' => $company->id, 'code' => 'JRK', 'name' => 'Java Residence Krian',
            'address' => 'Jl. Java Residence Krian Raya', 'is_active' => true,
        ]);
        $project->forceFill([
            'siteplan_image_path' => 'siteplans/jrk.png',
            'siteplan_width' => 2000, 'siteplan_height' => 1200,
        ])->save();

        $lokasi = Lokasi::create([
            'tenant_id' => 1, 'project_id' => $project->id,
            'kode' => 'JRK3', 'nama' => 'Java Residence Krian 3', 'is_active' => true,
        ]);

        $unit = Unit::create([
            'tenant_id' => 1, 'project_id' => $project->id, 'lokasi_id' => $lokasi->id,
            'blok' => 'A', 'no_unit' => '25', 'cluster' => 'AKASIA',
            'alamat' => 'JL. JAVA RESIDENCE KRIAN RAYA NO. 25',
            'luas_tanah' => 90, 'luas_bangunan' => 36,
            'harga_jual' => 524_000_000,
            'status' => 'TERJUAL', 'is_active' => true,
            'siteplan_coords' => [[100, 100], [200, 100], [200, 200], [100, 200]],
        ]);

        // Harga via skema bayar (model normalisasi) — bukan kolom tetap di unit.
        $skemaKpr = Skema::create(['tenant_id' => 1, 'project_id' => $project->id, 'code' => 'KPR', 'name' => 'KPR', 'cara_bayar' => 'KPR', 'tampil_siteplan' => true, 'is_active' => true]);
        $skemaCash = Skema::create(['tenant_id' => 1, 'project_id' => $project->id, 'code' => 'CASH', 'name' => 'CASH', 'cara_bayar' => 'CASH_KERAS', 'tampil_siteplan' => true, 'is_active' => true]);
        // Skema disembunyikan dari siteplan (tampil_siteplan = false) → tidak muncul di popup.
        $skemaInternal = Skema::create(['tenant_id' => 1, 'project_id' => $project->id, 'code' => 'INTERNAL', 'name' => 'HARGA INTERNAL', 'cara_bayar' => 'KPR', 'tampil_siteplan' => false, 'is_active' => true]);
        UnitPricelist::create(['tenant_id' => 1, 'unit_id' => $unit->id, 'skema_id' => $skemaKpr->id, 'price_list' => 460_750_000]);
        UnitPricelist::create(['tenant_id' => 1, 'unit_id' => $unit->id, 'skema_id' => $skemaCash->id, 'price_list' => 440_000_000]);
        UnitPricelist::create(['tenant_id' => 1, 'unit_id' => $unit->id, 'skema_id' => $skemaInternal->id, 'price_list' => 999_888_777]);

        return $project;
    }

    public function test_public_siteplan_renders_without_auth(): void
    {
        $this->seedSiteplan();

        $response = $this->get('/siteplan/JRK');

        $response->assertSuccessful();
        $response->assertSee('Java Residence Krian');
        $response->assertSee('jrk.png');                       // image overlay wired (slash escaped by @json)
        $response->assertSee('JAVA RESIDENCE KRIAN RAYA');     // address present in units JSON
        $response->assertSee('Java Residence Krian 3');        // lokasi nama (from fo_lokasi master), not project code
        $response->assertSee('KPR');                           // skema-based prices in popup pricelist
        $response->assertSee('CASH');
        $response->assertSee('460750000');                     // skema KPR price (fo_unit_price_lists)
        $response->assertDontSee('HARGA INTERNAL');            // skema tampil_siteplan=false → tersembunyi
        $response->assertDontSee('999888777');
    }

    public function test_index_jumps_to_single_project(): void
    {
        $this->seedSiteplan();

        $this->get('/siteplan')->assertSuccessful()->assertSee('Java Residence Krian');
    }

    public function test_unknown_project_404(): void
    {
        $this->seedSiteplan();

        $this->get('/siteplan/NOPE')->assertNotFound();
    }
}
