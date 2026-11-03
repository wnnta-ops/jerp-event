<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Filament\Fo\Concerns\BuildsStockGrid;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Booked\Services\BookedVerificationService;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Support\PesanGagal;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Pilih Unit (Booked Manual) — tabel stok visual (cluster + tahap → grid blok×no.).
 * Klik unit TERSEDIA → konfirmasi + skema bayar → Reservasi + Kontrak SPRS + TTS.
 */
class PilihUnitBooked extends Page implements HasActions, HasForms
{
    use MematuhiIzinHalaman;

    use BuildsStockGrid;
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?string $slug = 'pilih-unit-booked';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.fo.pages.pilih-unit-booked';

    protected static ?string $title = 'Pilih Unit — Booked Manual';

    public ?NupRegistration $registration = null;

    public ?int $projectId = null;

    public ?int $lokasiId = null;

    public ?string $tahap = null;

    public ?int $pendingUnitId = null;

    public function mount(): void
    {
        $this->registration = NupRegistration::query()
            ->where('jenis', 'BOOKED')
            ->where('verif_status', 'APPROVED')
            ->whereNull('reservasi_id')
            ->findOrFail(request()->integer('reg'));

        $this->projectId = $this->registration->project_pref_id;
    }

    public function updatedLokasiId(): void
    {
        $this->tahap = null;
    }

    /** @return array{rows:array,cols:array,cells:array,total:int} */
    public function getGrid(): array
    {
        return $this->stockGridData($this->projectId, $this->lokasiId, $this->tahap);
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
            ->modalHeading('Yakin pilih unit ini?')
            ->modalDescription($unit
                ? 'Unit '.($unit->kode_unit ?: $unit->no_unit).' akan dijadikan Kontrak SPRS untuk '.$this->registration->nama_pendaftar.'. Harga mengikuti Price List skema bayar yang dipilih.'
                : null)
            ->modalSubmitActionLabel('Ya, pilih unit ini')
            ->form([
                Forms\Components\Select::make('skema_id')->label('Skema Bayar')->searchable()
                    ->options(fn () => Skema::orderBy('name')->pluck('name', 'id'))
                    ->live()
                    ->helperText(function (Forms\Get $get) use ($unit) {
                        if (! $unit || ! $get('skema_id')) {
                            return 'Pilih skema bayar. Bisa diatur ulang nanti di tab Skema Bayar SPR.';
                        }
                        $harga = \App\Modules\FO\MarketingSales\Models\UnitPricelist::hargaUntuk($unit->id, (int) $get('skema_id'));

                        return $harga > 0
                            ? 'Harga unit untuk skema ini: Rp '.number_format($harga, 0, ',', '.')
                            : 'Unit ini belum punya harga untuk skema ini — isi dulu di Price List atau pilih skema lain.';
                    }),
            ])
            ->action(function (array $data) {
                try {
                    app(BookedVerificationService::class)->pilihUnit($this->registration, (int) $this->pendingUnitId, $data['skema_id'] ?? null);
                    Notification::make()->success()->title('Unit dipilih → Kontrak SPRS + TTS terbentuk')
                        ->body('Lanjut menu SPRS → Simpan sebagai SPR. TTS bisa di-Buka Kuitansi (BKM).')->persistent()->send();

                    $this->redirect('/fo/verifikasi-booked');
                } catch (\Throwable $e) {
                    Notification::make()->danger()->title('Gagal pilih unit')->body(PesanGagal::dari($e))->send();
                }
            });
    }
}
