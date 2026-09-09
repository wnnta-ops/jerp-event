<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\SkemaResource\Pages;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Tahap;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class SkemaResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Skema::class;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Master Jual';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Skema Bayar';

    protected static ?string $pluralModelLabel = 'Skema Pembayaran';

    /** Skema dipakai di Kontrak (SPRS/SPR)? (jadi tak boleh dihapus). */
    public static function usedByKontrak(Skema $skema): bool
    {
        return Kontrak::query()->where('skema_id', $skema->id)->exists();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Header Skema')->columns(3)->schema([
                Forms\Components\TextInput::make('code')->label('Kode')->required()->maxLength(32)->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255),
                Forms\Components\Select::make('project_id')->label('Proyek')->relationship('project', 'name')->searchable()->preload(),
                Forms\Components\Select::make('lokasi_id')->label('Lokasi')
                    ->relationship('lokasi', 'nama')->searchable()->preload()->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('tahap_id', null))
                    ->helperText('Ikat skema ke lokasi tertentu (opsional — kosong = berlaku umum).'),
                Forms\Components\Select::make('tahap_id')->label('Tahap')
                    ->options(fn (Forms\Get $get) => Tahap::query()
                        ->when($get('lokasi_id'), fn ($q, $id) => $q->where('lokasi_id', $id))
                        ->orderBy('kode')->pluck('nama', 'id'))
                    ->searchable()
                    ->helperText('Tahap dalam lokasi tsb (opsional).'),
                Forms\Components\Select::make('cara_bayar')->label('Jenis')->options([
                    'KPR' => 'KPR', 'CASH_BERTAHAP' => 'Cash bertahap', 'CASH_KERAS' => 'Cash keras',
                ])->default('KPR')->required(),
                Forms\Components\Select::make('bf_dipotong_di')->label('Booking Fee dipotong di')->options([
                    'ANGSURAN1' => 'FREE DP', 'DP_DISEBAR' => 'DP 3x', 'ANGSURAN_DISEBAR' => 'CASH / INHOUSE',
                ])->default('ANGSURAN1'),
                Forms\Components\TextInput::make('present_value')->label('Present Value')->numeric()->rupiah()->default(0),
                Forms\Components\TextInput::make('diskon_persen')->label('Diskon %')->numeric()->suffix('%')->default(0),
                Forms\Components\TextInput::make('subsidi_persen')->label('Subsidi developer %')->numeric()->suffix('%')->default(0)
                    ->helperText('Default 0 — diisi admin per skema. Mode DP Disebar: free X% dari DP.'),
                Forms\Components\TextInput::make('dp_persen')->label('DP total %')->numeric()->suffix('%')->default(0),
                Forms\Components\TextInput::make('ang_persen')->label('Angsuran total %')->numeric()->suffix('%')->default(0),
                Forms\Components\TextInput::make('bf_nominal')->label('Booking Fee (UTJ)')->numeric()->rupiah()->default(0),
                Forms\Components\TextInput::make('tenor_bulan')->label('Tenor (bulan)')->numeric(),
                Forms\Components\TextInput::make('jumlah_termin')->label('Jumlah termin')->numeric()->default(1)->required(),
                Forms\Components\Toggle::make('is_active')->label('Status aktif')->default(true),
                Forms\Components\Toggle::make('tampil_siteplan')->label('Tampil di Site Plan')->default(true)
                    ->helperText('Jika aktif, harga skema ini muncul di popup siteplan publik.'),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Termin Pembayaran')->schema([
                Forms\Components\Repeater::make('lines')->label('Termin')
                    ->relationship()
                    ->columns(5)
                    ->orderColumn('urutan')
                    ->defaultItems(0)
                    ->schema([
                        Forms\Components\TextInput::make('urutan')->label('No')->numeric()->required(),
                        Forms\Components\Select::make('tipe')->label('Tipe')->options([
                            'BF' => 'BF', 'DP' => 'DP', 'ANG' => 'Angsuran', 'ADM' => 'Adm',
                        ])->default('DP')->required(),
                        Forms\Components\TextInput::make('nama_termin')->label('Nama')->required()->columnSpan(2),
                        Forms\Components\Select::make('basis')->label('Nilai')->options(['PERSEN' => '% (persen)', 'NOMINAL' => 'F (nominal)'])
                            ->default('PERSEN')->required()->live(),
                        Forms\Components\TextInput::make('persen')->label('%')->numeric()->suffix('%')
                            ->visible(fn (Forms\Get $get) => $get('basis') === 'PERSEN'),
                        Forms\Components\TextInput::make('nominal')->label('Nominal')->numeric()->rupiah()
                            ->visible(fn (Forms\Get $get) => $get('basis') === 'NOMINAL'),
                        Forms\Components\Select::make('jadwal_basis')->label('Jadwal')
                            ->options(['BULAN' => 'Bulan', 'HARI' => 'Hari', 'TANGGAL' => 'Tanggal'])->default('BULAN'),
                        Forms\Components\TextInput::make('jadwal_nilai')->label('Jadwal nilai')->numeric()->default(0),
                        Forms\Components\TextInput::make('setelah')->label('Setelah termin')->numeric()->default(0),
                        Forms\Components\Toggle::make('flag_bf')->label('BF')->inline(false),
                        Forms\Components\Toggle::make('flag_kpr')->label('KPR')->inline(false),
                        Forms\Components\Textarea::make('keterangan')->rows(1)->columnSpanFull(),
                    ])
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => ($state['tipe'] ?? '').' · '.($state['nama_termin'] ?? 'Termin').' — '.($state['persen'] ?? $state['nominal'] ?? '')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('Kode')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
            Tables\Columns\TextColumn::make('project.name')->label('Proyek')->toggleable(),
            Tables\Columns\TextColumn::make('lokasi.nama')->label('Lokasi')->placeholder('Umum')->toggleable(),
            Tables\Columns\TextColumn::make('tahap.nama')->label('Tahap')->placeholder('—')->toggleable(),
            Tables\Columns\TextColumn::make('cara_bayar')->label('Cara')->badge(),
            Tables\Columns\TextColumn::make('dp_persen')->label('DP %')->numeric(2)->suffix('%')->alignEnd(),
            Tables\Columns\TextColumn::make('tenor_bulan')->label('Tenor')->numeric()->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('jumlah_termin')->label('# Termin')->numeric()->alignEnd(),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('cara_bayar')->label('Jenis')->options([
                'KPR' => 'KPR', 'CASH_BERTAHAP' => 'Cash bertahap', 'CASH_KERAS' => 'Cash keras',
            ]),
            Tables\Filters\TernaryFilter::make('is_active'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make()
                ->modalDescription('Menghapus skema ini juga menghapus harga Price List & termin master untuk skema tsb. Lanjutkan?')
                ->before(function (Skema $record, Tables\Actions\DeleteAction $action) {
                    if (static::usedByKontrak($record)) {
                        Notification::make()->danger()
                            ->title('Tidak bisa dihapus')
                            ->body('Skema "'.$record->code.'" sudah dipakai di kontrak (SPRS/SPR). Ganti/batalkan skema di kontrak terkait dulu.')
                            ->send();
                        $action->cancel();
                    }
                }),
        ])->bulkActions([
            // Aktif/nonaktif massal: menonaktifkan skema lama (mis. hasil migrasi
            // "OLD …") sebelumnya harus satu per satu lewat Ubah — melelahkan untuk
            // puluhan baris. Skema nonaktif otomatis hilang dari pilihan (Closing NUP,
            // Booked, Price List), sedangkan kontrak yang sudah memakainya TIDAK
            // terpengaruh. Ditaruh di luar grup agar langsung terlihat saat mencentang.
            Tables\Actions\BulkAction::make('nonaktifkan')
                ->label('Nonaktifkan')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Nonaktifkan skema terpilih?')
                ->modalDescription('Skema nonaktif tidak lagi muncul di pilihan (Closing NUP, Booked, Price List). Kontrak yang sudah memakainya tetap utuh.')
                ->action(function (Collection $records) {
                    $n = Skema::whereKey($records->pluck('id'))->update(['is_active' => false]);
                    Notification::make()->success()->title($n.' skema dinonaktifkan')->send();
                })
                ->deselectRecordsAfterCompletion(),

            Tables\Actions\BulkAction::make('aktifkan')
                ->label('Aktifkan')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Aktifkan skema terpilih?')
                ->action(function (Collection $records) {
                    $n = Skema::whereKey($records->pluck('id'))->update(['is_active' => true]);
                    Notification::make()->success()->title($n.' skema diaktifkan')->send();
                })
                ->deselectRecordsAfterCompletion(),

            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make()
                    ->before(function (Collection $records, Tables\Actions\DeleteBulkAction $action) {
                        $used = $records->filter(fn (Skema $r) => static::usedByKontrak($r));
                        if ($used->isNotEmpty()) {
                            Notification::make()->danger()
                                ->title('Sebagian skema dipakai kontrak')
                                ->body('Tidak dihapus: '.$used->pluck('code')->implode(', ').'. Sudah dipakai di kontrak (SPRS/SPR).')
                                ->send();
                            $action->cancel();
                        }
                    }),
            ]),
        ])->defaultSort('code');
    }

    public static function getPages(): array
    {
        // Pembuatan skema lewat Wizard (App\Filament\Fo\Pages\SkemaWizard) — bukan
        // grid manual. Halaman ini hanya list + edit (fine-tune termin).
        return [
            'index' => Pages\ListSkemas::route('/'),
            'edit' => Pages\EditSkema::route('/{record}/edit'),
        ];
    }
}
