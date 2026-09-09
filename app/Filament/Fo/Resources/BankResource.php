<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\BankResource\Pages;
use App\Modules\FO\Launching\Models\Bank;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Setup Bank — kelola daftar bank KPR per Cluster + Tahap (tambah/ubah/hapus).
 * Sumber dropdown "Bank Pilihan" di form pendaftaran NUP publik; dropdown itu
 * hanya muncul saat pendaftar memilih Cara Bayar = KPR.
 */
class BankResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Bank::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Launching & NUP';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'setup-bank';

    protected static ?string $navigationLabel = 'Setup Bank';

    protected static ?string $modelLabel = 'Bank';

    protected static ?string $pluralModelLabel = 'Setup Bank — Bank KPR';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Bank KPR')->columns(2)->schema([
                Forms\Components\Select::make('project_id')->label('Project')
                    ->relationship('project', 'name')->required()->searchable()->preload()
                    ->live()->afterStateUpdated(function (callable $set): void {
                        $set('lokasi_id', null);
                        $set('tahap_id', null);
                    }),

                // Cluster + Tahap menentukan di Jenis Tipe NUP mana bank ini muncul.
                // Dikosongkan → bank berlaku untuk seluruh project.
                Forms\Components\Select::make('lokasi_id')->label('Cluster')
                    ->options(fn (Get $get) => $get('project_id')
                        ? Lokasi::query()->where('project_id', $get('project_id'))->where('is_active', true)
                            ->orderBy('kode')->pluck('nama', 'id')
                        : [])
                    ->searchable()->preload()->live()
                    ->afterStateUpdated(fn (callable $set) => $set('tahap_id', null))
                    ->placeholder('Semua cluster di project ini')
                    ->helperText('Kosongkan bila bank berlaku untuk seluruh project.'),

                Forms\Components\Select::make('tahap_id')->label('Tahap')
                    ->options(fn (Get $get) => $get('lokasi_id')
                        ? Tahap::query()->where('lokasi_id', $get('lokasi_id'))->where('is_active', true)
                            ->orderBy('kode')->pluck('nama', 'id')
                        : [])
                    ->searchable()->preload()
                    ->placeholder('Semua tahap di cluster ini')
                    ->helperText('Kosongkan bila bank berlaku untuk semua tahap.'),

                Forms\Components\TextInput::make('nama')->label('Nama Bank')->required()->maxLength(100)
                    ->placeholder('mis. BTN / BCA / Mandiri'),
                Forms\Components\TextInput::make('urutan')->label('Urutan')->numeric()->default(0)
                    ->helperText('Angka kecil tampil lebih dulu di dropdown.'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
                Forms\Components\TextInput::make('keterangan')->label('Keterangan')->maxLength(255)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')->label('Project')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('lokasi.nama')->label('Cluster')->placeholder('Semua')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tahap.nama')->label('Tahap')->placeholder('Semua')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('nama')->label('Nama Bank')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('urutan')->label('Urutan')->alignEnd()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('keterangan')->label('Keterangan')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Project'),
                Tables\Filters\SelectFilter::make('lokasi_id')->relationship('lokasi', 'nama')->label('Cluster'),
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Tambah Bank'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
            ])
            ->defaultSort('urutan');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBanks::route('/'),
        ];
    }
}
