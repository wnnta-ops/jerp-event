<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\SkemaBltResource\Pages;
use App\Modules\FO\Adminjual\Models\SalesLevel;
use App\Modules\FO\Komisi\Models\SkemaBlt;
use App\Modules\FO\MarketingSales\Models\UnitType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SkemaBltResource extends Resource
{
    protected static ?string $model = SkemaBlt::class;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Setup Komisi';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'skema-blt';

    protected static ?string $modelLabel = 'Skema BLT';

    protected static ?string $pluralModelLabel = 'Skema BLT';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Rumus Global')->columns(2)->schema([
                Forms\Components\TextInput::make('kode')->label('Kode')->default('#AUTO#')->disabled()->dehydrated(false),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                Forms\Components\TextInput::make('nama')->label('Nama')->required()->maxLength(255)->columnSpanFull(),
                Forms\Components\Select::make('project_id')->label('Project')->relationship('project', 'name')->searchable()->preload(),
                Forms\Components\Select::make('sales_type_id')->label('Tipe Sales')->relationship('salesType', 'name')->searchable()->preload()->live()->placeholder('Semua'),
                Forms\Components\Select::make('unit_type_id')->label('Tipe Unit')
                    ->options(fn () => UnitType::query()->orderBy('name')->pluck('name', 'id'))->searchable()->placeholder('Semua'),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\DatePicker::make('periode_dari')->label('Periode BLT dari')->native(false)->displayFormat('d M Y'),
                    Forms\Components\DatePicker::make('periode_sampai')->label('sampai')->native(false)->displayFormat('d M Y'),
                ]),
                Forms\Components\Radio::make('rumus')->label('Rumus Komisi')->inline()
                    ->options(['SATUAN_PER_UNIT' => 'Satuan per Unit', 'TARGET_KUMULATIF' => 'Target Kumulatif'])
                    ->default('SATUAN_PER_UNIT')->required(),
                Forms\Components\Radio::make('dasar_hitung')->label('Dasar Perhitungan')->inline()
                    ->options(['DPP' => 'DPP', 'NILAI_KONTRAK' => 'Nilai Kontrak'])->default('DPP')->required(),
            ]),

            Forms\Components\Section::make('Rumus BLT')->schema([
                Forms\Components\Repeater::make('lines')->relationship()->label('')
                    ->columns(4)->defaultItems(1)->addActionLabel('Tambah Baris')->reorderable(false)
                    ->schema([
                        Forms\Components\Select::make('sales_level_id')->label('Level')
                            ->options(function (Forms\Get $get) {
                                $st = $get('../../sales_type_id');

                                return SalesLevel::query()->where('is_active', true)
                                    ->when($st, fn ($q) => $q->where('sales_type_id', $st))
                                    ->orderBy('name')->pluck('name', 'id');
                            })->searchable()->required()->helperText('Dari master Level Sales.'),
                        Forms\Components\Radio::make('tipe_tarif')->label('Tipe Tarif')->inline()
                            ->options(['PERSEN' => '%', 'RP' => 'Rp'])->default('RP')->required()->live(),
                        Forms\Components\TextInput::make('tarif')->label('Tarif Komisi')->rupiahPersen()->required()->default(0),
                        Forms\Components\Radio::make('potong_komisi')->label('Potong Komisi')->inline()
                            ->options([0 => 'Tidak', 1 => 'Ya'])->default(0)->boolean(),
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
            Tables\Columns\TextColumn::make('unitType.name')->label('Tipe Unit')->placeholder('Semua')->toggleable(),
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
            'index' => Pages\ListSkemaBlts::route('/'),
            'create' => Pages\CreateSkemaBlt::route('/create'),
            'edit' => Pages\EditSkemaBlt::route('/{record}/edit'),
        ];
    }
}
