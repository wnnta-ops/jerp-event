<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Bo\Resources\SupplierResource\Pages;
use App\Modules\BO\AccountsPayable\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Hutang (AP)';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Supplier';

    protected static ?string $pluralModelLabel = 'Supplier';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('kode')->label('Kode')->required()->maxLength(32),
                Forms\Components\TextInput::make('nama')->label('Nama')->required(),
                Forms\Components\TextInput::make('npwp')->label('NPWP')->maxLength(32),
                Forms\Components\TextInput::make('telp')->label('Telepon')->maxLength(32),
                Forms\Components\TextInput::make('email')->label('Email')->email(),
                Forms\Components\TextInput::make('bank_account')->label('Rekening')->maxLength(64),
                Forms\Components\Textarea::make('alamat')->rows(2)->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kode')->label('Kode')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('npwp')->label('NPWP')->toggleable(),
                Tables\Columns\TextColumn::make('telp')->label('Telp')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('nama');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}
