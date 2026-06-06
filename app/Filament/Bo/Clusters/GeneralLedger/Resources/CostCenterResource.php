<?php

namespace App\Filament\Bo\Clusters\GeneralLedger\Resources;

use App\Filament\Bo\Clusters\GeneralLedger;
use App\Filament\Bo\Clusters\GeneralLedger\Resources\CostCenterResource\Pages;
use App\Models\Company;
use App\Models\CostCenter;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CostCenterResource extends Resource
{
    protected static ?string $model = CostCenter::class;

    protected static ?string $cluster = GeneralLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Master';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Cost Center';

    protected static ?string $pluralModelLabel = 'Cost Center';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')->label('Kode')->required()->maxLength(32),
            Forms\Components\TextInput::make('name')->label('Nama')->required(),
            Forms\Components\Select::make('company_id')->label('Perusahaan')
                ->options(fn () => Company::query()->pluck('name', 'id'))->searchable(),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('company.name')->label('Perusahaan')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCostCenters::route('/')];
    }
}
