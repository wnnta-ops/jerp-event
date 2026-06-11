<?php

namespace App\Filament\Bankloan\Resources;

use App\Filament\Bankloan\Resources\RetentionCategoryResource\Pages;
use App\Modules\BankLoan\Models\RetentionCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RetentionCategoryResource extends Resource
{
    protected static ?string $model = RetentionCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $modelLabel = 'Kategori Retensi';

    protected static ?string $pluralModelLabel = 'Kategori Retensi';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255),
            Forms\Components\Textarea::make('description')->label('Keterangan')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('description')->label('Keterangan')->limit(60)->placeholder('—'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRetentionCategories::route('/')];
    }
}
