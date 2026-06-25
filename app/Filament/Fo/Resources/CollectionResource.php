<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Pages\TerbitkanSp;
use App\Filament\Fo\Resources\CollectionResource\Pages;
use App\Modules\FO\Collection\Models\CollectionNotice;
use App\Modules\FO\Collection\Models\SpLetter;
use App\Modules\FO\Collection\Services\CollectionService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use App\Settings\CollectionSettings;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar Collection — penagihan AR untuk kontrak yang sudah jadi SPR. Menampilkan
 * sisa AR, jatuh tempo terdekat, status/level SP, serta aksi kirim surat
 * (reminder / SP I-III via Email & WA) dan riwayat. KPR → diteruskan ke BankLoan.
 */
class CollectionResource extends Resource
{
    protected static ?string $model = Kontrak::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $slug = 'collection';

    protected static ?string $modelLabel = 'Collection';

    protected static ?string $pluralModelLabel = 'Surat SP';

    protected static bool $shouldRegisterNavigation = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Hanya kontrak yang sudah SPR — di situlah proses collection dimulai.
        return parent::getEloquentQuery()
            ->where('tahap', 'SPR')
            ->with(['customer', 'unit', 'tagihan', 'skema']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable()
                    ->description(fn (Kontrak $r) => $r->customer?->mobile ?: $r->customer?->phone),
                Tables\Columns\TextColumn::make('unit')->label('Unit')
                    ->state(fn (Kontrak $r) => $r->unit?->kode_unit ?: '—'),
                // Judul kolom tetap "Cara bayar", tapi isinya nama Skema Bayar kontrak.
                // Nama kolom virtual (bukan 'skema') agar tak bentrok dgn relasi skema().
                Tables\Columns\TextColumn::make('skema_bayar')->label('Cara bayar')->badge()
                    ->state(fn (Kontrak $r) => $r->skema?->name ?: ($r->cara_bayar ?: '—'))
                    ->color(fn (Kontrak $r) => strtoupper((string) $r->cara_bayar) === 'KPR' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('sisa_ar')->label('Sisa AR')->money('IDR')->alignEnd()
                    ->state(fn (Kontrak $r) => self::sisaAr($r)),
                Tables\Columns\TextColumn::make('jt_terdekat')->label('JT Terdekat')
                    ->state(fn (Kontrak $r) => optional(self::nearest($r)?->jatuh_tempo)->format('d M Y') ?? '—'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Kontrak $r) => self::statusLabel($r))
                    ->color(fn (string $state) => match (true) {
                        str_starts_with($state, 'SP') => 'danger',
                        $state === 'Telat' => 'warning',
                        $state === 'Lunas' => 'success',
                        $state === 'Jatuh tempo' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('cara_bayar')->label('Cara Bayar')->options([
                    'KPR' => 'KPR', 'CASH' => 'Cash', 'KKB' => 'KKB',
                ]),
            ])
            ->recordUrl(fn (Kontrak $r) => TerbitkanSp::getUrl(['kontrak' => $r->id]))
            ->actions([
                // Terbitkan + cetak Surat Peringatan (buka halaman detail penerbitan).
                Tables\Actions\Action::make('terbitkan_sp')->label('Terbitkan SP')->icon('heroicon-o-document-text')->color('primary')
                    ->url(fn (Kontrak $r) => TerbitkanSp::getUrl(['kontrak' => $r->id])),

                // Kirim surat manual (reminder / SP) via Email/WA untuk tagihan terpilih.
                Tables\Actions\Action::make('kirim')->label('Kirim Email/WA')->icon('heroicon-o-paper-airplane')->color('gray')
                    ->modalHeading('Kirim Surat Penagihan')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Select::make('kontrak_tagihan_id')->label('Tagihan')->required()
                            ->options(fn (Kontrak $r) => app(CollectionService::class)->arTagihan($r)
                                ->mapWithKeys(fn (KontrakTagihan $t) => [$t->id => $t->nama_tagihan
                                    .' · '.(optional($t->jatuh_tempo)->format('d M Y') ?? '—')
                                    .' · sisa Rp '.number_format((float) $t->nilai - (float) $t->pelunasan, 0, ',', '.')])->all())
                            ->helperText('Hanya tagihan yang belum lunas yang tampil.'),
                        Forms\Components\Select::make('jenis')->label('Jenis Surat')->required()
                            ->options([
                                'REMINDER' => 'Reminder (sebelum jatuh tempo)',
                                'SP1' => 'Surat Peringatan I',
                                'SP2' => 'Surat Peringatan II',
                                'SP3' => 'Surat Peringatan III',
                            ]),
                        Forms\Components\CheckboxList::make('channels')->label('Kirim via')->required()
                            ->options(['EMAIL' => 'Email', 'WA' => 'WhatsApp'])
                            ->default(fn () => self::defaultChannels())
                            ->columns(2),
                    ])
                    ->action(function (Kontrak $r, array $data) {
                        $t = KontrakTagihan::find($data['kontrak_tagihan_id']);
                        $hasil = app(CollectionService::class)->kirim($r, $t, $data['jenis'], $data['channels'] ?: null);
                        $ok = collect($hasil)->where('status', 'TERKIRIM')->count();
                        $gagal = collect($hasil)->where('status', 'GAGAL')->count();
                        Notification::make()
                            ->title("Surat diproses — {$ok} terkirim".($gagal ? ", {$gagal} gagal" : ''))
                            ->status($gagal && ! $ok ? 'danger' : 'success')
                            ->body($gagal ? 'Cek email/no. WA customer atau konfigurasi gateway.' : null)
                            ->send();
                    }),

                // Riwayat surat yang sudah dikirim.
                Tables\Actions\Action::make('riwayat')->label('Riwayat')->icon('heroicon-o-clock')->color('gray')
                    ->modalHeading(fn (Kontrak $r) => 'Riwayat Surat — '.$r->no_kontrak)
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (Kontrak $r) => view('filament.fo.collection.riwayat', [
                        'spLetters' => SpLetter::where('kontrak_id', $r->id)->orderByDesc('tgl_surat')->orderByDesc('id')->get(),
                        'notices' => CollectionNotice::where('kontrak_id', $r->id)->latest('dikirim_pada')->get(),
                    ])),

                // KPR → proses lanjut di modul BankLoan (data sudah di-mirror saat kontrak dibuat).
                Tables\Actions\Action::make('proses_kpr')->label('Proses KPR')->icon('heroicon-o-building-library')->color('info')
                    ->url(fn () => url('/bankloan'), shouldOpenInNewTab: true)
                    ->visible(fn (Kontrak $r) => strtoupper((string) $r->cara_bayar) === 'KPR'),
            ])
            ->defaultSort('tgl_kontrak', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCollections::route('/'),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Tagihan AR (selain GMC) yang belum lunas. */
    public static function arRows(Kontrak $k): \Illuminate\Support\Collection
    {
        return $k->tagihan
            ->where('tipe', '!=', 'GMC')
            ->filter(fn (KontrakTagihan $t) => (float) $t->nilai - (float) $t->pelunasan > 0.5)
            ->sortBy('jatuh_tempo')
            ->values();
    }

    public static function sisaAr(Kontrak $k): float
    {
        return (float) self::arRows($k)->sum(fn (KontrakTagihan $t) => (float) $t->nilai - (float) $t->pelunasan);
    }

    public static function nearest(Kontrak $k): ?KontrakTagihan
    {
        return self::arRows($k)->first();
    }

    public static function statusLabel(Kontrak $k): string
    {
        if (app(CollectionService::class)->levelSekarang($k)) {
            return str_replace(['SP1', 'SP2', 'SP3'], ['SP I', 'SP II', 'SP III'], (string) app(CollectionService::class)->levelSekarang($k));
        }

        $t = self::nearest($k);
        if (! $t) {
            return 'Lunas';
        }
        $telat = app(CollectionService::class)->hariTelat($t);
        if ($telat > 0) {
            return 'Telat';
        }

        return $t->jatuh_tempo && $t->jatuh_tempo->isToday() ? 'Jatuh tempo' : 'Belum JT';
    }

    /** @return array<int,string> */
    public static function defaultChannels(): array
    {
        $s = app(CollectionSettings::class);
        $c = [];
        if ($s->channel_email) {
            $c[] = 'EMAIL';
        }
        if ($s->channel_wa) {
            $c[] = 'WA';
        }

        return $c;
    }
}
