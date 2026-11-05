<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\PilihUnitSetupResource\Pages;
use App\Modules\FO\Launching\Models\PilihUnitSetup;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Setup Pilih Unit per cluster — giliran HP (aktif & jumlah HP) dan batas waktu memilih unit.
 * Lanjut / Ulang giliran ditekan admin di halaman Pilih Unit. Cluster tanpa setup: tanpa
 * giliran, waktu 2 menit.
 */
class PilihUnitSetupResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = PilihUnitSetup::class;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationGroup = 'Launching & NUP';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Setup Pilih Unit';

    protected static ?string $modelLabel = 'Setup Pilih Unit';

    protected static ?string $pluralModelLabel = 'Setup Pilih Unit — Giliran HP & Waktu';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Cluster')->schema([
                Forms\Components\Select::make('lokasi_id')->label('Cluster')
                    ->options(fn () => Lokasi::query()->with('project:id,name')->where('is_active', true)
                        ->orderBy('project_id')->orderBy('kode')->get()
                        ->mapWithKeys(fn (Lokasi $l) => [$l->id => ($l->project?->name ? $l->project->name.' — ' : '').$l->nama]))
                    ->required()->searchable()
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'Cluster ini sudah punya setup — ubah setup yang ada.']),
            ]),
            Forms\Components\Section::make('Giliran HP')->columns(2)->schema([
                Forms\Components\Toggle::make('giliran_aktif')->label('Giliran HP aktif')->default(false)->live()->inline(false)
                    ->helperText('Aktif: HP memilih unit bergantian HP 1 → 2 → … → HP terakhir → kembali ke HP 1.'),
                Forms\Components\TextInput::make('jumlah_hp')->label('Jumlah HP')
                    ->numeric()->integer()->minValue(1)->maxValue(50)->default(5)->required()
                    ->visible(fn (Get $get) => (bool) $get('giliran_aktif'))
                    ->helperText('Tambah/kurangi sesuai HP yang dipakai saat launching.'),
            ]),
            Forms\Components\Section::make('Waktu Memilih')->columns(2)->schema([
                Forms\Components\Toggle::make('timer_aktif')->label('Batas waktu aktif')->default(true)->live()->inline(false)
                    ->helperText('Nonaktif: tanpa hitung mundur, customer memilih tanpa batas waktu.'),
                Forms\Components\TextInput::make('timer_menit')->label('Lama (menit)')
                    ->numeric()->integer()->minValue(1)->maxValue(60)->default(2)->required()
                    ->visible(fn (Get $get) => (bool) $get('timer_aktif')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('lokasi.project.name')->label('Project')->badge(),
                Tables\Columns\TextColumn::make('lokasi.nama')->label('Cluster')->weight('bold'),
                Tables\Columns\IconColumn::make('giliran_aktif')->label('Giliran')->boolean(),
                Tables\Columns\TextColumn::make('jumlah_hp')->label('Jumlah HP')
                    ->state(fn (PilihUnitSetup $r) => $r->giliran_aktif ? $r->jumlah_hp.' HP' : '—'),
                Tables\Columns\TextColumn::make('timer_menit')->label('Waktu Memilih')
                    ->state(fn (PilihUnitSetup $r) => $r->timer_aktif ? $r->timer_menit.' menit' : 'Tanpa batas'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('lokasi_id');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPilihUnitSetup::route('/'),
            'create' => Pages\CreatePilihUnitSetup::route('/create'),
            'edit' => Pages\EditPilihUnitSetup::route('/{record}/edit'),
        ];
    }
}
