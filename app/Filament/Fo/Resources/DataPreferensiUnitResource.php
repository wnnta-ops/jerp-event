<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\DataPreferensiUnitResource\Pages;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Models\PreferensiUnit;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data Preferensi Unit — tampungan preferensi yang diisi pendaftar NUP:
 * nama customer, telepon, dan preferensi unit yang dipilih. Read-only; datanya
 * lahir dari form pendaftaran publik, bukan diinput manual di sini.
 */
class DataPreferensiUnitResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = NupRegistration::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Launching & NUP';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'data-preferensi-unit';

    protected static ?string $navigationLabel = 'Data Preferensi Unit';

    protected static ?string $modelLabel = 'Data Preferensi Unit';

    protected static ?string $pluralModelLabel = 'Data Preferensi Unit';

    // Hanya pendaftaran NUP (Booked Manual punya alurnya sendiri).
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('jenis', 'NUP');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('customer'))
            ->columns([
                Tables\Columns\TextColumn::make('nama_pendaftar')->label('Nama Customer')
                    ->searchable()->sortable()->weight('bold'),
                // Pendaftaran hasil migrasi tidak mengisi kolom phone; nomornya ada di
                // data customer. Ambil dari sana sebagai cadangan agar kolom tak kosong.
                Tables\Columns\TextColumn::make('phone')->label('Telepon')
                    ->getStateUsing(fn (NupRegistration $r) => $r->phone ?: ($r->customer?->mobile ?: $r->customer?->phone))
                    ->placeholder('—')->copyable()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(
                        fn (Builder $w) => $w->where('fo_nup_registrations.phone', 'like', "%{$search}%")
                            ->orWhereHas('customer', fn (Builder $c) => $c->where('mobile', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%"))
                    )),
                // Pendaftaran lama memakai kolom multi-pilih pilihan_budget; tampilkan
                // apa adanya supaya data lama tetap terbaca tanpa diubah surut. Data
                // hasil migrasi memang tak punya keduanya → tampil kosong.
                Tables\Columns\TextColumn::make('preferensi_unit_nama')->label('Preferensi Unit')
                    ->badge()->color('warning')->placeholder('— belum ada')->searchable()
                    ->getStateUsing(fn (NupRegistration $r) => $r->preferensi_unit_nama
                        ?: (filled($r->pilihan_budget) ? implode(', ', (array) $r->pilihan_budget) : null)),
                Tables\Columns\TextColumn::make('tgl_daftar')->label('Tgl Daftar')
                    ->dateTime('d/m/Y H:i')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('projectPref.name')->label('Project')
                    ->badge()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('tgl_daftar', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('preferensi_unit_id')->label('Preferensi Unit')
                    ->options(fn () => PreferensiUnit::query()->orderBy('urutan')->pluck('nama', 'id')),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function canCreate(): bool
    {
        return false;   // hanya tampungan — data masuk dari form pendaftaran publik
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDataPreferensiUnit::route('/'),
        ];
    }
}
