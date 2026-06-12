<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\UnitTypeResource\Pages;
use App\Modules\FO\MarketingSales\Models\UnitType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UnitTypeResource extends Resource
{
    protected static ?string $model = UnitType::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Master FO';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Tipe Unit';

    protected static ?string $pluralModelLabel = 'Tipe Unit';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tipe Unit')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('project_id')
                        ->label('Proyek')
                        ->relationship('project', 'name')
                        ->required()
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('code')
                        ->label('Kode')
                        ->required()
                        ->maxLength(32),
                    Forms\Components\TextInput::make('name')
                        ->label('Nama tipe')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('harga_dasar')
                        ->label('Harga dasar')
                        ->numeric()
                        ->rupiah()
                        ->default(0),
                    Forms\Components\TextInput::make('luas_tanah')->label('Luas tanah (m²)')->numeric(),
                    Forms\Components\TextInput::make('luas_bangunan')->label('Luas bangunan (m²)')->numeric(),
                    Forms\Components\TextInput::make('jumlah_kamar')->label('Jumlah kamar')->numeric(),
                    Forms\Components\TextInput::make('jumlah_kmandi')->label('Jumlah kamar mandi')->numeric(),
                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->sortable()->searchable()->badge(),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('project.name')->label('Proyek')->sortable(),
                Tables\Columns\TextColumn::make('luas_tanah')->label('LT')->numeric(2)->alignEnd(),
                Tables\Columns\TextColumn::make('luas_bangunan')->label('LB')->numeric(2)->alignEnd(),
                Tables\Columns\TextColumn::make('harga_dasar')
                    ->label('Harga dasar')
                    ->money('IDR', divideBy: 0)
                    ->alignEnd(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Proyek'),
                Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnitTypes::route('/'),
            'create' => Pages\CreateUnitType::route('/create'),
            'edit' => Pages\EditUnitType::route('/{record}/edit'),
        ];
    }
}
