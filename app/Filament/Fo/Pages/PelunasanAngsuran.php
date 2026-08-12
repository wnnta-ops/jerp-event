<?php

namespace App\Filament\Fo\Pages;

use App\Models\Bank;
use App\Modules\FO\FinanceAR\Services\PembayaranService;
use App\Modules\FO\MarketingSales\Models\KontrakTagihan;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Pelunasan Angsuran & Tambah DP — daftar baris Jadwal Tagihan (ANG/TDP) yang
 * BELUM lunas dari kontrak SPR; jatuh tempo & nominal nge-link ke SPR. Finance
 * "Terima Pembayaran" → sisa berkurang + Kwitansi (BKM) terbit otomatis.
 */
class PelunasanAngsuran extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'Pelunasan DP, Angsuran & Tambah DP';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'pelunasan-angsuran';

    protected static string $view = 'filament.fo.pages.pelunasan-angsuran';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                KontrakTagihan::query()
                    ->with(['kontrak.customer', 'kontrak.unit'])
                    ->whereIn('tipe', ['DP', 'ANG', 'TDP'])
                    ->whereRaw('nilai - pelunasan > 0.5')
                    ->whereHas('kontrak', fn ($q) => $q->where('tahap', 'SPR')->where('status', '!=', 'BATAL'))
                    ->where(function ($q) {
                        // Tambah DP (TDP) SELALU tampil — sering dibayar langsung di luar urutan.
                        $q->where('tipe', 'TDP')
                            // DP/ANG: hanya jatuh tempo BERIKUTNYA — sembunyikan bila masih ada
                            // DP/ANG belum-lunas yang lebih awal (urutan kecil). Cegah salah urut.
                            ->orWhere(fn ($s) => $s->whereIn('tipe', ['DP', 'ANG'])
                                ->whereNotExists(function ($x) {
                                    $x->selectRaw('1')->from('fo_kontrak_tagihan as t2')
                                        ->whereColumn('t2.kontrak_id', 'fo_kontrak_tagihan.kontrak_id')
                                        ->whereIn('t2.tipe', ['DP', 'ANG'])
                                        ->whereRaw('t2.nilai - t2.pelunasan > 0.5')
                                        ->whereColumn('t2.urutan', '<', 'fo_kontrak_tagihan.urutan');
                                }));
                    })
            )
            ->heading('Tagihan jatuh tempo berikutnya per kontrak (SPR)')
            ->description('DP & Angsuran tampil berurutan (mis. DP 1 dulu; setelah lunas baru DP 2 muncul) untuk cegah salah bayar. Tambah DP (TDP) SELALU tampil karena sering dibayar langsung di luar urutan.')
            ->columns([
                Tables\Columns\TextColumn::make('kontrak.unit.kode_unit')->label('Unit')->badge()->color('info')->placeholder('—')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('kontrak.customer.full_name')->label('Customer')->searchable()->weight('bold')->wrap(),
                Tables\Columns\TextColumn::make('tipe')->label('Tipe')->badge()
                    ->color(fn (string $state) => match ($state) { 'TDP' => 'warning', 'DP' => 'info', default => 'gray' })
                    ->formatStateUsing(fn (string $state) => match ($state) { 'TDP' => 'Tambah DP', 'DP' => 'DP', default => 'Angsuran' }),
                Tables\Columns\TextColumn::make('nama_tagihan')->label('Tagihan')->searchable(),
                Tables\Columns\TextColumn::make('jatuh_tempo')->label('Jatuh Tempo')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('nilai')->label('Nilai')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('pelunasan')->label('Terbayar')->money('IDR', divideBy: 0)->alignEnd()->color('success'),
                Tables\Columns\TextColumn::make('sisa')->label('Sisa')->money('IDR', divideBy: 0)->alignEnd()->weight('bold')
                    ->getStateUsing(fn (KontrakTagihan $r) => $r->sisa),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->getStateUsing(function (KontrakTagihan $r) {
                        if (! $r->jatuh_tempo) {
                            return 'TANPA JT';
                        }
                        $jt = $r->jatuh_tempo->copy()->startOfDay();
                        $today = now()->startOfDay();
                        if ($jt->lt($today)) {
                            return 'TELAT';
                        }
                        if ($jt->eq($today)) {
                            return 'JATUH TEMPO';
                        }
                        $days = (int) $today->diffInDays($jt);

                        return $days <= 3 ? 'H-'.$days : 'BELUM JT';
                    })
                    ->color(fn (string $state) => match (true) {
                        $state === 'TELAT' => 'danger',
                        $state === 'JATUH TEMPO' => 'warning',
                        str_starts_with($state, 'H-') => 'info',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('jatuh_tempo', 'asc')
            ->actions([
                Tables\Actions\Action::make('bayar')->label('Terima Pembayaran')->icon('heroicon-o-banknotes')->color('success')->button()
                    ->modalHeading(fn (KontrakTagihan $record) => 'Terima Pembayaran — '.$record->nama_tagihan)
                    ->modalDescription(fn (KontrakTagihan $record) => $record->kontrak?->no_kontrak.' · '.($record->kontrak?->customer?->full_name ?? '').' · Sisa Rp '.number_format((float) $record->sisa, 0, ',', '.'))
                    ->modalSubmitActionLabel('Terima & Terbitkan Kwitansi')
                    ->fillForm(fn (KontrakTagihan $record) => ['nominal' => (float) $record->sisa, 'tgl' => now()->toDateString()])
                    ->form([
                        Forms\Components\TextInput::make('nominal')->label('Nominal Bayar (Rp)')->numeric()->rupiah()->required()
                            ->helperText('Default = sisa tagihan. Boleh bayar sebagian (tak melebihi sisa).'),
                        Forms\Components\DatePicker::make('tgl')->label('Tgl Pembayaran')->default(now())->required(),
                        Forms\Components\Select::make('bank_id')->label('Bank / Kas')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        Forms\Components\TextInput::make('no_referensi')->label('No. Referensi / Transfer'),
                        Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2),
                    ])
                    ->action(function (KontrakTagihan $record, array $data) {
                        try {
                            $svc = app(PembayaranService::class);
                            $bkm = $svc->bayar($record, \App\Support\Rupiah::toFloat($data['nominal']), [
                                'tgl' => $data['tgl'] ?? now()->toDateString(),
                                'bank_id' => $data['bank_id'] ?? null,
                                'no_referensi' => $data['no_referensi'] ?? null,
                                'keterangan' => $data['keterangan'] ?? null,
                            ]);
                            $sisa = 'Sisa '.$record->fresh()->nama_tagihan.' kini Rp '.number_format((float) $record->fresh()->sisa, 0, ',', '.').'.';

                            // Hasil kiriman ke BO IKUT DIBERITAHUKAN. Sebelumnya kegagalan kiriman
                            // hanya masuk log sementara notifikasinya tetap berbunyi "Pembayaran
                            // diterima" — operator tidak tahu barisnya tak sampai ke Approval DP.
                            if ($svc->lastBoError === null) {
                                Notification::make()->success()
                                    ->title('Pembayaran diterima — Kwitansi '.$bkm->no_bkm)
                                    ->body($sisa.' Sudah terkirim ke BO → Cash Book → Approval DP.')
                                    ->actions([
                                        \Filament\Notifications\Actions\Action::make('cetak')->label('Cetak Kwitansi')
                                            ->url(route('fo.cetak.kwitansi', $bkm), shouldOpenInNewTab: true),
                                    ])
                                    ->persistent()->send();
                            } else {
                                Notification::make()->warning()
                                    ->title('Pembayaran diterima — Kwitansi '.$bkm->no_bkm.' (BELUM sampai ke BO)')
                                    ->body($sisa.' Kiriman ke Approval DP gagal: '.$svc->lastBoError
                                        .' Kwitansinya tetap sah — kirim ulang lewat menu Kwitansi → tombol "Posting BO".')
                                    ->actions([
                                        \Filament\Notifications\Actions\Action::make('cetak')->label('Cetak Kwitansi')
                                            ->url(route('fo.cetak.kwitansi', $bkm), shouldOpenInNewTab: true),
                                    ])
                                    ->persistent()->send();
                            }
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal')->body($e->getMessage())->persistent()->send();
                        }
                    }),
            ])
            ->paginated([10, 25, 50]);
    }
}
