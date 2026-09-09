<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bo\Resources\KaryawanResource\Pages;
use App\Modules\BO\HR\Models\Karyawan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class KaryawanResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Karyawan::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'HR';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Karyawan';

    protected static ?string $pluralModelLabel = 'Karyawan';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('nik')->label('NIK')->required()->maxLength(32),
                Forms\Components\TextInput::make('nama')->label('Nama')->required(),
                Forms\Components\Select::make('dept_id')->label('Departemen')->relationship('dept', 'nama')->searchable()->preload(),
                Forms\Components\TextInput::make('jabatan')->label('Jabatan'),
                Forms\Components\DatePicker::make('tgl_masuk')->label('Tgl masuk'),
                Forms\Components\Select::make('status')->label('Status')->options(['AKTIF' => 'Aktif', 'NONAKTIF' => 'Non-aktif'])->default('AKTIF')->required(),
                Forms\Components\TextInput::make('email')->label('Email')->email(),
                Forms\Components\TextInput::make('telp')->label('Telepon')->maxLength(32),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nik')->label('NIK')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('dept.nama')->label('Departemen')->toggleable(),
                Tables\Columns\TextColumn::make('jabatan')->label('Jabatan')->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => $state === 'AKTIF' ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('dept_id')->relationship('dept', 'nama')->label('Departemen'),
                Tables\Filters\SelectFilter::make('status')->options(['AKTIF' => 'Aktif', 'NONAKTIF' => 'Non-aktif']),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('nama');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKaryawans::route('/'),
            'create' => Pages\CreateKaryawan::route('/create'),
            'edit' => Pages\EditKaryawan::route('/{record}/edit'),
        ];
    }
}
