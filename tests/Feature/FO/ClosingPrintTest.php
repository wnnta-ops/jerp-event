<?php

namespace Tests\Feature\FO;

use App\Models\Project;
use App\Models\User;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Adminjual\Models\SkemaLine;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\Komisi\Models\Agent;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Support\Terbilang;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosingPrintTest extends TestCase
{
    use RefreshDatabase;

    public function test_terbilang(): void
    {
        $this->assertSame('Dua juta lima ratus ribu rupiah', Terbilang::rupiah(2_500_000));
        $this->assertSame('Satu rupiah', Terbilang::rupiah(1));
        $this->assertSame('Nol rupiah', Terbilang::rupiah(0));
    }

    public function test_print_routes_render(): void
    {
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $project = Project::where('code', 'JR')->firstOrFail();
        $customer = Customer::create(['tenant_id' => 1, 'code' => 'CUST-1', 'full_name' => 'Anik Irawati', 'nik' => '123', 'is_active' => true]);
        $unit = Unit::create(['tenant_id' => 1, 'project_id' => $project->id, 'blok' => 'IX', 'no_unit' => '15', 'harga_jual' => 505_000_000, 'status' => 'TERJUAL', 'is_active' => true]);
        $agent = Agent::create(['tenant_id' => 1, 'code' => '001', 'name' => 'Oni Sampro', 'is_active' => true]);
        $skema = Skema::create(['tenant_id' => 1, 'project_id' => $project->id, 'code' => 'KPR-001', 'name' => 'KPR', 'cara_bayar' => 'KPR', 'dp_persen' => 5, 'tenor_bulan' => 180, 'jumlah_termin' => 2, 'is_active' => true]);
        SkemaLine::create(['skema_id' => $skema->id, 'urutan' => 1, 'nama_termin' => 'DP', 'basis' => 'PERSEN', 'persen' => 5, 'jatuh_tempo_hari' => 0]);
        SkemaLine::create(['skema_id' => $skema->id, 'urutan' => 2, 'nama_termin' => 'PENCAIRAN KPR', 'basis' => 'PERSEN', 'persen' => 95, 'jatuh_tempo_hari' => 30]);

        $reservasi = Reservasi::create(['tenant_id' => 1, 'no_reservasi' => 'RSV-1', 'customer_id' => $customer->id, 'unit_id' => $unit->id, 'tgl_reservasi' => now()->toDateString(), 'harga_jual' => 505_000_000, 'booking_fee' => 2_500_000, 'status' => 'NAIK_KONTRAK']);
        $kontrak = Kontrak::create([
            'tenant_id' => 1, 'no_kontrak' => 'KPR-2026-00001', 'reservasi_id' => $reservasi->id, 'customer_id' => $customer->id,
            'unit_id' => $unit->id, 'skema_id' => $skema->id, 'agent_id' => $agent->id, 'tgl_kontrak' => now()->toDateString(),
            'harga_jual' => 505_000_000, 'diskon' => 0, 'ppn' => 0, 'total_kontrak' => 505_000_000, 'cara_bayar' => 'KPR',
            'tenor_bulan' => 180, 'dp_persen' => 5, 'dp_amount' => 25_250_000, 'status' => 'AKTIF',
        ]);
        $bkm = Bkm::create(['tenant_id' => 1, 'no_bkm' => 'BKM-2026-00001', 'tgl_bkm' => now()->toDateString(), 'customer_id' => $customer->id, 'kontrak_id' => $kontrak->id, 'amount' => 2_500_000, 'jenis_pembayaran' => 'BOOKING', 'status' => 'POSTED']);

        $user = User::factory()->create(['tenant_id' => 1, 'is_active' => true]);
        $this->actingAs($user);

        $this->get(route('fo.cetak.spr', $kontrak))->assertSuccessful()->assertSee('PEMESANAN RUMAH')->assertSee('KPR-2026-00001')->assertSee('Anik Irawati');
        $this->get(route('fo.cetak.jadwal', $kontrak))->assertSuccessful()->assertSee('JADWAL PEMBAYARAN')->assertSee('PENCAIRAN KPR');
        $this->get(route('fo.cetak.kwitansi', $bkm))->assertSuccessful()->assertSee('KWITANSI')->assertSee('Dua juta lima ratus ribu rupiah');
    }
}
