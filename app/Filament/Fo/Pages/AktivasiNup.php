<?php

namespace App\Filament\Fo\Pages;

use App\Models\Project;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\AktivasiNupService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Aktivasi NUP" — meja reception hari-H launching. List semua NUP yang sudah
 * APPROVED + punya no_nup, dengan tombol Aktivasi / Hold / Cancel. NUP yang
 * sudah pilih unit ditandai readonly ("Sudah Pilih Unit").
 */
class AktivasiNup extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-lock-open';

    protected static ?string $title = 'Aktivasi NUP';

    protected static ?string $slug = 'aktivasi-nup';

    protected static string $view = 'filament.fo.pages.aktivasi-nup';

    protected static bool $shouldRegisterNavigation = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                NupRegistration::query()
                    ->where('verif_status', NupRegistration::VERIF_APPROVED)
                    ->whereNotNull('no_nup')
                    ->with(['event', 'projectPref', 'customer'])
            )
            ->defaultSort('urutan', 'asc')
            ->paginated([10, 25, 50, 100])
            ->columns([
                Tables\Columns\TextColumn::make('no_nup')
                    ->label('NUP')
                    ->badge()->color('primary')
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('urutan')
                    ->label('Urutan')->alignEnd()
                    ->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('event.code')
                    ->label('Lokasi / Event')
                    ->formatStateUsing(fn ($state, NupRegistration $r) => $r->event?->name ?? '—')
                    ->wrap()->searchable(),
                Tables\Columns\TextColumn::make('event.tgl_launching')
                    ->label('Tahap')
                    ->date('d M Y')->toggleable(),
                Tables\Columns\TextColumn::make('tipe_nup')
                    ->label('Tipe')
                    ->placeholder('Reguler')->toggleable(),
                Tables\Columns\TextColumn::make('nama_pendaftar')
                    ->label('Customer')
                    ->description(fn (NupRegistration $r) => $r->customer?->code ?: '—')
                    ->searchable()->wrap(),
                Tables\Columns\TextColumn::make('ref_sales_name')
                    ->label('Sales Agent')
                    ->placeholder('—')
                    ->description(fn (NupRegistration $r) => $r->ref_sales_phone ?: null)
                    ->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (NupRegistration $r) => $this->displayStatus($r))
                    ->color(fn (string $state) => match ($state) {
                        'Belum Aktivasi' => 'warning',
                        'Sudah Aktivasi' => 'success',
                        'Hold' => 'danger',
                        'Sudah Pilih Unit' => 'primary',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('nup_event_id')
                    ->label('Event Launching')
                    ->options(fn () => NupEvent::query()->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('project_pref_id')
                    ->label('Project')
                    ->options(fn () => Project::query()->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('display_status')
                    ->label('Status Aktivasi')
                    ->options([
                        'belum' => 'Belum Aktivasi',
                        'sudah' => 'Sudah Aktivasi',
                        'hold' => 'Hold',
                        'pilih_unit' => 'Sudah Pilih Unit',
                    ])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'belum' => $q->where('is_hold', false)->whereNotIn('status', ['CHECK_IN', 'PILIH_UNIT']),
                        'sudah' => $q->where('is_hold', false)->where('status', 'CHECK_IN'),
                        'hold' => $q->where('is_hold', true),
                        'pilih_unit' => $q->where('status', 'PILIH_UNIT'),
                        default => $q,
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('qr')
                    ->label('QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->visible(fn (NupRegistration $r) => (bool) $r->customer_id)
                    ->url(fn (NupRegistration $r) => $r->customer_id ? route('fo.customer.qr', $r->customer_id) : null, shouldOpenInNewTab: true),
                Tables\Actions\Action::make('aktivasi')
                    ->label('Aktivasi')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (NupRegistration $r) => 'Aktifkan NUP '.$r->no_nup.' untuk pilih unit?')
                    ->visible(fn (NupRegistration $r) => ! $r->is_hold && ! in_array($r->status, ['CHECK_IN', 'PILIH_UNIT'], true))
                    ->action(function (NupRegistration $r) {
                        try {
                            app(AktivasiNupService::class)->aktivasi($r);
                            Notification::make()->title('NUP '.$r->no_nup.' diaktivasi')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('hold')
                    ->label('Hold Aktivasi')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->visible(fn (NupRegistration $r) => ! $r->is_hold && $r->status !== 'PILIH_UNIT')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan hold (opsional)')->rows(2),
                    ])
                    ->action(function (NupRegistration $r, array $data) {
                        try {
                            app(AktivasiNupService::class)->hold($r, $data['reason'] ?? null);
                            Notification::make()->title('NUP '.$r->no_nup.' di-hold')->warning()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('unhold')
                    ->label('Buka Hold')
                    ->icon('heroicon-o-play-circle')
                    ->color('info')
                    ->visible(fn (NupRegistration $r) => $r->is_hold)
                    ->action(function (NupRegistration $r) {
                        app(AktivasiNupService::class)->unhold($r);
                        Notification::make()->title('NUP '.$r->no_nup.' tidak lagi hold')->success()->send();
                    }),
                Tables\Actions\Action::make('cancelAktivasi')
                    ->label('Cancel Aktivasi')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (NupRegistration $r) => $r->status === 'CHECK_IN' && ! $r->is_hold)
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan batal aktivasi')->rows(2),
                    ])
                    ->action(function (NupRegistration $r, array $data) {
                        try {
                            app(AktivasiNupService::class)->cancelAktivasi($r, $data['reason'] ?? null);
                            Notification::make()->title('Aktivasi NUP '.$r->no_nup.' dibatalkan')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Gagal')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->emptyStateHeading('Belum ada NUP siap aktivasi')
            ->emptyStateDescription('Hanya NUP yang sudah APPROVED di Verifikasi Data + punya nomor NUP yang tampil di sini.')
            ->emptyStateIcon('heroicon-o-lock-closed');
    }

    private function displayStatus(NupRegistration $r): string
    {
        if ($r->status === 'PILIH_UNIT') {
            return 'Sudah Pilih Unit';
        }
        if ($r->is_hold) {
            return 'Hold';
        }
        if ($r->status === 'CHECK_IN') {
            return 'Sudah Aktivasi';
        }

        return 'Belum Aktivasi';
    }
}
