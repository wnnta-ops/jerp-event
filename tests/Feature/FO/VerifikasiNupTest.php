<?php

namespace Tests\Feature\FO;

use App\Models\Project;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\VerifikasiNupService;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use Database\Seeders\BoChartOfAccountSeeder;
use Database\Seeders\BoNumeratorSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class VerifikasiNupTest extends TestCase
{
    use RefreshDatabase;

    private VerifikasiNupService $service;

    private NupEvent $event;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);   // company MUP, project JR, numerators (CUST/RSV/BKM)
        $this->seed(BoNumeratorSeeder::class);  // JU + BKM for FOBO
        $this->seed(BoChartOfAccountSeeder::class);

        $project = Project::where('code', 'JR')->firstOrFail();
        $this->event = NupEvent::create([
            'project_id' => $project->id, 'code' => 'LNC1', 'name' => 'Launching 1',
            'tgl_launching' => '2026-06-01', 'booking_fee' => 3_000_000, 'status' => 'OPEN',
        ]);
        $this->unit = Unit::create([
            'project_id' => $project->id, 'blok' => 'A', 'no_unit' => '15', 'cluster' => 'JKS',
            'harga_jual' => 500_000_000, 'status' => 'TERSEDIA', 'is_active' => true,
        ]);
        $this->service = app(VerifikasiNupService::class);
    }

    private function makeReg(array $overrides = []): NupRegistration
    {
        return NupRegistration::create(array_merge([
            'nup_event_id' => $this->event->id,
            'no_nup' => null,   // terbit saat approval
            'urutan' => null,   // terbit saat approval
            'nama_pendaftar' => 'Budi Santoso',
            'nik' => '3175xxxxxxxxxxx',
            'phone' => '0812000111',
            'email' => 'budi@example.test',
            'tgl_daftar' => now(),
            'status' => 'DAFTAR',
            'verif_status' => NupRegistration::VERIF_MENUNGGU_BAYAR,
            'utj_amount' => 3_000_000,
            'pilihan_unit_1' => 'JKS A-15 (preferensi teks)',
        ], $overrides));
    }

    public function test_create_xendit_invoice_sets_pending(): void
    {
        $reg = $this->service->createXenditInvoice($this->makeReg(), 3_000_000);

        $this->assertSame('PENDING', $reg->xendit_status);
        $this->assertNotEmpty($reg->xendit_invoice_url);
        $this->assertSame(NupRegistration::VERIF_MENUNGGU_BAYAR, $reg->verif_status);
        $this->assertFalse($reg->canSelectUnit());
    }

    public function test_mark_paid_posts_utj_to_gl_via_fobo(): void
    {
        $reg = $this->service->createXenditInvoice($this->makeReg(), 3_000_000);

        // Referensi pembayaran = kolom khusus Xendit, bukan no_nup (yang masih null).
        $this->assertNotEmpty($reg->xendit_external_id);
        $this->assertNull($reg->no_nup);

        $reg = $this->service->markPaid($reg);

        $this->assertTrue($reg->isUtjPaid());
        $this->assertSame(NupRegistration::VERIF_PAID, $reg->verif_status);
        $this->assertDatabaseHas('bo_fobo_postings', [
            'source_modul' => 'FO.NUP', 'source_no' => $reg->xendit_external_id, 'jenis' => 'KAS_MASUK', 'status' => 'POSTED',
        ]);
    }

    public function test_approve_after_paid_opens_unit_selection_and_registers_customer(): void
    {
        $reg = $this->service->markPaid($this->service->createXenditInvoice($this->makeReg(), 3_000_000));
        $reg = $this->service->approve($reg); // no real unit assigned yet

        $this->assertSame(NupRegistration::VERIF_APPROVED, $reg->verif_status);
        $this->assertTrue($reg->canSelectUnit());
        $this->assertNotNull($reg->customer_id);
        $this->assertNull($reg->reservasi_id); // no draft reservation without a real unit

        // No NUP & nomor urut baru terbit saat approval.
        $this->assertNotNull($reg->no_nup);
        $this->assertStringStartsWith('NUP', $reg->no_nup);
        $this->assertSame(1, $reg->urutan);
    }

    public function test_approve_saves_unit_recommendation_from_verification_team(): void
    {
        $reg = $this->service->markPaid($this->service->createXenditInvoice($this->makeReg(), 3_000_000));
        $reg = $this->service->approve($reg, null, false, null, 'Blok A-15 (hook), alternatif A-16');

        $this->assertSame('Blok A-15 (hook), alternatif A-16', $reg->rekomendasi_unit);
    }

    public function test_approve_with_real_unit_creates_draft_reservation(): void
    {
        $reg = $this->service->markPaid($this->service->createXenditInvoice($this->makeReg(), 3_000_000));
        $reg = $this->service->approve($reg, null, false, $this->unit->id);

        $this->assertNotNull($reg->reservasi_id);
        $reservasi = Reservasi::find($reg->reservasi_id);
        $this->assertSame($this->unit->id, $reservasi->unit_id);
        $this->assertEqualsWithDelta(3_000_000, (float) $reservasi->booking_fee, 0.01);
    }

    public function test_approve_without_payment_requires_manual_with_note(): void
    {
        $reg = $this->makeReg(); // not paid

        // Plain approve is blocked.
        try {
            $this->service->approve($reg);
            $this->fail('Approve tanpa bayar seharusnya ditolak.');
        } catch (RuntimeException) {
        }

        // Manual approve without a note is blocked.
        try {
            $this->service->approve($reg, null, true);
            $this->fail('Approve manual tanpa catatan seharusnya ditolak.');
        } catch (RuntimeException) {
        }

        // Manual approve with a note succeeds and records why.
        $reg = $this->service->approve($reg, 'Sudah dicek di dashboard Xendit, PAID', true);
        $this->assertSame(NupRegistration::VERIF_APPROVED, $reg->verif_status);
        $this->assertTrue($reg->is_manual_approve);
        $this->assertStringContainsString('Xendit', $reg->verif_note);
    }

    public function test_reject_requires_note_and_blocks_unit_selection(): void
    {
        $reg = $this->service->markPaid($this->service->createXenditInvoice($this->makeReg(), 3_000_000));

        $this->expectException(RuntimeException::class);
        $this->service->reject($reg, '');
    }

    public function test_reject_blocks_unit_selection(): void
    {
        $reg = $this->service->reject($this->makeReg(), 'Data KTP tidak valid');
        $this->assertSame(NupRegistration::VERIF_REJECTED, $reg->verif_status);
        $this->assertFalse($reg->canSelectUnit());
    }

    public function test_refund_marks_refunded(): void
    {
        $reg = $this->service->markPaid($this->service->createXenditInvoice($this->makeReg(), 3_000_000));
        $reg = $this->service->refund($reg, 'Customer batal');

        $this->assertSame(NupRegistration::VERIF_REFUNDED, $reg->verif_status);
        $this->assertFalse($reg->canSelectUnit());
    }
}
