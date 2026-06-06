<?php

namespace Tests\Feature\BO;

use App\Filament\Bo\Clusters\GeneralLedger\Pages\FinalisasiJurnalPage;
use App\Filament\Bo\Clusters\GeneralLedger\Pages\NeracaSaldoPage;
use App\Filament\Bo\Clusters\GeneralLedger\Pages\PeriodeFiskalPage;
use App\Filament\Bo\Resources\ChartOfAccountResource;
use App\Models\User;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlClusterRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);
        $this->seed(BoNumeratorSeeder::class);
        $this->seed(BoChartOfAccountSeeder::class);
        $this->user = User::factory()->create(['tenant_id' => 1, 'is_active' => true]);
        Filament::setCurrentPanel(Filament::getPanel('bo'));
    }

    public function test_periode_fiskal_landing_renders(): void
    {
        $this->actingAs($this->user)->get(PeriodeFiskalPage::getUrl(panel: 'bo'))->assertSuccessful();
    }

    public function test_neraca_saldo_report_renders(): void
    {
        $this->actingAs($this->user)->get(NeracaSaldoPage::getUrl(panel: 'bo'))->assertSuccessful();
    }

    public function test_placeholder_page_renders(): void
    {
        $this->actingAs($this->user)->get(FinalisasiJurnalPage::getUrl(panel: 'bo'))
            ->assertSuccessful()
            ->assertSee('Menunggu penyesuaian');
    }

    public function test_clustered_coa_resource_renders(): void
    {
        $this->actingAs($this->user)->get(ChartOfAccountResource::getUrl(panel: 'bo'))->assertSuccessful();
    }
}
