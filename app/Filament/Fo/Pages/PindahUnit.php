<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Fo\Concerns\BuildsStockGrid;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Services\PindahUnitService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pindah Unit (per kontrak SPR) — pilih cluster + tahap → tabel stok visual → klik
 * unit TERSEDIA → konfirmasi → kontrak pindah unit. Diakses dari daftar Pindah Unit.
 */
class PindahUnit extends Page implements HasActions, HasForms
{
    use BuildsStockGrid;
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $slug = 'pindah-unit-pilih';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.fo.pages.pindah-unit';

    protected static ?string $title = 'Pindah Unit';

    public ?Kontrak $kontrak = null;

    public ?int $projectId = null;

    public ?int $lokasiId = null;

    public ?string $tahap = null;

    public ?int $pendingUnitId = null;

    public function mount(): void
    {
        $this->kontrak = Kontrak::with(['unit.lokasi', 'customer', 'agent'])
            ->where('tahap', 'SPR')->where('status', 'AKTIF')
            ->findOrFail(request()->integer('kontrak'));

        $this->projectId = $this->kontrak->unit?->project_id;
        $this->lokasiId = $this->kontrak->unit?->lokasi_id;
        $this->tahap = $this->kontrak->unit?->tahap;
    }

    public function updatedLokasiId(): void
    {
        $this->tahap = null;
    }

    /** @return array{rows:array,cols:array,cells:array,total:int} */
    public function getGrid(): array
    {
        return $this->stockGridData($this->projectId, $this->lokasiId, $this->tahap, $this->kontrak?->unit_id);
    }

    public function selectGridUnit(int $unitId): void
    {
        $this->pendingUnitId = $unitId;
        $this->mountAction('konfirmasi');
    }

    public function konfirmasi(): Action
    {
        $unit = $this->pendingUnitId ? Unit::find($this->pendingUnitId) : null;

        return Action::make('konfirmasi')
            ->modalHeading('Pindah ke unit ini?')
            ->modalDescription($unit
                ? 'Unit baru: '.($unit->kode_unit ?: $unit->no_unit).' (Rp '.number_format((float) $unit->harga_jual, 0, ',', '.').'). Unit lama dikembalikan TERSEDIA.'
                : null)
            ->modalSubmitActionLabel('Ya, pindah unit')
            ->form([
                Forms\Components\DatePicker::make('tgl_pindah')->label('Tanggal Pindah Unit')->default(now())->required(),
                Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2),
            ])
            ->action(function (array $data) {
                try {
                    $pu = app(PindahUnitService::class)->move($this->kontrak, (int) $this->pendingUnitId, $data);
                    Notification::make()->success()->title('Pindah Unit selesai — '.$pu->no_pindah)
                        ->body('Unit kontrak kini: '.($pu->unitBaru?->kode_unit ?: $pu->unitBaru?->no_unit))->persistent()->send();

                    $this->redirect('/fo/pindah-unit');
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Gagal pindah unit')->body($e->getMessage())->send();
                }
            });
    }
}
