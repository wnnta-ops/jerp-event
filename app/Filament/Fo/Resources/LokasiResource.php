<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\LokasiResource\Pages;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Setup Unit → master Lokasi (per Project). Kode jadi segmen pertama No. Unit.
 */
class LokasiResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Lokasi::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationGroup = 'Setup Unit';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Lokasi';

    protected static ?string $modelLabel = 'Lokasi';

    protected static ?string $pluralModelLabel = 'Setup Unit — Lokasi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Lokasi')->columns(2)->schema([
                Forms\Components\Select::make('project_id')->label('Project')
                    ->relationship('project', 'name')->required()->searchable()->preload(),
                Forms\Components\TextInput::make('kode')->label('Kode Lokasi')->required()->maxLength(32)
                    ->placeholder('mis. JRK3')
                    // Unik per project (mirror unique DB tenant_id+project_id+kode) →
                    // pesan validasi ramah, bukan crash 1062. Catatan: Lokasi ≠ Tahap;
                    // tahap dibuat di menu Tahap, bukan dgn menduplikasi lokasi.
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Forms\Get $get) => $rule->where('project_id', $get('project_id')))
                    ->validationMessages(['unique' => 'Kode Lokasi ini sudah ada untuk project tsb. Untuk menambah fase, pakai menu Tahap.'])
                    ->helperText('Dipakai sebagai segmen pertama No. Unit (Lokasi-Tahap-Blok-Nomer). Satu kode per cluster.'),
                Forms\Components\TextInput::make('nama')->label('Nama Lokasi')->required()->maxLength(255)
                    // Operator perlu tahu bahwa layar ini punya pembaca kedua: kalau tidak, ia
                    // akan mencari-cari tempat menambah cluster di Back Office — yang memang
                    // tidak ada, karena BO sepenuhnya mengikuti daftar ini.
                    ->helperText('Kode & nama ini yang muncul sebagai pilihan Cluster di Back Office (Setup Kategori Gimmick). Perubahannya menyusul otomatis.'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')->label('Project')->badge()->sortable()->searchable(),
                Tables\Columns\TextColumn::make('kode')->label('Kode')->badge()->color('info')->sortable()->searchable()->copyable(),
                Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('tahap_count')->counts('tahap')->label('Tahap')->alignEnd(),
                Tables\Columns\TextColumn::make('units_count')->counts('units')->label('Unit')->alignEnd(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Project'),
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])])
            ->defaultSort('kode');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLokasi::route('/'),
            'create' => Pages\CreateLokasi::route('/create'),
            'edit' => Pages\EditLokasi::route('/{record}/edit'),
        ];
    }
}
