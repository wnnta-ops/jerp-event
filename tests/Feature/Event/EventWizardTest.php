<?php

namespace Tests\Feature\Event;

use App\Filament\Event\Resources\EventResource\Pages\CreateEvent;
use App\Filament\Event\Resources\EventResource\Pages\EditEvent;
use App\Filament\Event\Resources\EventResource\Pages\ListEvents;
use App\Modules\Event\Models\EtAccess;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Services\EventSetupService;
use Filament\Facades\Filament;
use Livewire\Livewire;

class EventWizardTest extends EventTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('event'));
    }

    public function test_superadmin_creates_and_opens_event_through_wizard(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-WIZ'), '0801');
        $scanner = $this->user();
        $this->actingAs($this->superUser());

        $svc = app(EventSetupService::class);
        $pos = [
            ['code' => 'KONS', 'name' => 'Konsultasi', 'target_minutes' => 10],
            ['code' => 'PU', 'name' => 'Pilih Unit', 'target_minutes' => 20],
        ];

        Livewire::test(CreateEvent::class)
            ->fillForm([
                'name' => 'Pilih Unit Uji',
                'event_date' => '2026-11-20',
                'venue' => 'MG',
                'fo_nup_event_id' => $ev,
                'pos' => $pos,
                'titik' => $svc->titikBawaan($pos),
                'akses' => [['user_id' => $scanner->id, 'titik' => ['KONS:masuk', 'KONS:keluar'], 'can_live_board' => true, 'can_reports' => false]],
                'buka_sekarang' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $event = EtEvent::where('name', 'Pilih Unit Uji')->sole();
        $this->assertSame(EtEvent::OPEN, $event->status);
        $this->assertSame(['FO', 'KONS', 'PU'], $event->stations()->pluck('code')->all());
        $this->assertSame(2, EtAccess::where('event_id', $event->id)->where('user_id', $scanner->id)->count());
        $this->assertSame(1, $event->participants()->count());

        // Form ubah terisi dari data tersimpan.
        Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()])
            ->assertFormSet(['name' => 'Pilih Unit Uji', 'fo_nup_event_id' => $ev]);
    }

    public function test_panel_root_opens_event_list_not_back_to_dashboard(): void
    {
        $this->actingAs($this->superUser())
            ->get('/event')
            ->assertRedirect(url('/event/events'));
    }

    public function test_scanner_account_sees_only_own_events_and_cannot_create(): void
    {
        $super = $this->superUser();
        $scanner = $this->user();
        $a = $this->eventOpen($this->foNupEvent('A'));
        $this->eventOpen($this->foNupEvent('B'));
        $this->grantScan($scanner, $a['event'], $a['m2']);

        $this->actingAs($scanner);
        Livewire::test(ListEvents::class)
            ->assertCanSeeTableRecords([$a['event']])
            ->assertCountTableRecords(1)
            ->assertActionHidden('create');

        $this->get('/event/events/create')->assertForbidden();

        $this->actingAs($super);
        Livewire::test(ListEvents::class)->assertCountTableRecords(2);
    }
}
