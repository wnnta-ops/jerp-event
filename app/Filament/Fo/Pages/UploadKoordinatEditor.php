<?php

namespace App\Filament\Fo\Pages;

use App\Models\Project;
use App\Modules\FO\Adminjual\Exports\UnitKoordinatExport;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\SiteplanBlock;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

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

    public ?int $lokasiId = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    /** Tombol Export Excel — koordinat semua unit cluster terpilih (No. Unit / Peta / Koordinat). */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon('heroicon-o-table-cells')
                ->color('success')
                ->visible(fn (): bool => filled($this->data['lokasi_id'] ?? null))
                ->action(function () {
                    $lokasiId = (int) ($this->data['lokasi_id'] ?? 0);
                    if (! $lokasiId) {
                        Notification::make()->title('Pilih Cluster dulu')->warning()->send();

                        return null;
                    }
                    $nama = Str::slug(Lokasi::find($lokasiId)?->nama ?? 'unit');

                    return Excel::download(new UnitKoordinatExport($lokasiId), 'koordinat-'.$nama.'-'.now()->format('Ymd').'.xlsx');
                }),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Pilih Cluster')
                ->description('Pilih Cluster (Lokasi) untuk muat siteplan + unit cluster itu. Gambar siteplan diatur per cluster di menu Upload Site Plan (kosong → fallback gambar project).')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('project_id')
                        ->label('Project')->required()
                        ->options(fn () => Project::pluck('name', 'id'))
                        ->searchable()->preload()->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            $this->projectId = $state ? (int) $state : null;
                            $this->lokasiId = null;
                            $set('lokasi_id', null);
                        }),
                    Forms\Components\Select::make('lokasi_id')
                        ->label('Cluster / Lokasi')->required()
                        ->options(fn (Forms\Get $get) => $get('project_id')
                            ? Lokasi::where('project_id', $get('project_id'))->orderBy('kode')->pluck('nama', 'id')
                            : [])
                        ->disabled(fn (Forms\Get $get) => ! $get('project_id'))
                        ->searchable()->preload()->live()
                        ->afterStateUpdated(fn ($state) => $this->lokasiId = $state ? (int) $state : null),
                ]),
        ]);
    }

    public function getLokasi(): ?Lokasi
    {
        return $this->lokasiId ? Lokasi::find($this->lokasiId) : null;
    }

    /** Payload yang diconsume Alpine.js untuk inisialisasi map (per Cluster/Lokasi). */
    public function getEditorPayload(): array
    {
        $lokasi = $this->getLokasi();
        $sp = $lokasi?->resolvedSiteplan();
        if (! $lokasi || ! $sp) {
            return [];
        }

        $units = Unit::where('lokasi_id', $lokasi->id)
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

        $blocks = SiteplanBlock::where('lokasi_id', $lokasi->id)
            ->get(['id', 'coords', 'label', 'color', 'opacity'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'coords' => is_array($b->coords) ? $b->coords : [],
                'label' => $b->label,
                'color' => $b->color,
                'opacity' => (float) $b->opacity,
            ])->values()->all();

        return [
            'project' => [
                'id' => $lokasi->id,
                'name' => $lokasi->nama,
                'imageUrl' => $sp['url'],
                'width' => $sp['width'],
                'height' => $sp['height'],
            ],
            'units' => $units,
            'blocks' => $blocks,
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
        if (! $unit || $unit->lokasi_id !== $this->lokasiId) {
            Notification::make()->title('Unit tidak ditemukan / beda cluster')->danger()->send();

            return;
        }

        $unit->update(['siteplan_coords' => $clean]);

        $this->dispatch('coords-saved', unitId: $unitId, count: count($clean));
    }

    public function clearCoords(int $unitId): void
    {
        $unit = Unit::find($unitId);
        if (! $unit || $unit->lokasi_id !== $this->lokasiId) {
            return;
        }
        $unit->update(['siteplan_coords' => null]);

        $this->dispatch('coords-cleared', unitId: $unitId);
    }

    /**
     * Simpan/ubah blok area (penanda tahap, tidak terikat unit). $id null = baru.
     * Mengembalikan id blok (dipakai JS untuk melacak layer). Koordinat ruang image.
     */
    public function saveBlock(?int $id, array $coords, ?string $label): ?int
    {
        $lokasi = $this->getLokasi();
        if (! $lokasi) {
            return null;
        }
        if (count($coords) < 3) {
            Notification::make()->title('Blok minimal 3 titik')->danger()->send();

            return $id;
        }

        $clean = [];
        foreach ($coords as $pair) {
            if (! is_array($pair) || count($pair) !== 2) {
                return $id;
            }
            $clean[] = [(int) round($pair[0]), (int) round($pair[1])];
        }

        $block = $id ? SiteplanBlock::find($id) : new SiteplanBlock;
        if ($id && (! $block || $block->lokasi_id !== $lokasi->id)) {
            return null;
        }

        $block->fill([
            'project_id' => $lokasi->project_id,
            'lokasi_id' => $lokasi->id,
            'coords' => $clean,
            'label' => $label !== null ? trim($label) : $block->label,
            'created_by' => auth()->id(),
        ]);
        $block->save();

        return $block->id;
    }

    public function deleteBlock(int $id): void
    {
        $lokasi = $this->getLokasi();
        $block = SiteplanBlock::find($id);
        if ($block && $lokasi && $block->lokasi_id === $lokasi->id) {
            $block->delete();
        }
    }

    /** Simpan ulang SEMUA posisi unit + blok dalam satu permintaan (tombol Simpan). */
    public function saveAll(array $units, array $blocks): void
    {
        $lokasi = $this->getLokasi();
        if (! $lokasi) {
            return;
        }

        $nUnit = 0;
        foreach ($units as $u) {
            $id = (int) ($u['id'] ?? 0);
            $clean = $this->cleanCoords($u['coords'] ?? []);
            if (! $id || $clean === null) {
                continue;
            }
            $unit = Unit::find($id);
            if ($unit && $unit->lokasi_id === $this->lokasiId) {
                $unit->update(['siteplan_coords' => $clean]);
                $nUnit++;
            }
        }

        $nBlock = 0;
        foreach ($blocks as $b) {
            $id = (int) ($b['id'] ?? 0);
            $clean = $this->cleanCoords($b['coords'] ?? []);
            if (! $id || $clean === null) {
                continue;
            }
            $block = SiteplanBlock::find($id);
            if ($block && $block->lokasi_id === $lokasi->id) {
                $block->update([
                    'coords' => $clean,
                    'label' => array_key_exists('label', $b) ? trim((string) $b['label']) : $block->label,
                ]);
                $nBlock++;
            }
        }

        Notification::make()->title("Tersimpan — {$nUnit} unit, {$nBlock} blok")->success()->send();
    }

    /**
     * Bersihkan & validasi koordinat (≥3 titik, pasangan numeric). Null bila invalid.
     *
     * @return array<int,array{0:int,1:int}>|null
     */
    private function cleanCoords(array $coords): ?array
    {
        if (count($coords) < 3) {
            return null;
        }
        $clean = [];
        foreach ($coords as $pair) {
            if (! is_array($pair) || count($pair) !== 2) {
                return null;
            }
            $clean[] = [(int) round($pair[0]), (int) round($pair[1])];
        }

        return $clean;
    }
}
