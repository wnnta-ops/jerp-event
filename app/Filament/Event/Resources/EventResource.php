<?php

namespace App\Filament\Event\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Event\Resources\EventResource\Pages;
use App\Models\User;
use App\Modules\Event\Models\EtEvent;
use App\Modules\Event\Policies\EventPolicy;
use App\Modules\Event\Services\EventSetupService;
use App\Modules\Event\Services\FoNupLookup;
use App\Modules\Event\Services\SyncCheckInService;
use Filament\Forms;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Daftar event + wizard setup (khusus superadmin).
 *
 * Izin menu/rute lewat MematuhiIzin (config/akses.php → 'event.event'), jadi
 * sakelar akses.tegakkan berlaku seperti resource lain. Membuat/mengubah event
 * DITAMBAH syarat superadmin (akses.peran_super) — brief: setup hanya superadmin,
 * dan itu tidak boleh terbuka hanya karena mode lapor.
 */
class EventResource extends Resource
{
    use MematuhiIzin {
        canCreate as protected izinCreate;
        canEdit as protected izinEdit;
        canDelete as protected izinDelete;
        canDeleteAny as protected izinDeleteAny;
    }

    protected static ?string $model = EtEvent::class;

    protected static ?string $slug = 'events';

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Daftar Event';

    protected static ?string $modelLabel = 'event';

    protected static ?string $pluralModelLabel = 'event';

    protected static ?int $navigationSort = 1;

    private static function policy(): EventPolicy
    {
        return app(EventPolicy::class);
    }

    private static function super(): bool
    {
        return self::policy()->canManage(Auth::user());
    }

    public static function canCreate(): bool
    {
        return static::izinCreate() && self::super();
    }

    public static function canEdit(Model $record): bool
    {
        return static::izinEdit($record) && self::super();
    }

    public static function canDelete(Model $record): bool
    {
        return static::izinDelete($record) && self::super() && $record->status === EtEvent::DRAFT;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Superadmin melihat semua event; lainnya hanya event yang ada di et_access-nya. */
    public static function getEloquentQuery(): Builder
    {
        return self::policy()->scopeVisible(parent::getEloquentQuery(), Auth::user());
    }

    // ── Wizard ──────────────────────────────────────────────────────────

    /** @return array<int, Step> */
    public static function wizardSteps(): array
    {
        $kunci = fn (?EtEvent $record) => $record !== null && $record->status !== EtEvent::DRAFT;

        return [
            Step::make('Detail')
                ->icon('heroicon-o-information-circle')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nama event')->required()->maxLength(255)
                        ->placeholder('mis. Pilih Unit Launching Cluster Jasmine'),
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\DatePicker::make('event_date')->label('Tanggal')->required()->native(false)->displayFormat('d M Y'),
                        Forms\Components\TextInput::make('venue')->label('Lokasi / venue')->maxLength(255),
                    ]),
                    Forms\Components\Radio::make('jenis')
                        ->label('Jenis event')
                        ->options([
                            EventSetupService::JENIS_FO => 'Terhubung ke event NUP (FO)',
                            EventSetupService::JENIS_MANDIRI => 'Mandiri — tanpa event NUP',
                        ])
                        ->descriptions([
                            EventSetupService::JENIS_FO => 'Pos 1 = Aktivasi NUP di FO (dibaca otomatis). Dilacak per NUP; customer dengan 2 NUP antre 2 kali.',
                            EventSetupService::JENIS_MANDIRI => 'Semua pos di-scan di sini. Peserta dikenali dari QR customer FO (atau ketik kode customer / No. NUP), dilacak per customer, tercatat saat scan pertama.',
                        ])
                        ->default(EventSetupService::JENIS_FO)
                        ->required()
                        ->live()
                        ->disabled($kunci),
                    Forms\Components\Select::make('fo_nup_event_id')
                        ->label('Event NUP (FO)')
                        ->helperText('Peserta diambil dari NUP event ini yang sudah Aktivasi di FO.')
                        ->visible(fn (Get $get) => $get('jenis') !== EventSetupService::JENIS_MANDIRI)
                        ->options(fn () => app(FoNupLookup::class)->nupEvents()
                            ->mapWithKeys(fn ($e) => [$e['id'] => self::labelNupEvent($e)])->all())
                        ->searchable()
                        ->required()
                        ->disabled($kunci),
                ]),

            Step::make('Pos')
                ->icon('heroicon-o-map-pin')
                ->description('Urutan pos yang dilalui customer')
                ->schema([
                    Forms\Components\Placeholder::make('pos_fo')
                        ->label('Pos 1')
                        ->content('Aktivasi NUP (FO) — dibaca otomatis dari FO, tidak perlu scan di modul ini.')
                        ->visible(fn (Get $get) => $get('jenis') !== EventSetupService::JENIS_MANDIRI),
                    Forms\Components\Placeholder::make('pos_mandiri')
                        ->hiddenLabel()
                        ->content('Event mandiri: scan pertama customer di pos mana pun mencatat kedatangannya. Biasanya pos pertama = meja kedatangan.')
                        ->visible(fn (Get $get) => $get('jenis') === EventSetupService::JENIS_MANDIRI),
                    Forms\Components\Repeater::make('pos')
                        ->label(fn (Get $get) => $get('jenis') === EventSetupService::JENIS_MANDIRI ? 'Pos (urut)' : 'Pos berikutnya (urut)')
                        ->helperText('Pos terakhir otomatis menjadi pos Selesai. Seret untuk mengubah urutan.')
                        ->schema([
                            Forms\Components\Grid::make(3)->schema([
                                Forms\Components\TextInput::make('code')->label('Kode')->required()->maxLength(30)
                                    ->placeholder('KONS')
                                    ->dehydrateStateUsing(fn ($state) => strtoupper(trim((string) $state)))
                                    ->notIn(['FO', 'fo']),
                                Forms\Components\TextInput::make('name')->label('Nama pos')->required()->maxLength(255)
                                    ->placeholder('Konsultasi'),
                                Forms\Components\TextInput::make('target_minutes')->label('Target (menit)')
                                    ->numeric()->minValue(1)->maxValue(600)
                                    ->helperText('Kartu live board berubah warna bila lewat target.'),
                            ]),
                        ])
                        ->default([
                            ['code' => 'KONS', 'name' => 'Konsultasi', 'target_minutes' => 15],
                            ['code' => 'PU', 'name' => 'Pilih Unit', 'target_minutes' => 20],
                        ])
                        ->minItems(1)
                        ->reorderable()
                        ->addActionLabel('Tambah pos')
                        ->itemLabel(fn (array $state) => trim(($state['code'] ?? '').' · '.($state['name'] ?? ''), ' ·'))
                        ->disabled($kunci),
                ])
                ->afterValidation(function (Get $get, Set $set) {
                    $set('titik', app(EventSetupService::class)->titikBawaan((array) $get('pos'), (array) $get('titik')));
                }),

            Step::make('Titik scan')
                ->icon('heroicon-o-qr-code')
                ->description('Masuk + Keluar per pos (dua HP), atau satu HP')
                ->schema([
                    Forms\Components\Repeater::make('titik')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\Hidden::make('code'),
                            Forms\Components\Hidden::make('name'),
                            Forms\Components\Radio::make('mode')
                                ->label('Mode')
                                ->options([
                                    EventSetupService::MODE_GANDA => 'Masuk + Keluar (dua HP)',
                                    EventSetupService::MODE_SINGLE => 'Satu HP (scan pertama masuk, kedua keluar)',
                                ])
                                ->default(EventSetupService::MODE_GANDA)
                                ->live(),
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\TextInput::make('label_masuk')->label('Nama titik Masuk')->maxLength(255),
                                Forms\Components\TextInput::make('label_keluar')->label('Nama titik Keluar')->maxLength(255),
                            ])->visible(fn (Get $get) => $get('mode') !== EventSetupService::MODE_SINGLE),
                            Forms\Components\TextInput::make('label_single')->label('Nama titik')->maxLength(255)
                                ->visible(fn (Get $get) => $get('mode') === EventSetupService::MODE_SINGLE),
                        ])
                        ->addable(false)
                        ->deletable(false)
                        ->reorderable(false)
                        ->itemLabel(fn (array $state) => trim(($state['code'] ?? '').' · '.($state['name'] ?? ''), ' ·'))
                        ->disabled($kunci),
                ]),

            Step::make('Akses')
                ->icon('heroicon-o-users')
                ->description('Siapa scan di mana, siapa lihat live board & laporan')
                ->schema([
                    Forms\Components\Placeholder::make('akses_info')->hiddenLabel()
                        ->content('Superadmin selalu punya akses penuh. Akun scanner hanya melihat event ini dan titik yang dicentang.'),
                    Forms\Components\Repeater::make('akses')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\Select::make('user_id')
                                ->label('Pengguna')
                                ->searchable()
                                ->required()
                                ->getSearchResultsUsing(fn (string $search) => User::query()
                                    ->where('is_active', true)
                                    ->where(fn ($q) => $q->where('full_name', 'like', "%{$search}%")
                                        ->orWhere('name', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%"))
                                    ->limit(30)->get()
                                    ->mapWithKeys(fn (User $u) => [$u->id => self::labelUser($u)])->all())
                                ->getOptionLabelUsing(fn ($value) => ($u = User::find($value)) ? self::labelUser($u) : null)
                                ->distinct(),
                            Forms\Components\CheckboxList::make('titik')
                                ->label('Boleh scan di')
                                ->options(fn (Get $get) => app(EventSetupService::class)->pilihanTitik((array) $get('../../titik')))
                                ->columns(2)
                                ->bulkToggleable(),
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\Toggle::make('can_live_board')->label('Live board (koor lapangan)'),
                                Forms\Components\Toggle::make('can_reports')->label('Laporan & export'),
                            ]),
                        ])
                        ->defaultItems(0)
                        ->addActionLabel('Tambah pengguna')
                        ->itemLabel(fn (array $state) => filled($state['user_id'] ?? null) && ($u = User::find($state['user_id'])) ? self::labelUser($u) : null)
                        ->collapsible(),
                ]),

            Step::make('Tinjau')
                ->icon('heroicon-o-check-badge')
                ->schema([
                    Forms\Components\Placeholder::make('ringkasan')
                        ->hiddenLabel()
                        ->content(fn (Get $get) => self::ringkasan($get)),
                    Forms\Components\Toggle::make('buka_sekarang')
                        ->label('Buka event sekarang')
                        ->helperText('Event yang dibuka langsung menarik peserta yang sudah Aktivasi di FO, dan scanner mulai bisa dipakai.')
                        ->default(false)
                        ->visible(fn (?EtEvent $record) => $record === null || $record->status === EtEvent::DRAFT),
                ]),
        ];
    }

    // ── Tabel ───────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        $nupEvents = fn () => once(fn () => app(FoNupLookup::class)->nupEvents()->keyBy('id'));

        return $table
            ->defaultSort('event_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Event')->searchable()->weight('bold')
                    ->description(fn (EtEvent $r) => $r->venue),
                Tables\Columns\TextColumn::make('event_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('fo_nup_event_id')->label('Event NUP (FO)')
                    ->formatStateUsing(fn ($state) => ($e = $nupEvents()->get((int) $state)) ? self::labelNupEvent($e) : '#'.$state)
                    ->placeholder('Mandiri (tanpa NUP)'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        EtEvent::OPEN => 'Dibuka', EtEvent::CLOSED => 'Ditutup', default => 'Draft',
                    })
                    ->color(fn (string $state) => match ($state) {
                        EtEvent::OPEN => 'success', EtEvent::CLOSED => 'gray', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('participants_count')->label('Peserta (NUP)')->counts('participants')->alignEnd(),
                Tables\Columns\TextColumn::make('last_synced_at')->label('Sinkron FO')->since()->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    EtEvent::DRAFT => 'Draft', EtEvent::OPEN => 'Dibuka', EtEvent::CLOSED => 'Ditutup',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Ubah'),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('buka')
                        ->label('Buka event')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Scanner mulai bisa dipakai dan peserta yang sudah Aktivasi di FO ditarik.')
                        ->visible(fn (EtEvent $r) => self::super() && $r->status === EtEvent::DRAFT)
                        ->action(fn (EtEvent $r) => self::jalankan(fn () => app(EventSetupService::class)->open($r), 'Event dibuka')),
                    Tables\Actions\Action::make('sinkron')
                        ->label('Sinkron sekarang')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn (EtEvent $r) => self::super() && $r->status === EtEvent::OPEN)
                        ->action(function (EtEvent $r) {
                            self::jalankan(function () use ($r) {
                                $h = app(SyncCheckInService::class)->syncEvent($r);

                                return "Baru {$h['baru']}, diperbarui {$h['diperbarui']}, dibatalkan {$h['dibatalkan']}.";
                            }, 'Sinkron selesai');
                        }),
                    Tables\Actions\Action::make('tutup')
                        ->label('Tutup event')
                        ->icon('heroicon-o-lock-closed')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Akses scanner berakhir. Kunjungan yang masih terbuka ditutup dengan alasan "event ditutup".')
                        ->visible(fn (EtEvent $r) => self::super() && $r->status === EtEvent::OPEN)
                        ->action(fn (EtEvent $r) => self::jalankan(fn () => app(EventSetupService::class)->close($r, Auth::user()), 'Event ditutup')),
                    Tables\Actions\Action::make('duplikat')
                        ->label('Duplikat event')
                        ->icon('heroicon-o-document-duplicate')
                        ->requiresConfirmation()
                        ->modalDescription('Pos, titik scan, dan akses disalin ke event draft baru. Peserta tidak ikut.')
                        ->visible(fn () => self::super())
                        ->action(fn (EtEvent $r) => self::jalankan(fn () => app(EventSetupService::class)->duplicate($r, Auth::user()), 'Event diduplikat')),
                    Tables\Actions\DeleteAction::make()->label('Hapus draft'),
                ]),
            ])
            ->emptyStateHeading('Belum ada event')
            ->emptyStateDescription('Superadmin membuat event lewat tombol "Buat event".');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvents::route('/'),
            'create' => Pages\CreateEvent::route('/create'),
            'edit' => Pages\EditEvent::route('/{record}/edit'),
        ];
    }

    // ── bantu ───────────────────────────────────────────────────────────

    /** @param array{code:string, name:string, tgl_launching:string} $e */
    public static function labelNupEvent(array $e): string
    {
        return $e['code'].' — '.$e['name'].' ('.Carbon::parse($e['tgl_launching'])->translatedFormat('d M Y').')';
    }

    private static function labelUser(User $u): string
    {
        return ($u->full_name ?: $u->name).' · '.$u->email;
    }

    /** Jalankan aksi tabel; galat bisnis jadi notifikasi merah, bukan halaman error. */
    private static function jalankan(\Closure $aksi, string $judul): void
    {
        try {
            $hasil = $aksi();
            Notification::make()->success()->title($judul)->body(is_string($hasil) ? $hasil : null)->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Gagal')->body($e->getMessage())->send();
        }
    }

    private static function ringkasan(Get $get): HtmlString
    {
        $e = e(...);
        $pos = collect((array) $get('pos'))->values();
        $titik = collect((array) $get('titik'))->keyBy('code');
        $mandiri = $get('jenis') === EventSetupService::JENIS_MANDIRI;
        $nup = $mandiri ? null : app(FoNupLookup::class)->nupEvent((int) $get('fo_nup_event_id'));

        $baris = $mandiri ? '' : '<li>1. Aktivasi NUP (FO) — dari FO</li>';
        $awal = $mandiri ? 1 : 2;
        foreach ($pos as $i => $p) {
            $code = strtoupper((string) ($p['code'] ?? ''));
            $mode = ($titik[$code]['mode'] ?? EventSetupService::MODE_GANDA) === EventSetupService::MODE_SINGLE ? 'satu HP' : 'Masuk + Keluar';
            $selesai = $i === $pos->count() - 1 ? ' · <b>Selesai</b>' : '';
            $target = filled($p['target_minutes'] ?? null) ? ' · target '.(int) $p['target_minutes'].' mnt' : '';
            $baris .= '<li>'.($i + $awal).'. '.$e($p['name'] ?? '').' ('.$e($code).') — '.$mode.$target.$selesai.'</li>';
        }

        $akses = collect((array) $get('akses'))->filter(fn ($a) => filled($a['user_id'] ?? null))->count();

        return new HtmlString(
            '<div class="space-y-3 text-sm">'.
            '<div><b>'.$e((string) $get('name')).'</b> · '.$e((string) $get('event_date')).' · '.$e((string) $get('venue')).'</div>'.
            '<div>'.($mandiri ? 'Event mandiri — tanpa event NUP, dilacak per customer.' : 'Event NUP: '.($nup ? $e(self::labelNupEvent($nup)) : '—')).'</div>'.
            '<ol class="list-none space-y-1">'.$baris.'</ol>'.
            '<div>'.$akses.' pengguna diberi akses. Superadmin selalu punya akses penuh.</div>'.
            '</div>'
        );
    }
}
