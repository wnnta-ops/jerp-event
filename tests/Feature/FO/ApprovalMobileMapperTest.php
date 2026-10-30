<?php

namespace Tests\Feature\FO;

use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalMobileMapper;
use Tests\TestCase;

/**
 * Bentuk data app mobile approver dari ApprovalMobileMapper: kolom tabel inbox,
 * nominal positif, dan urgensi menurut SLA 72 jam. Model dibangun di memori
 * (relasi diset null) sehingga tanpa DB.
 */
class ApprovalMobileMapperTest extends TestCase
{
    private function approval(array $attrs): Approval
    {
        $a = new Approval;
        $a->setRawAttributes($attrs + [
            'id' => 1, 'no_approval' => 'APR-202610-00001', 'status' => 'PENDING',
            'current_level' => 1, 'tgl_approval' => now()->toDateString(),
            'created_at' => now()->toDateTimeString(),
        ]);
        foreach (['subject', 'requester', 'approver', 'level1Approver'] as $rel) {
            $a->setRelation($rel, null);
        }

        return $a;
    }

    public function test_diskon_tampil_positif_dengan_kolom_tabel(): void
    {
        $item = (new ApprovalMobileMapper)->toRequestItem(
            $this->approval(['jenis' => 'DISKON', 'amount_dampak' => -15000000])
        );

        $this->assertSame('Rp 15.000.000', $item['amount']);
        $this->assertSame(15000000.0, $item['valueNum']);
        $this->assertSame('APR-202610-00001', $item['docNo']);
        $this->assertSame('DISKON', $item['moduleKey']);
        $this->assertSame(now()->format('d/m/Y'), $item['docDate']);
        $this->assertNull($item['project']);
        $this->assertSame([0, 2], [$item['approveLevel'], $item['approveTotal']]);
    }

    public function test_urgensi_mengikuti_sla_72_jam_dari_waktu_pengajuan(): void
    {
        $m = new ApprovalMobileMapper;
        $baru = $m->toRequestItem($this->approval(['jenis' => 'GANTI_NAMA', 'created_at' => now()->subHours(2)->toDateTimeString()]));
        $mepet = $m->toRequestItem($this->approval(['jenis' => 'GANTI_NAMA', 'created_at' => now()->subHours(60)->toDateTimeString()]));

        $this->assertSame('none', $baru['deadline']);   // sisa ±70 jam
        $this->assertSame('red', $mepet['deadline']);   // sisa ±12 jam
        $this->assertGreaterThan(69 * 60, $baru['slaMin']); // dihitung dari jam pengajuan, bukan 00:00
        $this->assertNull($baru['valueNum']);           // DOC tanpa nominal
    }

    public function test_level_final_terhitung_satu_dari_dua(): void
    {
        $item = (new ApprovalMobileMapper)->toRequestItem(
            $this->approval(['jenis' => 'BATAL_KONTRAK', 'amount_dampak' => 120000000, 'current_level' => 2])
        );

        $this->assertSame([1, 2], [$item['approveLevel'], $item['approveTotal']]);
    }
}
