<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Models\Project;
use App\Modules\FO\Launching\Services\UnitLockService;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

/**
 * Live unit map for launching event (BR-LNC, RULE-06).
 * Subscribes to Echo presence channel project.{projectId}.units (Reverb).
 * Sales clicks unit → lock atomic → broadcast UnitStatusChanged → all open
 * maps refresh < 1 second (replaces legacy SignalR unitHub).
 */
class PetaUnitLive extends Page
{
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationGroup = 'Launching & NUP';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Peta Unit Live';

    protected static ?string $navigationLabel = 'Peta Unit (Live)';

    protected static string $view = 'filament.fo.pages.peta-unit-live';

    public ?int $projectId = null;

    public function mount(): void
    {
        $this->projectId = (int) (Project::query()->where('is_active', true)->orderBy('name')->value('id') ?? 0);
    }

    #[Computed]
    public function projects()
    {
        return Project::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function units()
    {
        if (! $this->projectId) {
            return collect();
        }

        return Unit::query()
            ->where('project_id', $this->projectId)
            ->orderBy('blok')
            ->orderBy('no_unit')
            ->get();
    }

    public function setProject(int $projectId): void
    {
        $this->projectId = $projectId;
    }

    public function lockUnit(int $unitId): void
    {
        try {
            app(UnitLockService::class)->lock($unitId);
            Notification::make()->title('Unit dikunci (BOOKING)')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal mengunci unit')->body($e->getMessage())->danger()->send();
        }
    }

    public function releaseUnit(int $unitId): void
    {
        try {
            app(UnitLockService::class)->release($unitId);
            Notification::make()->title('Unit dilepas (TERSEDIA)')->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Gagal melepas unit')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Livewire listener for Echo broadcast. Auto-refresh data when other clients
     * update unit status — listener name matches event broadcastAs().
     */
    #[On('echo-presence:project.{projectId}.units,unit.status.changed')]
    public function onRemoteUnitStatusChanged(array $payload = []): void
    {
        // simply re-render — computed unit list refetches from DB
        unset($this->units);
    }

    /**
     * Echo channel listeners array — used by Blade view to wire up window.Echo.
     *
     * @return array<int,string>
     */
    public function getListeners(): array
    {
        return $this->projectId
            ? ["echo-presence:project.{$this->projectId}.units,unit.status.changed" => 'onRemoteUnitStatusChanged']
            : [];
    }
}
