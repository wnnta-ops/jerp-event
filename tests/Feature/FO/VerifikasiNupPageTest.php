<?php

namespace Tests\Feature\FO;

use App\Models\Project;
use App\Models\User;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Unit;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifikasiNupPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_verifikasi_nup_list_page_renders_without_error(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $project = Project::where('code', 'JR')->firstOrFail();
        $event = NupEvent::create([
            'project_id' => $project->id, 'code' => 'LNC1', 'name' => 'Launching 1',
            'tgl_launching' => '2026-06-01', 'booking_fee' => 3_000_000, 'status' => 'OPEN',
        ]);
        $unit = Unit::create([
            'project_id' => $project->id, 'blok' => 'A', 'no_unit' => '15', 'cluster' => 'JKS',
            'harga_jual' => 500_000_000, 'status' => 'TERSEDIA', 'is_active' => true,
        ]);
        NupRegistration::create([
            'nup_event_id' => $event->id, 'no_nup' => 'NUP-1', 'urutan' => 1,
            'nama_pendaftar' => 'Budi', 'tgl_daftar' => now(), 'status' => 'DAFTAR',
            'verif_status' => NupRegistration::VERIF_MENUNGGU_BAYAR, 'utj_amount' => 3_000_000,
            'pilihan_unit_1' => 'JKS A-15',
        ]);

        $user = User::factory()->create(['tenant_id' => 1, 'is_active' => true]);

        $response = $this->actingAs($user)->get('/fo/verifikasi-nups');

        // Before the fix this returned 500 (BindingResolutionException for [$q]).
        $response->assertSuccessful();
        $response->assertSee('Budi');
    }
}
