<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Komisi\Models\Agent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\ClosingNupService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * "Closing NUP" — naik kontrak. List NUP yang sudah Pilih Unit (punya reservasi
 * yang belum naik kontrak); operator pilih skema cara bayar + sales → buat
 * Kontrak + TTS (UTJ). Kwitansi dibuka dari TTS (menu Finance) atau tombol di sini.
 */
class ClosingNup extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-check-circle';

    protected static ?string $title = 'Closing NUP';

    protected static ?string $slug = 'closing-nup';

    protected static string $view = 'filament.fo.pages.closing-nup';

    protected static bool $shouldRegisterNavigation = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                NupRegistration::query()
                    ->where('status', 'PILIH_UNIT')
                    ->whereNotNull('reservasi_id')
                    ->whereHas('reservasi', fn ($q) => $q->whereDoesntHave('kontrak'))
                    ->with(['customer', 'reservasi.unit.unitType'])
            )
            ->defaultSort('updated_at', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('no_nup')->label('NUP')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($state, NupRegistration $r) => $r->customer?->full_name ?: $r->nama_pendaftar)
                    ->description(fn (NupRegistration $r) => $r->customer?->code ?: null)
                    ->searchable()->wrap(),
                Tables\Columns\TextColumn::make('reservasi.unit.kode_unit')
                    ->label('Unit')
                    ->formatStateUsing(fn ($state, NupRegistration $r) => $r->reservasi?->unit?->kode_unit ?: $r->reservasi?->unit?->label)
                    ->description(fn (NupRegistration $r) => $r->reservasi?->unit?->unitType?->name)
                    ->searchable(),
                Tables\Columns\TextColumn::make('reservasi.harga_jual')->label('Harga')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('reservasi.no_reservasi')->label('Reservasi')->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('closing')
                    ->label('Proses Closing')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->modalHeading(fn (NupRegistration $r) => 'Closing NUP '.$r->no_nup.' — '.($r->customer?->full_name ?: $r->nama_pendaftar))
                    ->modalSubmitActionLabel('Buat Kontrak')
                    ->form([
                        Forms\Components\DatePicker::make('tgl_kontrak')->label('Tanggal Kontrak')->default(now())->required(),
                        Forms\Components\Select::make('skema_id')
                            ->label('Skema Cara Bayar')
                            ->options(fn (NupRegistration $r) => Skema::query()
                                ->where('is_active', true)
                                ->when($r->reservasi?->unit?->project_id, fn ($q, $pid) => $q->where('project_id', $pid))
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Skema $s) => [$s->id => $s->code.' — '.$s->name]))
                            ->searchable()->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                $s = $state ? Skema::find($state) : null;
                                if ($s) {
                                    $set('cara_bayar', match ($s->cara_bayar) {
                                        'CASH' => 'CASH', 'CASH_BERTAHAP', 'CICILAN' => 'CASH_BERTAHAP', default => 'KPR',
                                    });
                                }
                            }),
                        Forms\Components\Select::make('cara_bayar')
                            ->label('Cara Bayar')
                            ->options(['KPR' => 'KPR', 'CASH_BERTAHAP' => 'Cash bertahap', 'CASH' => 'Cash keras'])
                            ->default('KPR')->required(),
                        Forms\Components\Select::make('agent_id')
                            ->label('Sales')
                            ->options(fn () => Agent::query()->where('is_active', true)->orderBy('name')->get()
                                ->mapWithKeys(fn (Agent $a) => [$a->id => ($a->code ? $a->code.' — ' : '').$a->name]))
                            ->searchable()->placeholder('— pilih sales —'),
                    ])
                    ->action(function (NupRegistration $r, array $data) {
                        try {
                            $result = app(ClosingNupService::class)->close($r, [
                                'skema_id' => (int) $data['skema_id'],
                                'agent_id' => $data['agent_id'] ?? null,
                                'cara_bayar' => $data['cara_bayar'] ?? 'KPR',
                                'tgl_kontrak' => $data['tgl_kontrak'] ?? now()->toDateString(),
                            ]);
                            Notification::make()
                                ->success()
                                ->title('Closing berhasil — Kontrak '.$result['kontrak']->no_kontrak)
                                ->body('TTS '.$result['tts']->no_tts.' dibuat. Buka kwitansi di menu Finance AR → TTS (aksi "Buka Kuitansi").')
                                ->actions([
                                    NotificationAction::make('spr')->label('Cetak SPR')
                                        ->url(route('fo.cetak.spr', $result['kontrak']), shouldOpenInNewTab: true),
                                    NotificationAction::make('jadwal')->label('Jadwal Pembayaran')
                                        ->url(route('fo.cetak.jadwal', $result['kontrak']), shouldOpenInNewTab: true),
                                ])
                                ->persistent()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal closing')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->emptyStateHeading('Belum ada NUP siap closing')
            ->emptyStateDescription('Hanya NUP yang sudah Pilih Unit (punya reservasi & belum naik kontrak) yang tampil di sini.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
