<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\SumberDataResource\Pages;
use App\Modules\FO\Adminjual\Models\SumberData;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Master Sumber Data — kelola pilihan "Sumber Data" pada form pendaftaran NUP/Booked
 * (Instagram, Website, Referral, dst). Tambah/edit sendiri tanpa ubah kode.
 */
class SumberDataResource extends Resource
{
    protected static ?string $model = SumberData::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 25;

    protected static ?string $slug = 'sumber-data';

    protected static ?string $navigationLabel = 'Sumber Data';

    protected static ?string $modelLabel = 'Sumber Data';

    protected static ?string $pluralModelLabel = 'Sumber Data';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('nama')->label('Nama')->required()->maxLength(100)
                ->helperText('Tampil di dropdown Sumber Data pada form pendaftaran NUP/Booked.'),
            Forms\Components\TextInput::make('kode')->label('Kode')->maxLength(32)
                ->placeholder('Otomatis dari nama')
                ->helperText('Nilai yang tersimpan (stabil). Kosongkan → otomatis dari nama. Hindari ubah bila sudah dipakai.'),
            Forms\Components\TextInput::make('urutan')->label('Urutan')->numeric()->default(0)
                ->helperText('Angka kecil tampil lebih dulu.'),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('urutan')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('kode')->label('Kode')->badge()->color('gray')->searchable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('urutan')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Tambah Sumber Data'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSumberData::route('/'),
        ];
    }
}
