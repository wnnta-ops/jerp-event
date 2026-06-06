<?php

namespace Tests\Feature\E2E;

use App\Livewire\Nup\PublicForm;
use App\Models\Project;
use App\Modules\FO\Launching\Events\NupRegistered;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Models\NupTipe;
use App\Modules\FO\Launching\Services\VerifikasiNupService;
use App\Modules\FO\MarketingSales\Models\Customer;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\TenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * E2E: public NUP registration via the Livewire form (BR-NUP, docs/04_BusinessFlow.md §2).
 * No auth — exercises the actual route protected only by throttle middleware.
 */
class NupPublicFormTest extends TestCase
{
    use RefreshDatabase;

    private NupEvent $event;

    private NupTipe $tipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TenantSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $project = Project::query()->where('code', 'JR')->firstOrFail();
        $this->event = NupEvent::create([
            'tenant_id' => 1,
            'project_id' => $project->id,
            'code' => 'JR-LCH-2026-01',
            'name' => 'Java Residence Launching 2026',
            'tgl_launching' => '2026-07-01',
            'kuota' => 50,
            'booking_fee' => 5_000_000,
            'status' => 'OPEN',
        ]);

        // Setup NUP — Jenis Tipe NUP untuk project ini (Nilai = UTJ).
        $this->tipe = NupTipe::create([
            'tenant_id' => 1,
            'project_id' => $project->id,
            'nama' => 'Reguler',
            'nilai_utj' => 3_000_000,
            'is_active' => true,
        ]);

        Storage::fake('local');
    }

    public function test_public_nup_route_is_reachable_without_auth(): void
    {
        $response = $this->get('/nup/daftar');
        $response->assertOk();
        $response->assertSee('Pendaftaran NUP');
    }

    public function test_tipe_nup_dropdown_uses_setup_nup_for_selected_project(): void
    {
        NupTipe::create([
            'tenant_id' => 1, 'project_id' => $this->event->project_id,
            'nama' => 'VIP', 'nilai_utj' => 10_000_000, 'is_active' => true,
        ]);

        // Tanpa project terpilih, opsi tipe belum muncul; setelah pilih project, muncul.
        Livewire::test(PublicForm::class)
            ->set('project_pref_id', $this->event->project_id)
            ->assertSee('Reguler')
            ->assertSee('VIP');
    }

    public function test_livewire_submit_creates_lead_customer_and_nup_registration(): void
    {
        Mail::fake();
        Event::fake([NupRegistered::class]);

        Livewire::test(PublicForm::class)
            ->set('nup_event_id', $this->event->id)
            ->set('project_pref_id', $this->event->project_id)
            ->set('sumber_data', 'Instagram')
            ->set('full_name', 'Rini Wijaya')
            ->set('nik', '3201020202020001')
            ->set('birth_place', 'Bekasi')
            ->set('birth_date', '1990-05-12')
            ->set('gender', 'P')
            ->set('marital_status', 'BELUM')
            ->set('mobile', '081234999111')
            ->set('email', 'rini@example.test')
            ->set('city', 'Bekasi')
            ->set('province', 'Jawa Barat')
            ->set('mail_same_as_ktp', true)
            ->set('nup_tipe_id', $this->tipe->id)
            ->set('cara_bayar', 'KPR')
            ->set('ktp_file', UploadedFile::fake()->create('ktp.pdf', 200, 'application/pdf'))
            ->set('pernyataan_setuju', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        // Customer (LEAD) was created with the right shape
        $customer = Customer::where('nik', '3201020202020001')->firstOrFail();
        $this->assertSame('Rini Wijaya', $customer->full_name);
        $this->assertSame('LEAD', $customer->lead_status);
        $this->assertNotEmpty($customer->ktp_file_path);
        Storage::disk('local')->assertExists($customer->ktp_file_path);

        // NupRegistration was linked
        $registration = NupRegistration::where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame($this->event->id, $registration->nup_event_id);
        $this->assertSame('DAFTAR', $registration->status);
        $this->assertSame('PUBLIC', $registration->channel);
        $this->assertNull($registration->urutan, 'Nomor urut NUP belum terbit sebelum approval');
        $this->assertSame($this->event->project_id, $registration->project_pref_id);
        $this->assertTrue((bool) $registration->pernyataan_setuju);

        // Jenis Tipe NUP terikat → nama + UTJ dari master Setup NUP, invoice dibuat saat register.
        $this->assertSame('Reguler', $registration->tipe_nup);
        $this->assertEqualsWithDelta(3_000_000, (float) $registration->utj_amount, 0.01);
        $this->assertSame(NupRegistration::VERIF_MENUNGGU_BAYAR, $registration->verif_status);
        $this->assertNotEmpty($registration->xendit_external_id, 'UTJ Xendit dibuat otomatis saat register');

        // Event fired → email job would dispatch via the listener (faked here)
        Event::assertDispatched(NupRegistered::class, function ($e) use ($registration) {
            return $e->registration->id === $registration->id;
        });
    }

    public function test_submit_rejects_without_pernyataan_setuju(): void
    {
        Livewire::test(PublicForm::class)
            ->set('nup_event_id', $this->event->id)
            ->set('full_name', 'Dimas')
            ->set('nik', '3201020202020002')
            ->set('mobile', '081234999112')
            ->set('email', 'dimas@example.test')
            ->set('city', 'Bekasi')
            ->set('province', 'Jawa Barat')
            ->set('ktp_file', UploadedFile::fake()->create('ktp.pdf', 200, 'application/pdf'))
            ->set('pernyataan_setuju', false)
            ->call('submit')
            ->assertHasErrors(['pernyataan_setuju']);

        $this->assertSame(0, NupRegistration::count(), 'No registration on rejected submit');
        $this->assertSame(0, Customer::where('nik', '3201020202020002')->count());
    }

    public function test_submit_rejects_invalid_nik_length(): void
    {
        Livewire::test(PublicForm::class)
            ->set('nup_event_id', $this->event->id)
            ->set('full_name', 'Test User')
            ->set('nik', '123')      // too short
            ->set('mobile', '081234567890')
            ->set('email', 'test@example.test')
            ->set('city', 'Bekasi')
            ->set('province', 'Jawa Barat')
            ->set('ktp_file', UploadedFile::fake()->create('ktp.pdf', 200, 'application/pdf'))
            ->set('pernyataan_setuju', true)
            ->call('submit')
            ->assertHasErrors(['nik']);
    }

    public function test_spouse_required_when_marital_status_kawin(): void
    {
        Livewire::test(PublicForm::class)
            ->set('nup_event_id', $this->event->id)
            ->set('full_name', 'Kawin Test')
            ->set('nik', '3201020202020003')
            ->set('mobile', '081234567890')
            ->set('email', 'kawin@example.test')
            ->set('city', 'Bekasi')
            ->set('province', 'Jawa Barat')
            ->set('marital_status', 'KAWIN')
            ->set('ktp_file', UploadedFile::fake()->create('ktp.pdf', 200, 'application/pdf'))
            ->set('pernyataan_setuju', true)
            ->call('submit')
            ->assertHasErrors(['spouse_full_name', 'spouse_nik']);
    }

    public function test_urutan_is_issued_on_approval_not_registration(): void
    {
        $register = function (string $name, string $nik, string $email): NupRegistration {
            Livewire::test(PublicForm::class)
                ->set('nup_event_id', $this->event->id)
                ->set('project_pref_id', $this->event->project_id)
                ->set('nup_tipe_id', $this->tipe->id)
                ->set('full_name', $name)
                ->set('nik', $nik)
                ->set('mobile', '0812'.substr($nik, -8))
                ->set('email', $email)
                ->set('city', 'Bekasi')
                ->set('province', 'Jawa Barat')
                ->set('ktp_file', UploadedFile::fake()->create('ktp.pdf', 200, 'application/pdf'))
                ->set('pernyataan_setuju', true)
                ->call('submit')
                ->assertHasNoErrors()
                ->assertSet('submitted', true);

            return NupRegistration::where('nik', $nik)->firstOrFail();
        };

        $first = $register('First', '3201020202020010', 'first@example.test');
        $second = $register('Second', '3201020202020011', 'second@example.test');

        // No queue number is issued at registration.
        $this->assertNull($first->urutan);
        $this->assertNull($second->urutan);

        // The queue order is allocated at approval, in the order approvals happen
        // (FIFO by approval) — here the second registrant is approved first.
        $service = app(VerifikasiNupService::class);
        $approvedSecond = $service->approve($second->fresh(), 'Cek manual: data valid', true);
        $approvedFirst = $service->approve($first->fresh(), 'Cek manual: data valid', true);

        $this->assertSame(1, $approvedSecond->urutan);
        $this->assertSame(2, $approvedFirst->urutan);
    }
}
