<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
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
    use MematuhiIzin;

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
            // Kode diisikan otomatis SAAT MENGETIK nama supaya nilainya kelihatan dan
            // ikut divalidasi. Sebelumnya kode dibiarkan kosong lalu dibentuk model saat
            // menyimpan, sehingga bentrok unique(tenant_id, kode) baru meledak di
            // database sebagai 500 — bukan pesan yang bisa dibaca operator.
            Forms\Components\TextInput::make('nama')->label('Nama')->required()->maxLength(100)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Forms\Set $set, Forms\Get $get, ?string $state): void {
                    if (blank($get('kode'))) {
                        $set('kode', SumberData::kodeDari($state));
                    }
                })
                ->helperText('Tampil di dropdown Sumber Data pada form pendaftaran NUP/Booked.'),
            Forms\Components\TextInput::make('kode')->label('Kode')->maxLength(32)
                ->placeholder('Otomatis dari nama')
                // ignoreRecord: saat Ubah, kode milik baris itu sendiri tidak dianggap bentrok.
                // Baris terhapus (soft delete) TETAP memakai kodenya di indeks unik —
                // karena itu pesannya menyebut kemungkinan tersebut.
                ->unique(ignoreRecord: true)
                ->validationMessages([
                    'unique' => 'Kode ini sudah dipakai sumber data lain (mungkin yang sudah dihapus). Pakai kode berbeda.',
                ])
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
            // HAPUS PERMANEN, bukan soft delete. Baris yang di-soft-delete tetap
            // menempati unique(tenant_id, kode) sehingga membuat ulang nama yang sama
            // ditolak database — operator menghapus semua isi, layar kosong, tapi
            // "Instagram" tetap tak bisa ditambahkan lagi tanpa penjelasan apa pun.
            // Aman: fo_customers.sumber_data menyimpan KODE berupa teks, bukan kunci
            // asing, jadi pendaftaran lama tetap terbaca walau masternya dihapus.
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Sumber data ini dihapus permanen. Pendaftaran lama tidak terpengaruh — nilainya sudah tersimpan sebagai teks.')
                    ->action(fn (SumberData $record) => $record->forceDelete()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(fn ($records) => $records->each->forceDelete()),
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
