<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Komisi\Models\Blt;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Pengajuan BLT — BLT DRAFT diajukan untuk pencairan (DRAFT → APPROVED). Per type/level. */
class PengajuanBlt extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'pengajuan-blt';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Komisi';

    protected static string $view = 'filament.fo.pages.generate-komisi';

    protected static ?string $title = 'Pengajuan BLT';

    public function table(Table $table): Table
    {
        return $table
            ->query(Blt::query()->with(['agent', 'kontrak'])->where('status', 'DRAFT'))
            ->heading('Pengajuan BLT')
            ->description('BLT berstatus DRAFT — ajukan untuk pencairan (→ APPROVED). Bisa difilter per type.')
            ->columns([
                Tables\Columns\TextColumn::make('no_blt')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('level_type')->label('Type')->badge()->color(fn (?string $s) => match ($s) {
                    'SALES' => 'success', 'KOORDINATOR' => 'warning', 'KANTOR' => 'info', default => 'gray',
                }),
                Tables\Columns\TextColumn::make('kontrak.no_kontrak')->label('Kontrak')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('nama_user')->label('Nama User')->placeholder('—')->limit(20),
                Tables\Columns\TextColumn::make('nilai_blt')->label('Nilai BLT')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('pph_amount')->label('PPh')->money('IDR')->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('nett')->label('NETT')->money('IDR')->alignEnd()->weight('bold'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level_type')->label('Type')
                    ->options(['SALES' => 'Sales', 'KOORDINATOR' => 'Koordinator', 'KANTOR' => 'Kantor']),
            ])
            ->actions([
                Tables\Actions\Action::make('ajukan')->label('Ajukan')->icon('heroicon-o-paper-airplane')->color('warning')->button()
                    ->requiresConfirmation()
                    ->action(function (Blt $r) {
                        $r->update(['status' => 'APPROVED']);
                        Notification::make()->success()->title('Diajukan — '.$r->no_blt)->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('ajukan_bulk')->label('Ajukan Terpilih')->icon('heroicon-o-paper-airplane')->color('warning')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        $records->each->update(['status' => 'APPROVED']);
                        Notification::make()->success()->title(count($records).' BLT diajukan')->send();
                    })->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('tgl_blt', 'desc')->paginated([10, 25, 50]);
    }
}
