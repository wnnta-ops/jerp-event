<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Services\TtsToBkmService;
use App\Modules\FO\Komisi\Models\Agent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\ClosingNupService;
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
    #[On('bukaKwitansi')]
    public function bukaKwitansi(int $ttsId): void
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

            return;
        }

        try {
            $bkm = $tts->bkm_id ? Bkm::find($tts->bkm_id) : TtsToBkmService::convert($tts);
            if (! $bkm) {
                throw new \RuntimeException('Kwitansi gagal dibuat.');
            }

            Notification::make()->success()
                ->title('Kwitansi '.$bkm->no_bkm.' siap')
                ->body($tts->bkm_id ? 'Kwitansi untuk TTS ini sudah pernah dibuka.' : 'TTS '.$tts->no_tts.' berhasil dibuka menjadi kwitansi.')
                ->actions([
                    NotificationAction::make('cetak_kwitansi')->label('Cetak Kwitansi')->button()
                        ->url(route('fo.cetak.kwitansi', $bkm), shouldOpenInNewTab: true),
                ])
                ->persistent()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Gagal buka kwitansi')->body($e->getMessage())->send();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                NupRegistration::query()
                    ->where('status', 'PILIH_UNIT')
                    ->whereNotNull('reservasi_id')
                    ->whereHas('reservasi', fn ($q) => $q->whereDoesntHave('kontrak'))
                    ->with(['customer', 'reservasi.unit.unitType'])
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
                            ->default('KPR')->required(),
                        Forms\Components\Select::make('agent_id')
                            ->label('Sales')
                            // Hanya agent level Sales (sembunyikan Koordinator & Kantor).
                            ->options(fn () => Agent::query()->where('is_active', true)->salesPicker()->orderBy('name')->get()
                                ->mapWithKeys(fn (Agent $a) => [$a->id => ($a->code ? $a->code.' — ' : '').$a->name]))
                            ->searchable()->placeholder('— pilih sales —'),
                    ])
                    ->action(function (NupRegistration $r, array $data) {
                        try {
                            $result = app(ClosingNupService::class)->close($r, [
                                'skema_id' => (int) $data['skema_id'],
                                'agent_id' => $data['agent_id'] ?? null,
                                'cara_bayar' => $data['cara_bayar'] ?? 'KPR',
                                'tgl_kontrak' => $data['tgl_kontrak'] ?? now()->toDateString(),
                            ]);
                            Notification::make()
                                ->success()
                                ->title('Closing berhasil — Kontrak '.$result['kontrak']->no_kontrak)
                                ->body('TTS '.$result['tts']->no_tts.' dibuat. Kwitansi bisa dibuka langsung dari sini.')
                                ->actions([
                                    // Buka kwitansi TANPA pindah ke Finance AR → TTS.
                                    NotificationAction::make('buka_kwitansi')->label('Buka Kwitansi')->button()
                                        ->dispatch('bukaKwitansi', [$result['tts']->id]),
                                    NotificationAction::make('spr')->label('Cetak SPR')
                                        ->url(route('fo.cetak.spr', $result['kontrak']), shouldOpenInNewTab: true),
                                    NotificationAction::make('jadwal')->label('Jadwal Pembayaran')
                                        ->url(route('fo.cetak.jadwal', $result['kontrak']), shouldOpenInNewTab: true),
                                ])
                                ->persistent()
                                ->send();
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
