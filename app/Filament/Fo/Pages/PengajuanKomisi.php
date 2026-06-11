<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Komisi;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Pengajuan Komisi — komisi DRAFT diajukan untuk pencairan (DRAFT → APPROVED). */
class PengajuanKomisi extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'pengajuan-komisi';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Komisi';

    protected static string $view = 'filament.fo.pages.generate-komisi';

    protected static ?string $title = 'Pengajuan Komisi';

    public function table(Table $table): Table
    {
        return $table
            ->query(Komisi::query()->with(['agent', 'kontrak'])->where('status', 'DRAFT'))
            ->heading('Pengajuan Komisi')
            ->description('Komisi berstatus DRAFT — ajukan untuk pencairan (→ APPROVED).')
            ->columns([
                Tables\Columns\TextColumn::make('no_komisi')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tgl_komisi')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('kontrak.no_kontrak')->label('Kontrak')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('agent.name')->label('Agent')->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')->label('Komisi')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('pph_amount')->label('PPh')->money('IDR')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('amount_net')->label('NETT')->money('IDR')->alignEnd()->weight('bold'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color('gray'),
            ])
            ->actions([
                Tables\Actions\Action::make('ajukan')->label('Ajukan')->icon('heroicon-o-paper-airplane')->color('warning')->button()
                    ->requiresConfirmation()
                    ->action(function (Komisi $r) {
                        $r->update(['status' => 'APPROVED']);
                        Notification::make()->success()->title('Diajukan — '.$r->no_komisi.' (APPROVED, siap realisasi)')->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('ajukan_bulk')->label('Ajukan Terpilih')->icon('heroicon-o-paper-airplane')->color('warning')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        $records->each->update(['status' => 'APPROVED']);
                        Notification::make()->success()->title(count($records).' komisi diajukan')->send();
                    })->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('tgl_komisi', 'desc')->paginated([10, 25, 50]);
    }
}
