<?php

namespace App\Filament\Bankloan\Resources\ContractResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class BiCheckingRelationManager extends RelationManager
{
    protected static string $relationship = 'biCheckings';

    protected static ?string $title = 'BI Checking';

    protected static ?string $icon = 'heroicon-o-magnifying-glass-circle';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('result')->label('Hasil')->options([
                'CLEAR' => 'Clear', 'NEGATIVE' => 'Negative', 'POSITIVE' => 'Positive', 'PENDING' => 'Pending',
            ]),
            Forms\Components\DatePicker::make('checked_at')->label('Tgl Cek'),
            Forms\Components\DatePicker::make('due_date')->label('Jatuh Tempo (reminder)'),
            Forms\Components\Textarea::make('notes')->label('Catatan')->rows(2)->columnSpanFull(),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('checked_at')->label('Tgl Cek')->date('d M Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('result')->label('Hasil')->badge()->color(fn (?string $state) => match ($state) {
                    'CLEAR', 'POSITIVE' => 'success', 'NEGATIVE' => 'danger', default => 'gray',
                })->placeholder('—'),
                Tables\Columns\TextColumn::make('due_date')->label('Jatuh Tempo')->date('d M Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('notes')->label('Catatan')->limit(40)->placeholder('—'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Tambah BI Checking')])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
