<?php

namespace App\Filament\System\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\System\Resources\NumeratorResource\Pages;
use App\Models\Numerator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NumeratorResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Numerator::class;

    protected static ?string $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationGroup = 'Konfigurasi';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Penomoran';

    protected static ?string $pluralModelLabel = 'Penomoran (Numerator)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Konfigurasi Penomoran')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('company_id')
                        ->label('Perusahaan')
                        ->relationship('company', 'name')
                        ->searchable()
                        ->preload()
                        ->helperText('Kosongkan jika berlaku untuk semua perusahaan.'),
                    Forms\Components\TextInput::make('code')
                        ->label('Kode jenis dokumen')
                        ->required()
                        ->maxLength(32)
                        ->helperText('Contoh: KPR, TTS, BKM, BKK'),
                    Forms\Components\TextInput::make('format')
                        ->label('Format')
                        ->required()
                        ->maxLength(255)
                        ->default('{code}-{YYYY}-{seq:5}')
                        ->helperText('Placeholder: {code}, {YYYY}, {YY}, {MM}, {seq:N} (zero-padded)'),
                    Forms\Components\TextInput::make('period')
                        ->label('Periode reset')
                        ->maxLength(16)
                        ->helperText('Contoh: "2026" (yearly), "202605" (monthly). Kosongkan jika tidak ada reset.'),
                    Forms\Components\TextInput::make('last_seq')
                        ->label('Nomor urut terakhir')
                        ->numeric()
                        ->default(0)
                        ->disabled(fn (string $operation) => $operation === 'edit')
                        ->helperText('Hanya di-set saat membuat numerator baru. Sistem akan increment otomatis.'),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Jenis')->searchable()->sortable()->badge(),
                Tables\Columns\TextColumn::make('format')->label('Format')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('period')->label('Periode')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('last_seq')->label('Seq terakhir')->numeric()->alignEnd(),
                Tables\Columns\TextColumn::make('company.name')->label('Perusahaan')->placeholder('Semua')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('company_id')->relationship('company', 'name')->label('Perusahaan'),
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
            'index' => Pages\ListNumerators::route('/'),
            'create' => Pages\CreateNumerator::route('/create'),
            'edit' => Pages\EditNumerator::route('/{record}/edit'),
        ];
    }
}
