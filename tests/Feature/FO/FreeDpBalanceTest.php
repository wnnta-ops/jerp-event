<?php

namespace Tests\Feature\FO;

use App\Models\Project;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Adminjual\Models\SkemaLine;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi bug SPR KPR-2026-00022: mode FREE DP (ANGSURAN1), DP 1 & DP 2 terbayar
 * (kurang dari skema), DP 3 di-free. PENCAIRAN KPR harus dihitung ulang sebagai
 * SISA sehingga TOTAL jadwal tagihan = Harga Pemesanan (bukan kurang 5,3jt).
 */
class FreeDpBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_dp_with_partially_paid_dp_keeps_total_equal_harga(): void
    {
        $this->seed(TenantSeeder::class);
        $project = Project::where('code', 'JR')->firstOrFail();

        $customer = Customer::create(['tenant_id' => 1, 'code' => 'CUST-FD', 'full_name' => 'Kami', 'nik' => '999', 'is_active' => true]);
        $unit = Unit::create(['tenant_id' => 1, 'project_id' => $project->id, 'blok' => 'X', 'no_unit' => '19', 'harga_jual' => 495_000_000, 'status' => 'TERJUAL', 'is_active' => true]);

        // Skema FREE DP (ANGSURAN1), KPR: BF 3jt + DP 3x @ 8,25jt (5% total) + PENCAIRAN KPR (sisa).
        $skema = Skema::create([
            'tenant_id' => 1, 'project_id' => $project->id, 'code' => 'KPR-FD', 'name' => 'KPR FREE DP',
            'cara_bayar' => 'KPR', 'dp_persen' => 5, 'bf_nominal' => 3_000_000,
            'bf_dipotong_di' => 'ANGSURAN1', 'is_active' => true,
        ]);
        SkemaLine::create(['skema_id' => $skema->id, 'urutan' => 1, 'tipe' => 'BF', 'nama_termin' => 'BOOKING FEE', 'basis' => 'NOMINAL', 'nominal' => 3_000_000, 'jatuh_tempo_hari' => 0]);
        SkemaLine::create(['skema_id' => $skema->id, 'urutan' => 2, 'tipe' => 'DP', 'nama_termin' => 'DP 1', 'basis' => 'NOMINAL', 'nominal' => 8_250_000, 'jatuh_tempo_hari' => 15]);
        SkemaLine::create(['skema_id' => $skema->id, 'urutan' => 3, 'tipe' => 'DP', 'nama_termin' => 'DP 2', 'basis' => 'NOMINAL', 'nominal' => 8_250_000, 'jatuh_tempo_hari' => 30]);
        SkemaLine::create(['skema_id' => $skema->id, 'urutan' => 4, 'tipe' => 'DP', 'nama_termin' => 'DP 3', 'basis' => 'NOMINAL', 'nominal' => 8_250_000, 'jatuh_tempo_hari' => 45]);
        SkemaLine::create(['skema_id' => $skema->id, 'urutan' => 5, 'tipe' => 'ANG', 'nama_termin' => 'PENCAIRAN KPR', 'basis' => 'PERSEN', 'persen' => 95, 'flag_kpr' => true, 'jatuh_tempo_hari' => 60]);

        $kontrak = Kontrak::create([
            'tenant_id' => 1, 'no_kontrak' => 'SPR-2026-00099', 'customer_id' => $customer->id,
            'unit_id' => $unit->id, 'skema_id' => $skema->id, 'tgl_kontrak' => '2026-06-30',
            'harga_jual' => 495_000_000, 'diskon' => 0, 'ppn' => 0, 'total_kontrak' => 495_000_000,
            'cara_bayar' => 'KPR', 'dp_persen' => 5, 'status' => 'AKTIF',
            // tgl_berkas_lengkap null → semua DP belum-bayar jadi FREE
        ]);

        // Pra-kondisi: DP 1 & DP 2 SUDAH terbayar 5,6jt (kurang dari skema 8,25jt).
        $kontrak->tagihan()->create(['urutan' => 2, 'tipe' => 'DP', 'nama_tagihan' => 'DP 1', 'jatuh_tempo' => '2026-07-15', 'nilai' => 8_250_000, 'pelunasan' => 5_600_000]);
        $kontrak->tagihan()->create(['urutan' => 3, 'tipe' => 'DP', 'nama_tagihan' => 'DP 2', 'jatuh_tempo' => '2026-08-01', 'nilai' => 8_250_000, 'pelunasan' => 5_600_000]);

        // Generate ulang jadwal: snapshot paidDp → DP1/DP2 dikunci 5,6jt, DP3 di-free jadi UANG MASUK.
        app(KontrakGeneratorService::class)->tagihan($kontrak);

        $total = (float) $kontrak->tagihan()->sum('nilai');
        $this->assertEqualsWithDelta(495_000_000, $total, 0.5, 'Total jadwal tagihan harus = Harga Pemesanan');

        // DP terbayar tetap tampil sesuai jumlah bayar; DP3 tak lagi jadi baris DP (jadi UANG MASUK).
        $this->assertSame(5_600_000.0, (float) $kontrak->tagihan()->where('nama_tagihan', 'DP 1')->value('nilai'));
        $this->assertSame(0, $kontrak->tagihan()->where('nama_tagihan', 'DP 3')->count());

        // Pencairan KPR TETAP plafon awal (harga − DP gross 24,75jt − BF 3jt = 467,25jt),
        // TIDAK menyerap selisih DP.
        $this->assertEqualsWithDelta(467_250_000, (float) $kontrak->tagihan()->where('nama_tagihan', 'PENCAIRAN KPR')->value('nilai'), 0.5, 'Pencairan KPR harus tetap plafon awal');

        // UANG MASUK = DP3 free (8,25jt) + selisih DP1/DP2 (2×2,65jt = 5,3jt) = 13,55jt.
        $this->assertEqualsWithDelta(13_550_000, (float) $kontrak->tagihan()->where('nama_tagihan', 'UANG MASUK')->sum('nilai'), 0.5, 'UANG MASUK = DP3 free + selisih DP terbayar');
    }
}
