<?php

namespace App\Filament\Fo\Widgets;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentKontrak extends BaseWidget
{
    protected static ?string $heading = '10 Kontrak terbaru';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Kontrak::query()->latest('tgl_kontrak')->limit(10))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No kontrak')->badge(),
                Tables\Columns\TextColumn::make('tgl_kontrak')->label('Tgl')->date('d M Y'),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->limit(30),
                Tables\Columns\TextColumn::make('unit.no_unit')
                    ->label('Unit')
                    ->formatStateUsing(fn ($record) => trim(($record->unit?->blok ? $record->unit->blok.' / ' : '').$record->unit?->no_unit)),
                Tables\Columns\TextColumn::make('cara_bayar')->label('Cara')->badge(),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Total')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'DRAFT' => 'gray', 'BOOKING' => 'info', 'AKTIF' => 'success',
                    'LUNAS' => 'primary', 'BATAL' => 'danger', default => 'gray',
                }),
            ]);
    }
}
