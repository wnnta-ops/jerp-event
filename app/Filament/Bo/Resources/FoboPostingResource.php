<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bo\Resources\FoboPostingResource\Pages;
use App\Modules\BO\FoBoBridge\Models\FoboPosting;
use App\Modules\FO\Shared\Events\FoFinancialEvent;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FoboPostingResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = FoboPosting::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'FOBO Bridge';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Posting FOBO';

    protected static ?string $pluralModelLabel = 'Posting FO → BO';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('source_modul')->label('Modul FO')->badge()->searchable(),
                Tables\Columns\TextColumn::make('source_no')->label('No dokumen')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge(),
                Tables\Columns\TextColumn::make('amount')->label('Nominal')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('jurnal.no_jurnal')->label('Jurnal')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (string $state) => match ($state) {
                    'POSTED' => 'success', 'PENDING' => 'warning', 'FAILED' => 'danger', 'SKIPPED' => 'gray', default => 'gray',
                }),
                Tables\Columns\TextColumn::make('posted_at')->label('Diposting')->dateTime('d M Y H:i')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('error_message')->label('Error')->limit(40)->toggleable()->color('danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'PENDING' => 'Pending', 'POSTED' => 'Posted', 'FAILED' => 'Failed', 'SKIPPED' => 'Skipped',
                ]),
                Tables\Filters\SelectFilter::make('jenis')->options([
                    'KAS_MASUK' => 'Kas masuk', 'SALES' => 'Sales', 'KOMISI' => 'Komisi',
                    'AKAD' => 'Akad', 'BUYBACK' => 'Buyback', 'REFUND' => 'Refund', 'MEMO' => 'Memo',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('retry')
                    ->label('Retry')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (FoboPosting $r) => $r->status === 'FAILED')
                    ->requiresConfirmation()
                    ->action(function (FoboPosting $r) {
                        FoFinancialEvent::dispatch(
                            jenis: $r->jenis,
                            sourceModul: $r->source_modul,
                            sourceNo: $r->source_no,
                            amount: (float) $r->amount,
                            meta: $r->meta ?? [],
                        );
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFoboPostings::route('/')];
    }
}
