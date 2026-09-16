<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\UnitResource\Pages;
use App\Modules\FO\MarketingSales\Models\Tahap;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Support\Luas;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UnitResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Unit::class;

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Unit';

    protected static ?string $pluralModelLabel = 'Unit (kavling/rumah)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Unit')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('lokasi_id')
                        ->label('Lokasi')
                        ->relationship('lokasi', 'nama')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->kode.' — '.$record->nama)
                        ->required()->searchable()->preload()->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('tahap_id', null))
                        ->helperText('Segmen pertama No. Unit. Atur di Setup Unit → Lokasi.'),
                    Forms\Components\Select::make('tahap_id')
                        ->label('Tahap')
                        ->options(fn (Forms\Get $get) => $get('lokasi_id')
                            ? Tahap::query()
                                ->where('lokasi_id', $get('lokasi_id'))->where('is_active', true)
                                ->orderBy('kode')->pluck('nama', 'id')
                            : [])
                        ->getOptionLabelUsing(fn ($value) => optional(Tahap::find($value))->nama)
                        ->searchable()
                        ->helperText('Segmen kedua No. Unit. Atur di Setup Unit → Tahap.'),
                    Forms\Components\Select::make('unit_type_id')
                        ->label('Tipe')
                        ->relationship('unitType', 'name')
                        ->searchable()
                        ->preload(),
                    Forms\Components\TextInput::make('cluster')->label('Cluster')->maxLength(255),
                    Forms\Components\TextInput::make('blok')->label('Blok')->maxLength(16),
                    // Bentrok kombinasi Lokasi+Tahap+Blok+Nomer diperiksa DI SINI, bukan
                    // dibiarkan sampai ke database. Tanpa ini, memindahkan unit ke blok
                    // yang nomornya sudah terpakai berakhir sebagai halaman 500 tanpa
                    // penjelasan — operator tidak tahu unit mana yang bentrok.
                    Forms\Components\TextInput::make('no_unit')
                        ->label('Nomer')
                        ->required()
                        ->maxLength(32)
                        ->helperText('No. Unit otomatis: Lokasi-Tahap-Blok-Nomer.')
                        ->rule(function (Forms\Get $get, ?Unit $record) {
                            return function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                $bentrok = Unit::query()
                                    ->where('lokasi_id', $get('lokasi_id'))
                                    ->where('tahap_id', $get('tahap_id'))
                                    ->where('blok', $get('blok'))
                                    ->where('no_unit', $value)
                                    ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                    ->first();

                                if ($bentrok) {
                                    $fail('Nomer ini sudah dipakai unit '.$bentrok->kode_unit
                                        .' ('.$bentrok->status.'). Pakai nomer lain, atau pindahkan unit tersebut lebih dulu.');
                                }
                            };
                        }),
                    Forms\Components\Placeholder::make('kode_unit_preview')
                        ->label('No. Unit (otomatis)')
                        ->content(fn (?Unit $record) => $record?->kode_unit ?: '— tersimpan otomatis saat disimpan —'),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'TERSEDIA' => 'Tersedia',
                            'BOOKING' => 'Booking',
                            'TERJUAL' => 'Terjual',
                            'LUNAS' => 'Lunas',
                            'HOLD' => 'Hold',
                            'BATAL' => 'Batal',
                        ])
                        ->default('TERSEDIA')
                        ->required(),
                ]),

            Forms\Components\Section::make('Spesifikasi & Harga')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('luas_tanah')->label('Luas tanah (m²)')->numeric()
                        ->formatStateUsing(fn ($state) => Luas::isian($state)),
                    Forms\Components\TextInput::make('luas_bangunan')->label('Luas bangunan (m²)')->numeric()
                        ->formatStateUsing(fn ($state) => Luas::isian($state)),
                    Forms\Components\TextInput::make('harga_jual')->label('Harga jual')->numeric()->rupiah()->default(0),

                    // Alamat/deskripsi unit — TULISAN YANG BENAR-BENAR DIPAKAI orang:
                    // label di Pilih Unit & siteplan, deskripsi unit di cetak SPR, baris
                    // "Alamat Unit" di Faktur Penjualan, dan penanda {unit} pada surat
                    // Collection. Terisi dari migrasi (1358 dari 1358) tapi tidak pernah
                    // punya isian di layar ini, sehingga salah ketik tidak bisa dibetulkan
                    // dari mana pun. Keterangan di bawah TIDAK dibaca keempatnya — mengetik
                    // koreksi di sana tidak akan mengubah satu pun dokumen.
                    Forms\Components\Textarea::make('alamat')
                        ->label('Alamat / Deskripsi Unit')
                        ->rows(2)->columnSpanFull()->maxLength(500)
                        ->helperText('Dipakai di siteplan, cetak SPR, Faktur Penjualan, dan surat Collection. Contoh: JL. JAVA RESIDENCE KRIAN 2 II NO. 3 - TIPE AKASIA 6X15 - LT. 90 LB. 36'),

                    Forms\Components\Textarea::make('keterangan')->label('Keterangan (catatan internal)')
                        ->rows(2)->columnSpanFull()
                        ->helperText('Catatan bebas untuk tim sendiri — tidak ikut tercetak di dokumen mana pun.'),
                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.code')->label('Proyek')->sortable()->badge()->toggleable(),
                Tables\Columns\TextColumn::make('kode_unit')->label('No. Unit')->badge()->color('info')->sortable()->searchable()->copyable(),
                Tables\Columns\TextColumn::make('lokasi.kode')->label('Lokasi')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('tahap')->label('Tahap')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('blok')->label('Blok')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('no_unit')->label('Nomer')->sortable()->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('unitType.name')->label('Tipe')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('cluster')->label('Cluster')->toggleable(),
                Tables\Columns\TextColumn::make('luas_tanah')->label('LT')
                    ->formatStateUsing(fn ($state) => Luas::rapi($state))->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('luas_bangunan')->label('LB')
                    ->formatStateUsing(fn ($state) => Luas::rapi($state))->alignEnd()->toggleable(),
                Tables\Columns\TextColumn::make('harga_jual')->label('Harga jual')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'TERSEDIA' => 'success',
                    'BOOKING' => 'info',
                    'TERJUAL' => 'warning',
                    'LUNAS' => 'primary',
                    'HOLD' => 'gray',
                    'BATAL' => 'danger',
                    default => 'gray',
                }),
                // Enable/Disable: nonaktif = unit DI-HOLD internal → hilang dari siteplan,
                // tak bisa dipilih, tak dihitung stok. Toggle di sini langsung tersimpan;
                // aktifkan lagi → kembali seperti semula.
                Tables\Columns\ToggleColumn::make('is_active')->label('Aktif')
                    ->onColor('success')->offColor('danger')->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif')
                    ->placeholder('Semua')->trueLabel('Aktif')->falseLabel('Nonaktif (hold internal)'),
                Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Proyek'),
                Tables\Filters\SelectFilter::make('status')->options([
                    'TERSEDIA' => 'Tersedia', 'BOOKING' => 'Booking', 'TERJUAL' => 'Terjual',
                    'LUNAS' => 'Lunas', 'HOLD' => 'Hold', 'BATAL' => 'Batal',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('nonaktifkan')->label('Nonaktifkan (Hold)')
                        ->icon('heroicon-o-eye-slash')->color('danger')->requiresConfirmation()
                        ->modalDescription('Unit terpilih akan di-HOLD internal: hilang dari siteplan, tak bisa dipilih, tak dihitung stok.')
                        ->action(fn ($records) => $records->each(fn ($u) => $u->update(['is_active' => false])))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('aktifkan')->label('Aktifkan')
                        ->icon('heroicon-o-eye')->color('success')
                        ->action(fn ($records) => $records->each(fn ($u) => $u->update(['is_active' => true])))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('blok')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnits::route('/'),
            'create' => Pages\CreateUnit::route('/create'),
            'edit' => Pages\EditUnit::route('/{record}/edit'),
        ];
    }
}
