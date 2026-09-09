<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\BerkasLengkapResource\Pages;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Berkas Lengkap (Free DP) — modul Collection menentukan Tgl Berkas Lengkap per
 * kontrak KPR FREE DP. DP yang jatuh temponya SETELAH tanggal itu menjadi FREE
 * (UANG MASUK); sebelum/saat → tetap dibayar. Kosong = berkas lengkap di awal →
 * semua DP free. Set tanggal → Jadwal Tagihan otomatis dihitung ulang.
 */
class BerkasLengkapResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Kontrak::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $slug = 'pemberkasan';

    protected static ?string $modelLabel = 'Pemberkasan';

    protected static ?string $pluralModelLabel = 'Pemberkasan';

    protected static bool $shouldRegisterNavigation = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Hanya kontrak KPR FREE DP (skema mode ANGSURAN1) — di situlah berkas
        // lengkap menentukan DP mana yang free.
        return parent::getEloquentQuery()
            ->where('cara_bayar', 'KPR')
            ->whereHas('skema', fn (Builder $q) => $q->where('bf_dipotong_di', 'ANGSURAN1'))
            ->with(['customer', 'unit', 'skema', 'tagihan']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('unit')->label('Unit')
                    ->state(fn (Kontrak $r) => $r->unit?->kode_unit ?: '—'),
                Tables\Columns\TextColumn::make('tahap')->label('Tahap')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('tgl_berkas_lengkap')->label('Tgl Berkas Lengkap')
                    ->date('d M Y')->placeholder('— berkas di awal (semua DP free)')
                    ->color(fn (Kontrak $r) => $r->tgl_berkas_lengkap ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('dp_bayar')->label('DP dibayar')->alignCenter()
                    ->state(fn (Kontrak $r) => $r->tagihan->where('tipe', 'DP')->count()),
                Tables\Columns\TextColumn::make('dp_free')->label('DP free (UANG MASUK)')->money('IDR')->alignEnd()
                    ->state(fn (Kontrak $r) => (float) $r->tagihan->where('tipe', 'ADM')
                        ->where('nama_tagihan', 'UANG MASUK')->sum('nilai')),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('tgl_berkas_lengkap')->label('Berkas Lengkap')
                    ->placeholder('Semua')->trueLabel('Sudah diisi')->falseLabel('Belum diisi')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('tgl_berkas_lengkap'),
                        false: fn (Builder $q) => $q->whereNull('tgl_berkas_lengkap'),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('setBerkas')->label('Set Tgl Berkas Lengkap')
                    ->icon('heroicon-o-calendar-days')->color('primary')
                    ->modalHeading('Set Tgl Berkas Lengkap')
                    ->modalDescription('DP yang jatuh temponya SETELAH tanggal ini menjadi FREE (UANG MASUK). Kosongkan = berkas lengkap di awal → semua DP free. Jadwal Tagihan otomatis dihitung ulang.')
                    ->fillForm(fn (Kontrak $r) => ['tgl_berkas_lengkap' => $r->tgl_berkas_lengkap])
                    ->form([
                        Forms\Components\DatePicker::make('tgl_berkas_lengkap')->label('Tgl Berkas Lengkap')
                            ->helperText('Kosongkan bila berkas lengkap sejak awal (semua DP free).'),
                    ])
                    ->action(function (Kontrak $record, array $data) {
                        $record->update(['tgl_berkas_lengkap' => $data['tgl_berkas_lengkap'] ?: null]);
                        app(KontrakGeneratorService::class)->tagihan($record);
                        Notification::make()->success()->title('Tgl Berkas Lengkap disimpan')
                            ->body('Jadwal Tagihan dihitung ulang — DP setelah berkas lengkap menjadi free.')->send();
                    }),
            ])
            ->defaultSort('tgl_kontrak', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBerkasLengkaps::route('/'),
        ];
    }
}
