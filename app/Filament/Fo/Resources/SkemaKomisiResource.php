<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\SkemaKomisiResource\Pages;
use App\Modules\FO\Adminjual\Models\SalesLevel;
use App\Modules\FO\Komisi\Models\SkemaKomisi;
use App\Modules\FO\Komisi\Models\TipeTarif;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SkemaKomisiResource extends Resource
{
    protected static ?string $model = SkemaKomisi::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Setup Komisi';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'skema-komisi';

    protected static ?string $modelLabel = 'Skema Komisi';

    protected static ?string $pluralModelLabel = 'Skema Komisi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Skema Komisi')->columns(2)->schema([
                Forms\Components\TextInput::make('kode')->label('Kode')
                    ->default('#AUTO#')->disabled()->dehydrated(false),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                Forms\Components\TextInput::make('nama')->label('Nama')->required()->maxLength(255)->columnSpanFull(),
                Forms\Components\DatePicker::make('periode_dari')->label('Periode dari')->native(false)->displayFormat('d M Y'),
                Forms\Components\DatePicker::make('periode_sampai')->label('sampai')->native(false)->displayFormat('d M Y'),
                Forms\Components\Select::make('project_id')->label('Project')
                    ->relationship('project', 'name')->searchable()->preload()->live()
                    ->afterStateUpdated(function (Forms\Set $set) {
                        $set('lokasi_id', null);
                        $set('tahap_id', null);
                    }),
                Forms\Components\Select::make('sales_type_id')->label('Tipe Sales')
                    ->relationship('salesType', 'name')->searchable()->preload()->placeholder('Semua'),
                Forms\Components\Select::make('lokasi_id')->label('Lokasi (Cluster)')
                    ->options(fn (Forms\Get $get) => $get('project_id')
                        ? Lokasi::where('project_id', $get('project_id'))->orderBy('kode')->pluck('nama', 'id')
                        : [])
                    ->searchable()->live()->placeholder('Semua lokasi')
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('tahap_id', null)),
                Forms\Components\Select::make('tahap_id')->label('Tahap')
                    ->options(fn (Forms\Get $get) => $get('lokasi_id')
                        ? Tahap::where('lokasi_id', $get('lokasi_id'))->orderBy('kode')->pluck('nama', 'id')
                        : [])
                    ->searchable()->placeholder('Semua tahap'),
                Forms\Components\Radio::make('rumus')->label('Rumus Komisi')->inline()
                    ->options([
                        'SATUAN_PER_UNIT' => 'Satuan Per Unit',
                        'TARGET_KUMULATIF' => 'Target Kumulatif',
                        'PROGRESIF' => 'Progresif',
                    ])->default('SATUAN_PER_UNIT')->required(),
                Forms\Components\Radio::make('dasar_hitung')->label('Dasar Perhitungan')->inline()
                    ->options(['DPP' => 'DPP', 'NILAI_KONTRAK' => 'Nilai Kontrak'])
                    ->default('DPP')->required(),
            ]),

            Forms\Components\Section::make('Rumus Komisi (per Level)')->schema([
                Forms\Components\Repeater::make('lines')->relationship()->label('')
                    ->columns(3)->defaultItems(1)->addActionLabel('Tambah Baris')
                    ->schema([
                        Forms\Components\Select::make('sales_level_id')->label('Level')
                            ->options(function (Forms\Get $get) {
                                $st = $get('../../sales_type_id');

                                return SalesLevel::query()->where('is_active', true)
                                    ->when($st, fn ($q) => $q->where('sales_type_id', $st))
                                    ->orderBy('name')->pluck('name', 'id');
                            })
                            ->searchable()->required()->helperText('Dari master Level Sales.'),
                        Forms\Components\Radio::make('tipe_tarif')->label('Tipe Tarif')->inline()
                            ->options(['PERSEN' => '%', 'RP' => 'Rp'])->default('PERSEN')->required(),
                        Forms\Components\TextInput::make('tarif')->label('Tarif Komisi')->numeric()->required()->default(0),
                    ]),
            ]),

            Forms\Components\Section::make('Biaya-biaya (dipotong dari basis)')
                ->description('Pilih Tipe Tarif (PPH/BPHTB) yang mengurangi basis sebelum komisi dihitung. Kelola daftarnya di menu Setup Tarif.')
                ->schema([
                    Forms\Components\Repeater::make('biaya')->relationship()->label('')
                        ->defaultItems(0)->addActionLabel('Tambah Biaya')
                        ->schema([
                            Forms\Components\Select::make('tipe_tarif_id')->label('Tipe Biaya')
                                ->options(fn () => TipeTarif::where('is_active', true)->pluck('nama', 'id'))
                                ->searchable()->required(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('kode')->label('Kode')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable()->sortable()->wrap(),
            Tables\Columns\TextColumn::make('project.name')->label('Project')->placeholder('Semua')->toggleable(),
            Tables\Columns\TextColumn::make('salesType.name')->label('Tipe Sales')->placeholder('Semua')->toggleable(),
            Tables\Columns\TextColumn::make('lokasi.kode')->label('Lokasi')->placeholder('Semua')->toggleable(),
            Tables\Columns\TextColumn::make('rumus')->label('Rumus')->badge()->toggleable(),
            Tables\Columns\TextColumn::make('lines_count')->counts('lines')->label('# Baris')->alignEnd()->toggleable(),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Project'),
            Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('kode');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSkemaKomisis::route('/'),
            'create' => Pages\CreateSkemaKomisi::route('/create'),
            'edit' => Pages\EditSkemaKomisi::route('/{record}/edit'),
        ];
    }
}
