<?php

namespace App\Filament\Fo\Pages;

use App\Models\Project;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;

/**
 * Polygon Editor untuk koordinat unit di siteplan.
 *
 * Pengganti workflow Adobe Fireworks → Excel: user gambar polygon
 * langsung di siteplan via Leaflet-Geoman. Setiap edit auto-save
 * ke fo_units.siteplan_coords (JSON [[x,y], ...]).
 *
 * Koordinat disimpan dalam ruang IMAGE (origin top-left, sama dengan
 * format Adobe Fireworks). Leaflet pakai CRS.Simple dengan flip
 * vertikal saat render/save.
 */
class UploadKoordinatEditor extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static ?string $title = 'Polygon Editor (Live)';

    protected static ?string $navigationLabel = 'Polygon Editor';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 76;

    protected static ?string $slug = 'upload-koordinat-editor';

    protected static string $view = 'filament.fo.pages.upload-koordinat-editor';

    public ?array $data = [];

    public ?int $projectId = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Pilih Project')
                ->description('Pilih project untuk muat siteplan + daftar unit. Site plan harus sudah diupload di menu Upload Site Plan.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('project_id')
                        ->label('Project / Cluster')->required()
                        ->options(fn () => Project::pluck('name', 'id'))
                        ->searchable()->preload()
                        ->live()
                        ->afterStateUpdated(function ($state) {
                            $this->projectId = $state ? (int) $state : null;
                        }),
                ]),
        ]);
    }

    public function getProject(): ?Project
    {
        return $this->projectId ? Project::find($this->projectId) : null;
    }

    /** Payload yang diconsume Alpine.js untuk inisialisasi map. */
    public function getEditorPayload(): array
    {
        $project = $this->getProject();
        if (! $project || ! $project->siteplan_image_path) {
            return [];
        }

        $units = Unit::where('project_id', $project->id)
            ->orderBy('blok')->orderBy('no_unit')
            ->get(['id', 'blok', 'no_unit', 'tahap', 'cluster', 'status', 'siteplan_coords'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'kode' => trim(($u->blok ?? '').'-'.($u->no_unit ?? ''), '-'),
                'label' => trim(($u->cluster ? $u->cluster.' / ' : '').($u->blok ?? '').'-'.($u->no_unit ?? ''), ' /-'),
                'tahap' => $u->tahap,
                'status' => $u->status,
                'coords' => is_array($u->siteplan_coords) ? $u->siteplan_coords : null,
            ])
            ->values()
            ->all();

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'imageUrl' => Storage::disk('public')->url($project->siteplan_image_path),
                'width' => (int) ($project->siteplan_width ?: 2000),
                'height' => (int) ($project->siteplan_height ?: 1200),
            ],
            'units' => $units,
        ];
    }

    public function saveCoords(int $unitId, array $coords): void
    {
        // Validasi: minimal 3 titik, semua pasangan numeric
        if (count($coords) < 3) {
            Notification::make()->title('Polygon minimal 3 titik')->danger()->send();

            return;
        }

        $clean = [];
        foreach ($coords as $pair) {
            if (! is_array($pair) || count($pair) !== 2) {
                Notification::make()->title('Format koordinat invalid')->danger()->send();

                return;
            }
            $clean[] = [(int) round($pair[0]), (int) round($pair[1])];
        }

        $unit = Unit::find($unitId);
        if (! $unit || $unit->project_id !== $this->projectId) {
            Notification::make()->title('Unit tidak ditemukan / beda project')->danger()->send();

            return;
        }

        $unit->update(['siteplan_coords' => $clean]);

        $this->dispatch('coords-saved', unitId: $unitId, count: count($clean));
    }

    public function clearCoords(int $unitId): void
    {
        $unit = Unit::find($unitId);
        if (! $unit || $unit->project_id !== $this->projectId) {
            return;
        }
        $unit->update(['siteplan_coords' => null]);

        $this->dispatch('coords-cleared', unitId: $unitId);
    }
}
