<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Booked\Services\BookedVerificationService;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;

/**
 * Pilih Unit (Booked Manual) — tabel unit TERSEDIA; klik baris → konfirmasi → bentuk
 * Reservasi + Kontrak SPRS + TTS booking fee. Diakses dari Verifikasi Booked (Approve).
 */
class PilihUnitBooked extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'pilih-unit-booked';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.fo.pages.pilih-unit-booked';

    protected static ?string $title = 'Pilih Unit — Booked Manual';

    public ?NupRegistration $registration = null;

    public function mount(): void
    {
        $this->registration = NupRegistration::query()
            ->where('jenis', 'BOOKED')
            ->where('verif_status', 'APPROVED')
            ->whereNull('reservasi_id')
            ->findOrFail(request()->integer('reg'));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Unit::query()
                    ->where('is_active', true)
                    ->when($this->registration?->project_pref_id, fn ($q) => $q->where('project_id', $this->registration->project_pref_id))
                    ->orderBy('blok')->orderBy('no_unit')
            )
            ->columns([
                Tables\Columns\TextColumn::make('blok')->label('Blok')->badge()->color('gray')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('no_unit')->label('No. Unit')->weight('bold')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unitType.name')->label('Tipe')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('lokasi.nama')->label('Lokasi')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('harga_jual')->label('Harga')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'TERSEDIA' => 'Tersedia', 'BOOKING' => 'Booked', 'TERJUAL' => 'Sold', default => $state,
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'TERSEDIA' => 'success', 'BOOKING' => 'warning', 'TERJUAL' => 'danger', default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('lokasi_id')->label('Cluster (Lokasi)')
                    ->options(fn () => Lokasi::query()
                        ->when($this->registration?->project_pref_id, fn ($q) => $q->where('project_id', $this->registration->project_pref_id))
                        ->orderBy('nama')->pluck('nama', 'id'))
                    ->placeholder('— Semua cluster —')
                    ->native(false),
                Tables\Filters\SelectFilter::make('status')->label('Status')->native(false)
                    ->options(['TERSEDIA' => 'Tersedia', 'BOOKING' => 'Booked', 'TERJUAL' => 'Sold']),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\Action::make('pilih')->label('Pilih Unit Ini')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Unit $record) => $record->status === 'TERSEDIA')
                    ->modalHeading('Yakin pilih unit ini?')
                    ->modalDescription(fn (Unit $record) => 'Unit '.trim(($record->blok ? $record->blok.' / ' : '').$record->no_unit)
                        .' (Rp '.number_format((float) $record->harga_jual, 0, ',', '.').') akan dijadikan Kontrak SPRS untuk '.$this->registration->nama_pendaftar.'.')
                    ->form([
                        Forms\Components\Select::make('skema_id')->label('Skema Bayar')->searchable()
                            ->options(fn () => Skema::orderBy('name')->pluck('name', 'id'))
                            ->helperText('Pilih skema bayar. Bisa juga diatur ulang nanti di tab Skema Bayar SPR.'),
                    ])
                    ->modalSubmitActionLabel('Ya, pilih unit ini')
                    ->action(function (Unit $record, array $data) {
                        try {
                            app(BookedVerificationService::class)->pilihUnit($this->registration, $record->id, $data['skema_id'] ?? null);
                            Notification::make()->success()->title('Unit dipilih → Kontrak SPRS + TTS terbentuk')
                                ->body('Lanjut menu SPRS → Simpan sebagai SPR. TTS bisa di-Buka Kuitansi (BKM).')->persistent()->send();

                            return redirect('/fo/verifikasi-booked');
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal pilih unit')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->emptyStateHeading('Tidak ada unit TERSEDIA')
            ->paginated([25, 50, 100]);
    }
}
