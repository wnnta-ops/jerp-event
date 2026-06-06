<?php

namespace Tests\Feature\FO;

use App\Models\Project;
use App\Modules\FO\Adminjual\Imports\UnitImport;
use App\Modules\FO\Adminjual\Support\UnitCodeParser;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use App\Modules\FO\MarketingSales\Models\Unit;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * No. Unit kanonik {Lokasi(kode project)}-{Tahap}-{Blok}-{Nomer} dibangun otomatis
 * saat unit disimpan (upload / edit) dan dipakai sebagai identitas lintas modul.
 */
class UnitKodeUnitTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);
        $this->project = Project::query()->where('code', 'JR')->firstOrFail();
    }

    private function makeUnit(array $overrides = []): Unit
    {
        return Unit::create(array_merge([
            'project_id' => $this->project->id,
            'tahap' => '004',
            'blok' => 'XV',
            'no_unit' => '2',
            'harga_jual' => 0,
            'status' => 'TERSEDIA',
            'is_active' => true,
        ], $overrides));
    }

    public function test_kode_unit_auto_generated_lokasi_tahap_blok_nomer(): void
    {
        $unit = $this->makeUnit();

        $this->assertSame('JR-004-XV-2', $unit->kode_unit);
        $this->assertSame('JR-004-XV-2', $unit->label);
    }

    public function test_kode_unit_skips_empty_tahap(): void
    {
        $unit = $this->makeUnit(['tahap' => null, 'blok' => 'A', 'no_unit' => '12']);

        $this->assertSame('JR-A-12', $unit->kode_unit);
    }

    public function test_kode_unit_rebuilt_on_update(): void
    {
        $unit = $this->makeUnit(['tahap' => '1', 'blok' => 'A', 'no_unit' => '1']);
        $unit->update(['blok' => 'B', 'no_unit' => '9']);

        $this->assertSame('JR-1-B-9', $unit->fresh()->kode_unit);
    }

    public function test_parser_finds_unit_by_canonical_kode_unit(): void
    {
        $unit = $this->makeUnit();

        $found = UnitCodeParser::findUnit('JR-004-XV-2', 1);

        $this->assertNotNull($found);
        $this->assertSame($unit->id, $found->id);
    }

    public function test_kode_unit_uses_lokasi_and_tahap_masters(): void
    {
        $lokasi = Lokasi::create([
            'project_id' => $this->project->id, 'kode' => 'JRK3', 'nama' => 'Java Residence Kavling 3', 'is_active' => true,
        ]);
        $tahap = Tahap::create([
            'lokasi_id' => $lokasi->id, 'kode' => '004', 'nama' => 'Tahap 4', 'is_active' => true,
        ]);

        $unit = Unit::create([
            'lokasi_id' => $lokasi->id, 'tahap_id' => $tahap->id,
            'blok' => 'XV', 'no_unit' => '2', 'harga_jual' => 0, 'status' => 'TERSEDIA', 'is_active' => true,
        ]);

        // kode_unit pakai kode Lokasi + Tahap master; project_id & string tahap diturunkan.
        $this->assertSame('JRK3-004-XV-2', $unit->kode_unit);
        $this->assertSame($this->project->id, $unit->project_id);
        $this->assertSame('004', $unit->tahap);
    }

    public function test_upload_import_resolves_masters_and_generates_kode_unit(): void
    {
        $lokasi = Lokasi::create([
            'project_id' => $this->project->id, 'kode' => 'JRK3', 'nama' => 'JR Kavling 3', 'is_active' => true,
        ]);
        Tahap::create(['lokasi_id' => $lokasi->id, 'kode' => '004', 'nama' => 'Tahap 4', 'is_active' => true]);

        $import = new UnitImport(projectId: $this->project->id);
        $import->collection(new Collection([
            new Collection(['lokasi' => 'JRK3', 'tahap' => '004', 'blok' => 'XV', 'nomor' => '11', 'price_list_rumah' => 500_000_000]),
        ]));

        $this->assertSame(1, $import->created, 'baris valid → unit dibuat');
        $unit = Unit::query()->where('no_unit', '11')->firstOrFail();
        $this->assertSame('JRK3-004-XV-11', $unit->kode_unit);
    }

    public function test_upload_import_rejects_unregistered_lokasi(): void
    {
        $import = new UnitImport(projectId: $this->project->id);
        $import->collection(new Collection([
            new Collection(['lokasi' => 'GHOST', 'tahap' => '004', 'blok' => 'XV', 'nomor' => '11']),
        ]));

        $this->assertSame(0, $import->created);
        $this->assertSame(1, $import->skipped);
        $this->assertNotEmpty($import->errors);
        $this->assertStringContainsString('Lokasi', $import->errors[0]);
    }
}
