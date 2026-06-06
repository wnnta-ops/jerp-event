<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Bo\Resources\IvtLocationResource\Pages;
use App\Modules\BO\Inventory\Models\IvtLocation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IvtLocationResource extends Resource
{
    protected static ?string $model = IvtLocation::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 31;

    protected static ?string $modelLabel = 'Lokasi Gudang';

    protected static ?string $pluralModelLabel = 'Lokasi Gudang';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('kode')->label('Kode')->required()->maxLength(32),
            Forms\Components\TextInput::make('nama')->label('Nama')->required(),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kode')->label('Kode')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('kode');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageIvtLocations::route('/')];
    }
}
