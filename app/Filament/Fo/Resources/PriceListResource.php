<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\PriceListResource\Pages;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Models\UnitPricelist;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Price List = matrix harga per unit per skema bayar. Menampilkan SEMUA unit
 * dengan kolom harga per skema (CASH/KPR/INHOUSE/…). Harga dapat diedit per unit
 * lewat modal "Edit Harga" — untuk koreksi cepat tanpa re-upload Excel.
 *
 * Skema yang dicentang "Tampil di Site Plan" (di Setup Sales → Skema Bayar)
 * itulah yang muncul ke pengunjung /siteplan.
 */
class PriceListResource extends Resource
{
    protected static ?string $model = Unit::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'Price List';

    protected static ?string $pluralModelLabel = 'Price List';

    /** Skema bayar aktif — jadi kolom & field harga di editor. */
    public static function activeSchemes()
    {
        return Skema::query()
            ->where('is_active', true)
            ->orderByDesc('tampil_siteplan')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'tampil_siteplan']);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('is_active', true)
            ->with(['unitType', 'pricelists']);
    }

    public static function table(Table $table): Table
    {
        $schemes = static::activeSchemes();

        $schemeColumns = $schemes->map(function ($s) {
            return Tables\Columns\TextColumn::make('harga_skema_'.$s->id)
                ->label($s->name.($s->tampil_siteplan ? ' ★' : ''))
                ->alignEnd()
                ->state(function (Unit $record) use ($s) {
                    $pl = $record->pricelists->firstWhere('skema_id', $s->id);

                    return $pl ? (float) $pl->price_list : null;
                })
                ->formatStateUsing(fn ($state) => $state ? 'Rp '.number_format((float) $state, 0, ',', '.') : '—');
        })->all();

        return $table
            ->columns(array_merge([
                Tables\Columns\TextColumn::make('kode_unit')->label('No. Unit')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('unitType.name')->label('Tipe')->toggleable()->searchable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->toggleable(),
            ], $schemeColumns))
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Proyek'),
                Tables\Filters\SelectFilter::make('tahap')->label('Tahap')
                    ->options(fn () => Unit::query()->whereNotNull('tahap')->distinct()->orderBy('tahap')->pluck('tahap', 'tahap')->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('editHarga')
                    ->label('Edit Harga')
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading(fn (Unit $record) => 'Edit Harga — '.$record->kode_unit)
                    ->modalWidth('lg')
                    ->fillForm(function (Unit $record) use ($schemes) {
                        $data = [];
                        foreach ($schemes as $s) {
                            $pl = $record->pricelists->firstWhere('skema_id', $s->id);
                            $data['skema_'.$s->id] = $pl ? (float) $pl->price_list : null;
                        }

                        return $data;
                    })
                    ->form([
                        Forms\Components\Placeholder::make('hint')
                            ->label('')
                            ->content('Skema bertanda ★ tampil di /siteplan untuk pengunjung. Kosongkan harga bila skema tidak berlaku untuk unit ini.'),
                        Forms\Components\Grid::make(1)->schema(
                            $schemes->map(function ($s) {
                                return Forms\Components\TextInput::make('skema_'.$s->id)
                                    ->label($s->name.($s->tampil_siteplan ? '  ★ (tampil di siteplan)' : ''))
                                    ->numeric()->minValue(0)->rupiah()
                                    ->placeholder('Kosong = tidak berlaku');
                            })->all()
                        ),
                    ])
                    ->action(function (Unit $record, array $data) use ($schemes) {
                        DB::transaction(function () use ($record, $data, $schemes) {
                            $primary = null;
                            foreach ($schemes as $s) {
                                $val = $data['skema_'.$s->id] ?? null;
                                $price = ($val === null || $val === '') ? null : (float) $val;

                                if ($price === null) {
                                    // Hapus baris bila harga dikosongkan.
                                    UnitPricelist::where('unit_id', $record->id)->where('skema_id', $s->id)->delete();

                                    continue;
                                }

                                UnitPricelist::updateOrCreate(
                                    ['unit_id' => $record->id, 'skema_id' => $s->id],
                                    ['tenant_id' => $record->tenant_id, 'no_skema' => $s->code, 'price_list' => $price],
                                );

                                if ($primary === null && $s->tampil_siteplan && $price > 0) {
                                    $primary = $price;
                                }
                            }

                            if ($primary !== null) {
                                $record->update(['harga_jual' => $primary]);
                            }
                        });

                        Notification::make()
                            ->title('Harga unit '.$record->kode_unit.' tersimpan')
                            ->success()->send();
                    }),
            ])
            ->defaultSort('kode_unit')
            ->paginated([25, 50, 100, 'all']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPriceLists::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
