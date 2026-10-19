<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\LogPesanResource\Pages;
use App\Modules\FO\Komunikasi\Jobs\KirimKwitansiBayarJob;
use App\Modules\FO\Komunikasi\Models\LogPesan;
use App\Modules\FO\Komunikasi\Support\JenisPesan;
use App\Modules\FO\Launching\Jobs\KirimKonfirmasiNupJob;
use App\Modules\FO\Launching\Jobs\KirimKwitansiUtjJob;
use App\Support\Akses;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pengaturan → Monitoring Pesan: setiap email & WA yang dikirim aplikasi beserta statusnya
 * (TERKIRIM / GAGAL / DILEWATI) dan alasannya. Yang gagal bisa dikirim ulang dari sini.
 */
class LogPesanResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = LogPesan::class;

    protected static ?string $slug = 'monitoring-pesan';

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?string $modelLabel = 'Pesan';

    protected static ?string $pluralModelLabel = 'Monitoring Pesan';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Kirim ulang memakai izin "ubah" Monitoring Pesan. */
    public static function bolehKirimUlang(): bool
    {
        return Akses::boleh(Akses::izinUntuk(static::class, 'update'));
    }

    public static function warnaStatus(?string $s): string
    {
        return match ($s) {
            LogPesan::TERKIRIM => 'success',
            LogPesan::GAGAL => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('channel')->label('Kanal')->badge()
                    ->color(fn ($state) => $state === 'WA' ? 'success' : 'info'),
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->formatStateUsing(fn ($state) => JenisPesan::label($state))->wrap(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn ($state) => self::warnaStatus($state)),
                Tables\Columns\TextColumn::make('penerima_nama')->label('Penerima')->searchable()->description(fn (LogPesan $r) => $r->tujuan),
                Tables\Columns\TextColumn::make('ref_no')->label('Dokumen')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('alasan')->label('Alasan')->limit(60)->tooltip(fn (LogPesan $r) => $r->alasan)->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('percobaan')->label('Ke-')->alignCenter()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('tujuan')->label('Tujuan')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')->label('Kanal')->options(['EMAIL' => 'Email', 'WA' => 'WhatsApp']),
                Tables\Filters\SelectFilter::make('jenis')->label('Jenis')->options(
                    JenisPesan::pilihan() + collect(['REMINDER', 'SP1', 'SP2', 'SP3'])->mapWithKeys(fn ($j) => [$j => JenisPesan::label($j)])->all()
                ),
                Tables\Filters\Filter::make('tanggal')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('dari')->label('Dari'),
                        \Filament\Forms\Components\DatePicker::make('sampai')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['dari'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
                        ->when($data['sampai'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Detail'),
                Tables\Actions\Action::make('kirimUlang')->label('Kirim ulang')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (LogPesan $r) => self::bisaKirimUlang($r) && self::bolehKirimUlang())
                    ->requiresConfirmation()
                    ->modalDescription(fn (LogPesan $r) => 'Kirim ulang '.JenisPesan::label($r->jenis).' via '.$r->channel.' ke '.($r->penerima_nama ?: '-').'? Data tujuan diambil ulang dari data customer terbaru.')
                    ->action(function (LogPesan $r) {
                        self::kirimUlang($r);
                        Notification::make()->success()->title('Dijadwalkan kirim ulang')->body('Hasilnya muncul sebagai baris baru di daftar ini.')->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('kirimUlangMassal')->label('Kirim ulang terpilih')->icon('heroicon-o-arrow-path')
                    ->visible(fn () => self::bolehKirimUlang())
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $n = 0;
                        foreach ($records as $r) {
                            if (self::bisaKirimUlang($r)) {
                                self::kirimUlang($r);
                                $n++;
                            }
                        }
                        Notification::make()->success()->title($n.' pesan dijadwalkan kirim ulang')
                            ->body($records->count() - $n > 0 ? ($records->count() - $n).' dilewati (sudah terkirim / surat SP — kirim ulang dari Collection).' : null)->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('30s');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(3)->schema([
                Infolists\Components\TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                Infolists\Components\TextEntry::make('channel')->label('Kanal')->badge(),
                Infolists\Components\TextEntry::make('status')->label('Status')->badge()->color(fn ($state) => self::warnaStatus($state)),
                Infolists\Components\TextEntry::make('jenis')->label('Jenis')->formatStateUsing(fn ($state) => JenisPesan::label($state)),
                Infolists\Components\TextEntry::make('penerima_nama')->label('Penerima')->placeholder('—'),
                Infolists\Components\TextEntry::make('tujuan')->label('Tujuan')->placeholder('—')->copyable(),
                Infolists\Components\TextEntry::make('ref_no')->label('Dokumen')->placeholder('—'),
                Infolists\Components\TextEntry::make('subjek')->label('Subjek email')->placeholder('—'),
                Infolists\Components\TextEntry::make('wa_template_id')->label('ID template WA')->placeholder('—'),
                Infolists\Components\TextEntry::make('percobaan')->label('Percobaan ke-'),
                Infolists\Components\TextEntry::make('kirim_ulang_dari')->label('Kirim ulang dari log #')->placeholder('—'),
                Infolists\Components\TextEntry::make('alasan')->label('Alasan')->placeholder('—')->columnSpanFull(),
                Infolists\Components\TextEntry::make('isian_wa')->label('Isian WA')->columnSpanFull()
                    ->state(fn (LogPesan $record) => implode(' · ', array_map(
                        fn ($v, $i) => '{{'.($i + 1).'}} '.$v,
                        array_values((array) ($record->payload['params'] ?? [])),
                        array_keys(array_values((array) ($record->payload['params'] ?? []))),
                    )) ?: '—')
                    ->visible(fn (LogPesan $r) => $r->channel === 'WA'),
            ]),
        ]);
    }

    /** Hanya jenis yang dikirim job sendiri; surat SP/reminder dikirim ulang dari Collection. */
    public static function bisaKirimUlang(LogPesan $r): bool
    {
        return $r->status !== LogPesan::TERKIRIM && $r->ref_id
            && in_array($r->jenis, array_keys(JenisPesan::pilihan()), true);
    }

    public static function kirimUlang(LogPesan $r): void
    {
        $args = [(int) $r->ref_id, $r->channel, $r->id, (int) $r->percobaan + 1];

        match ($r->jenis) {
            JenisPesan::TAGIHAN_UTJ, JenisPesan::KONFIRMASI_NUP => KirimKonfirmasiNupJob::dispatch(...$args),
            JenisPesan::KWITANSI_UTJ => KirimKwitansiUtjJob::dispatch(...$args),
            JenisPesan::KWITANSI_BAYAR => KirimKwitansiBayarJob::dispatch(...$args),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLogPesan::route('/'),
            'view' => Pages\ViewLogPesan::route('/{record}'),
        ];
    }
}
