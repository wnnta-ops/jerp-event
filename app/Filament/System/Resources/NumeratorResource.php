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
                        ->live(onBlur: true)
                        ->helperText(fn (Forms\Get $get) => 'Placeholder: {YYYY}, {YY}, {MM}, {M}, {ROMAWI} (bulan Romawi), {DD}, {seq:N}. '
                            . 'Contoh hasil berikutnya: ' . app(\App\Services\Numerator\NumeratorService::class)
                                ->contoh((string) $get('format'), (int) $get('last_seq') + 1)),
                    Forms\Components\Select::make('reset')
                        ->label('Reset nomor urut')
                        ->options(['tidak' => 'Tidak pernah — nomor terus bersambung'])
                        ->placeholder('Ikut format (tahunan/bulanan/harian)')
                        ->live()
                        ->helperText('Pilih "Tidak pernah" untuk nomor yang bersambung lintas tahun, mis. SPR penerus eProperty.'),
                    Forms\Components\TextInput::make('period')
                        ->label('Periode reset')
                        ->maxLength(16)
                        ->visible(fn (Forms\Get $get) => $get('reset') !== 'tidak')
                        ->helperText('Contoh: "2026" (yearly), "202605" (monthly). Kosongkan jika tidak ada reset.'),
                    Forms\Components\TextInput::make('last_seq')
                        ->label('Seq terakhir (cutoff)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->live(onBlur: true)
                        ->helperText(function (Forms\Get $get) {
                            $teks = 'Nomor berikutnya = angka ini + 1. Boleh diubah kapan saja (mis. cutoff dari eProperty).';
                            $max = \App\Services\Numerator\NumeratorService::tertinggiDiData((string) $get('code'), (string) $get('format'));
                            if ($max !== null) {
                                $teks .= ' Nomor tertinggi di data sekarang: ' . $max . '. Nomor yang sudah dipakai otomatis dilewati.';
                            }

                            return $teks;
                        }),
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
                Tables\Columns\TextColumn::make('period')->label('Periode')->placeholder('—')->sortable()
                    ->formatStateUsing(fn ($state, $record) => $record->reset === 'tidak' ? 'tanpa reset' : $state),
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
