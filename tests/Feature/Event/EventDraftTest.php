<?php

namespace Tests\Feature\Event;

use App\Filament\Event\Resources\EventResource\Pages\CreateEvent;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Models\EtSetupDraft;
use App\Modules\Event\Services\EventSetupService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** "Simpan draft" di wizard: satu draft per akun, dilanjutkan otomatis, dibuang setelah event jadi. */
class EventDraftTest extends EventTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('event'));
    }

    public function test_save_draft_without_validation_and_resume_on_same_account_only(): void
    {
        $a = $this->superUser();
        $b = $this->superUser();

        // Setengah jadi: tanggal & event NUP masih kosong — tetap boleh disimpan.
        Livewire::actingAs($a)->test(CreateEvent::class)
            ->fillForm(['name' => 'Draft Pilih Unit', 'venue' => 'MG Jasmine'])
            ->callAction('simpanDraft')
            ->assertHasNoFormErrors();

        $this->assertSame('Draft Pilih Unit', EtSetupDraft::where('user_id', $a->id)->sole()->data['name']);
        $this->assertSame(0, EtEvent::count());

        Livewire::actingAs($a)->test(CreateEvent::class)
            ->assertFormSet(['name' => 'Draft Pilih Unit', 'venue' => 'MG Jasmine'])
            ->assertActionVisible('buangDraft');

        // Akun lain tak melihat draft itu.
        Livewire::actingAs($b)->test(CreateEvent::class)
            ->assertFormSet(['name' => null])
            ->assertActionHidden('buangDraft');
    }

    public function test_draft_is_removed_after_event_created_and_can_be_discarded(): void
    {
        $ev = $this->foNupEvent();
        $u = $this->superUser();
        $svc = app(EventSetupService::class);
        $pos = [['code' => 'PU', 'name' => 'Pilih Unit']];

        Livewire::actingAs($u)->test(CreateEvent::class)
            ->fillForm(['name' => 'Akan jadi'])
            ->callAction('simpanDraft');

        Livewire::actingAs($u)->test(CreateEvent::class)
            ->fillForm([
                'name' => 'Akan jadi', 'event_date' => '2026-11-20', 'fo_nup_event_id' => $ev,
                'pos' => $pos, 'titik' => $svc->titikBawaan($pos), 'akses' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(1, EtEvent::where('name', 'Akan jadi')->count());
        $this->assertSame(0, EtSetupDraft::where('user_id', $u->id)->count());

        Livewire::actingAs($u)->test(CreateEvent::class)
            ->fillForm(['name' => 'Dibuang'])
            ->callAction('simpanDraft')
            ->callAction('buangDraft')
            ->assertFormSet(['name' => null]);
        $this->assertSame(0, EtSetupDraft::where('user_id', $u->id)->count());
    }
}
