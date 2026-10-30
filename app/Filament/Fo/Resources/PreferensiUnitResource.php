<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\PreferensiUnitResource\Pages;
use App\Modules\FO\Launching\Models\PreferensiUnit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Setup Preferensi Unit — kelola pilihan "Preferensi Unit" pada form pendaftaran
 * NUP publik (tambah/ubah/hapus). Pendaftar memilih SATU saja; rekapnya ada di
 * menu NUP → Data Preferensi Unit.
 */
class PreferensiUnitResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = PreferensiUnit::class;

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $navigationGroup = 'Launching & NUP';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'setup-preferensi-unit';

    protected static ?string $navigationLabel = 'Setup Preferensi Unit';

    protected static ?string $modelLabel = 'Preferensi Unit';

    protected static ?string $pluralModelLabel = 'Setup Preferensi Unit';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('nama')->label('Nama Preferensi')->required()->maxLength(100)
                // Unik per tenant (indeks DB tenant_id+nama) — tampilkan pesan, bukan 500.
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('tenant_id', app(\App\Support\TenantContext::class)->id()))
                ->validationMessages(['unique' => 'Nama preferensi ini sudah dipakai.'])
                ->placeholder('mis. Rumah 400 Jutaan / RUKO')
                ->helperText('Tampil sebagai pilihan di form pendaftaran NUP.'),
            Forms\Components\TextInput::make('urutan')->label('Urutan')->numeric()->default(0)
                ->helperText('Angka kecil tampil lebih dulu.'),
            Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true)->inline(false),
            Forms\Components\TextInput::make('keterangan')->label('Keterangan')->maxLength(255)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('urutan')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('nama')->label('Nama Preferensi')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('keterangan')->label('Keterangan')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('urutan')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Tambah Preferensi'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPreferensiUnit::route('/'),
        ];
    }
}
