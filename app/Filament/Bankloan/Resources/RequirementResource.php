<?php

namespace App\Filament\Bankloan\Resources;

use App\Filament\Bankloan\Resources\RequirementResource\Pages;
use App\Modules\BankLoan\Models\Requirement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RequirementResource extends Resource
{
    protected static ?string $model = Requirement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?string $modelLabel = 'Persyaratan';

    protected static ?string $pluralModelLabel = 'Persyaratan Dokumen';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')->label('Kode')->maxLength(32),
            Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255),
            Forms\Components\Textarea::make('description')->label('Keterangan')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Kode')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('description')->label('Keterangan')->limit(50)->placeholder('—'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRequirements::route('/')];
    }
}
