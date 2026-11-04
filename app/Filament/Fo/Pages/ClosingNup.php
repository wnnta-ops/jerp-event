<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Services\TtsToBkmService;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\ClosingNupService;
use App\Modules\FO\MarketingSales\Services\PaketGimmickService;
use App\Support\Rupiah;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * "Closing NUP" — naik kontrak. List NUP yang sudah Pilih Unit (punya reservasi
 * yang belum naik kontrak); operator pilih skema cara bayar + sales → buat
 * Kontrak + TTS (UTJ). Kwitansi dibuka dari TTS (menu Finance) atau tombol di sini.
 */
class ClosingNup extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-check-circle';

    protected static ?string $title = 'Closing NUP';

    protected static ?string $slug = 'closing-nup';

    protected static string $view = 'filament.fo.pages.closing-nup';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Buka Kuitansi (TTS → BKM) langsung dari Closing NUP, lalu tawarkan Cetak
     * Kwitansi — operator hari-H tak perlu berpindah ke Finance AR → TTS.
     * Idempoten: TTS yang sudah jadi BKM hanya menampilkan tombol cetaknya.
     */
    /**
     * Hasil closing terakhir — isi jendela "Closing Berhasil" (kwitansi, SPR, jadwal).
     *
     * @var array<string,mixed>|null
     */
    #[Locked]
    public ?array $hasil = null;

    /** Tombol "Buka Kwitansi" di jendela hasil closing. */
    public function bukaKwitansiHasil(): void
    {
        if (! $this->hasil || $this->hasil['bkm_id']) {
            return;
        }
        $bkm = $this->konversiTts((int) $this->hasil['tts_id']);
        if ($bkm) {
            $this->hasil['bkm_id'] = $bkm->id;
            $this->hasil['no_bkm'] = $bkm->no_bkm;
        }
    }

    /**
     * TTS → BKM dengan batasan keamanan (lihat bukaKwitansi). Null bila gagal —
     * notifikasi error sudah dikirim.
     */
    private function konversiTts(int $ttsId): ?Bkm
    {
        // KEAMANAN: metode Livewire publik bisa dipanggil langsung dari browser
        // dengan id sembarang. Tanpa batasan, siapa pun yang membuka halaman ini
        // dapat memaksa TTS APA PUN menjadi kwitansi (BKM + jurnal ikut terbentuk).
        // Karena itu hanya TTS milik alur Closing NUP yang boleh: jenis BOOKING,
        // terikat kontrak, dan belum dibatalkan. Batas tenant sudah otomatis dari
        // global scope TenantAwareModel.
        $tts = Tts::query()
            ->whereKey($ttsId)
            ->where('jenis_pembayaran', 'BOOKING')
            ->whereNotNull('kontrak_id')
            ->where('status', '!=', 'BATAL')
            ->first();

        if (! $tts) {
            Notification::make()->danger()->title('TTS tidak ditemukan')
                ->body('Kwitansi hanya bisa dibuka untuk TTS booking dari Closing NUP.')->send();

            return null;
        }

        try {
            $bkm = $tts->bkm_id ? Bkm::find($tts->bkm_id) : TtsToBkmService::convert($tts);
            if (! $bkm) {
                throw new \RuntimeException('Kwitansi gagal dibuat.');
            }

            return $bkm;
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Gagal buka kwitansi')->body($e->getMessage())->send();

            return null;
        }
    }

    #[On('bukaKwitansi')]
    public function bukaKwitansi(int $ttsId): void
    {
        $bkm = $this->konversiTts($ttsId);
        if (! $bkm) {
            return;
        }

        Notification::make()->success()
            ->title('Kwitansi '.$bkm->no_bkm.' siap')
            ->actions([
                NotificationAction::make('cetak_kwitansi')->label('Cetak Kwitansi')->button()
                    ->url(route('fo.cetak.kwitansi', $bkm), shouldOpenInNewTab: true),
            ])
            ->persistent()
            ->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                NupRegistration::query()
                    ->where('status', 'PILIH_UNIT')
                    ->whereNotNull('reservasi_id')
                    ->whereHas('reservasi', fn ($q) => $q->whereDoesntHave('kontrak'))
                    ->with(['customer', 'agent', 'reservasi.unit.unitType'])
            )
            ->defaultSort('updated_at', 'desc')
            ->paginated([10, 25, 50])
            ->columns([
                Tables\Columns\TextColumn::make('no_nup')->label('NUP')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')
                    ->label('Customer')
                    ->formatStateUsing(fn ($state, NupRegistration $r) => $r->customer?->full_name ?: $r->nama_pendaftar)
                    ->description(fn (NupRegistration $r) => $r->customer?->code ?: null)
                    ->searchable()->wrap(),
                Tables\Columns\TextColumn::make('reservasi.unit.kode_unit')
                    ->label('Unit')
                    ->formatStateUsing(fn ($state, NupRegistration $r) => $r->reservasi?->unit?->kode_unit ?: $r->reservasi?->unit?->label)
                    ->description(fn (NupRegistration $r) => $r->reservasi?->unit?->unitType?->name)
                    ->searchable(),
                Tables\Columns\TextColumn::make('reservasi.harga_jual')->label('Harga')->money('IDR')->alignEnd(),
                Tables\Columns\TextColumn::make('reservasi.no_reservasi')->label('Reservasi')->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('closing')
                    ->label('Proses Closing')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->modalHeading(fn (NupRegistration $r) => 'Closing NUP '.$r->no_nup.' — '.($r->customer?->full_name ?: $r->nama_pendaftar))
                    ->modalSubmitActionLabel('Buat Kontrak')
                    ->form([
                        // Biodata yang DIISI SAAT MENDAFTAR NUP, ditampilkan apa adanya supaya
                        // operator bisa mencocokkannya dengan berkas customer sebelum kontrak
                        // terbentuk. Sekali kontrak jadi, nomor & nama ikut tercetak di SPR —
                        // mencocokkan sesudahnya berarti membatalkan dan mengulang.
                        Forms\Components\Section::make('Data Customer (dari form NUP)')
                            ->columns(2)
                            ->collapsible()
                            ->schema([
                                Forms\Components\Placeholder::make('c_nama')->label('Nama Pendaftar')
                                    ->content(fn (NupRegistration $r) => $r->customer?->full_name ?: ($r->nama_pendaftar ?: '—')),
                                Forms\Components\Placeholder::make('c_kode')->label('Kode Customer')
                                    ->content(fn (NupRegistration $r) => $r->customer?->code ?: '—'),
                                Forms\Components\Placeholder::make('c_nik')->label('NIK')
                                    ->content(fn (NupRegistration $r) => $r->nik ?: ($r->customer?->nik ?: '—')),
                                Forms\Components\Placeholder::make('c_hp')->label('No. HP')
                                    ->content(fn (NupRegistration $r) => trim(($r->phone ?: '—').($r->secondary_phone ? ' / '.$r->secondary_phone : ''))),
                                Forms\Components\Placeholder::make('c_email')->label('Email')
                                    ->content(fn (NupRegistration $r) => $r->email ?: '—'),
                                Forms\Components\Placeholder::make('c_nup')->label('NUP')
                                    ->content(fn (NupRegistration $r) => $r->no_nup.($r->tgl_daftar ? ' · '.$r->tgl_daftar->format('d M Y') : '')),
                                Forms\Components\Placeholder::make('c_unit')->label('Unit')
                                    ->content(fn (NupRegistration $r) => $r->reservasi?->unit?->kode_unit ?: ($r->reservasi?->unit?->label ?: '—')),
                                Forms\Components\Placeholder::make('c_harga')->label('Harga Reservasi')
                                    ->content(fn (NupRegistration $r) => $r->reservasi?->harga_jual
                                        ? 'Rp '.number_format((float) $r->reservasi->harga_jual, 0, ',', '.') : '—'),
                            ]),

                        Forms\Components\DatePicker::make('tgl_kontrak')->label('Tanggal Kontrak')->default(now())->required(),
                        Forms\Components\Select::make('skema_id')
                            ->label('Skema Cara Bayar')
                            ->options(fn (NupRegistration $r) => Skema::query()
                                ->where('is_active', true)
                                ->when($r->reservasi?->unit?->project_id, fn ($q, $pid) => $q->where('project_id', $pid))
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Skema $s) => [$s->id => $s->code.' — '.$s->name]))
                            ->searchable()->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                $s = $state ? Skema::find($state) : null;
                                if ($s) {
                                    $set('cara_bayar', match ($s->cara_bayar) {
                                        'CASH' => 'CASH', 'CASH_BERTAHAP', 'CICILAN' => 'CASH_BERTAHAP', default => 'KPR',
                                    });
                                }
                            }),
                        Forms\Components\Select::make('cara_bayar')
                            ->label('Cara Bayar')
                            ->options(['KPR' => 'KPR', 'CASH_BERTAHAP' => 'Cash bertahap', 'CASH' => 'Cash keras'])
                            ->default('KPR')->required()->live(),
                        // Sales TIDAK bisa dipilih di sini: yang berhak adalah sales yang
                        // mendaftarkan NUP-nya, dan itu sudah tercatat sejak form NUP. Dulu ini
                        // dropdown bebas, sehingga closing bisa memindahkan komisi ke sales lain
                        // tanpa jejak — dan 293 dari 304 NUP memang sudah membawa sales-nya.
                        // NUP tanpa sales (mis. pendaftaran langsung) memang kosong; perbaikannya
                        // di NUP-nya, bukan dengan menebak di sini.
                        Forms\Components\Placeholder::make('sales_nup')
                            ->label('Sales')
                            ->content(fn (NupRegistration $r) => $r->agent
                                ? trim(($r->agent->code ? $r->agent->code.' — ' : '').$r->agent->name)
                                : ($r->ref_sales_name ?: '— tidak ada sales pada NUP ini —')),

                        // Paket Gimmick (Tipe Gimmick) — semua item tercentang, yang tidak
                        // didapat customer tinggal dilepas. Yang tercentang tercetak di SPR.
                        Forms\Components\Section::make('Gimmick')
                            ->collapsible()
                            ->schema([
                                Forms\Components\Select::make('tipe_gimmick_id')->label('Paket Gimmick')
                                    ->options(fn (NupRegistration $r) => app(PaketGimmickService::class)->opsiPaket($r->reservasi?->unit))
                                    ->searchable()->placeholder('— Tanpa gimmick')
                                    ->live()
                                    ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('gimmick_ids',
                                        array_map('strval', array_keys(app(PaketGimmickService::class)->itemPaket($state ? (int) $state : null))))),
                                Forms\Components\CheckboxList::make('gimmick_ids')->label('Item yang didapat customer')
                                    ->options(fn (Forms\Get $get) => app(PaketGimmickService::class)->itemPaket($get('tipe_gimmick_id') ? (int) $get('tipe_gimmick_id') : null))
                                    ->visible(fn (Forms\Get $get) => filled($get('tipe_gimmick_id')))
                                    ->bulkToggleable()->columns(2),
                            ]),

                        // Tambah DP — sama dengan tombol "Tambah DP" di Edit SPR: tiap baris
                        // jadi tagihan TAMBAH DP & Pencairan KPR otomatis berkurang.
                        Forms\Components\Section::make('Tambah DP (opsional)')
                            ->description('DP tambahan dari customer. Pencairan KPR otomatis berkurang sebesar nominalnya, total tetap = harga unit.')
                            ->visible(fn (Forms\Get $get) => $get('cara_bayar') === 'KPR')
                            ->collapsible()
                            ->schema([
                                Forms\Components\Repeater::make('tambah_dp')->label('')
                                    ->defaultItems(0)->addActionLabel('Tambah DP')
                                    ->columns(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('nominal')->label('Nominal Tambah DP')->numeric()->rupiah()->required(),
                                        Forms\Components\DatePicker::make('jatuh_tempo')->label('Jatuh Tempo')->default(now())->required(),
                                    ]),
                            ]),
                    ])
                    ->action(function (NupRegistration $r, array $data, ClosingNup $livewire) {
                        try {
                            $caraBayar = $data['cara_bayar'] ?? 'KPR';
                            $result = app(ClosingNupService::class)->close($r, [
                                'skema_id' => (int) $data['skema_id'],
                                // Dari NUP-nya, bukan dari form — bidangnya memang tidak bisa diisi.
                                'agent_id' => $r->agent_id,
                                'cara_bayar' => $caraBayar,
                                'tgl_kontrak' => $data['tgl_kontrak'] ?? now()->toDateString(),
                                'gimmick_ids' => filled($data['tipe_gimmick_id'] ?? null) ? ($data['gimmick_ids'] ?? []) : [],
                                'tambah_dp' => $caraBayar === 'KPR'
                                    ? collect($data['tambah_dp'] ?? [])->map(fn ($row) => [
                                        'nominal' => (float) Rupiah::toFloat($row['nominal'] ?? 0),
                                        'jatuh_tempo' => $row['jatuh_tempo'] ?? null,
                                    ])->values()->all()
                                    : [],
                            ]);

                            // Jendela "Closing Berhasil": kwitansi, SPR & jadwal di satu tempat —
                            // dulu hanya toast kecil di pojok yang mudah terlewat.
                            $k = $result['kontrak'];
                            $livewire->hasil = [
                                'kontrak_id' => $k->id,
                                'no_kontrak' => $k->no_kontrak,
                                'tts_id' => $result['tts']->id,
                                'no_tts' => $result['tts']->no_tts,
                                'bkm_id' => null,
                                'no_bkm' => null,
                                'customer' => $r->customer?->full_name ?: $r->nama_pendaftar,
                                'unit' => $k->unit?->kode_unit ?: $k->unit?->label,
                                'gimmick' => $result['gimmick'] ?? 0,
                                'tambah_dp' => $result['tambah_dp'] ?? 0,
                            ];
                            $livewire->dispatch('open-modal', id: 'hasil-closing');
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal closing')->body($e->getMessage())->send();
                        }
                    }),
            ])
            ->emptyStateHeading('Belum ada NUP siap closing')
            ->emptyStateDescription('Hanya NUP yang sudah Pilih Unit (punya reservasi & belum naik kontrak) yang tampil di sini.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
