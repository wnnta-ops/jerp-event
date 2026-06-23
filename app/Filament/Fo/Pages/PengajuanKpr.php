<?php

namespace App\Filament\Fo\Pages;

use App\Models\Bank;
use App\Modules\FO\FinanceAR\Models\KprPengajuan;
use App\Modules\FO\FinanceAR\Models\KprPengajuanBiaya;
use App\Modules\FO\FinanceAR\Services\KprPengajuanService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Support\Rupiah;
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
 * Pengajuan KPR (Finance) — daftar kontrak SPR (KPR). Tiap SPR bisa diajukan
 * pembayaran biaya prosesnya: biaya proses standar + SEMUA FREE dari SPR (gimmick).
 * Biaya diproses → Kas Keluar + posting GL. TIDAK mengubah SPR; pencairan KPR &
 * pelunasan SPR ditangani modul BankLoan.
 */
class PengajuanKpr extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $title = 'Pengajuan KPR';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'pengajuan-kpr';

    protected static string $view = 'filament.fo.pages.pengajuan-kpr';

    /** Pengajuan aktif (bukan BATAL) untuk kontrak. */
    private static function aktif(Kontrak $k): ?KprPengajuan
    {
        return $k->kprPengajuans->first(fn (KprPengajuan $p) => $p->status !== 'BATAL');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Kontrak::query()->where('tahap', 'SPR')->where('cara_bayar', 'KPR')->where('status', '!=', 'BATAL')
                ->with(['unit', 'customer', 'kprPengajuans.biaya']))
            ->heading('SPR (KPR) — Pengajuan Pembayaran')
            ->description('Semua kontrak SPR berjenis KPR (FREE DP / DP 3X). Ajukan pembayaran biaya proses + SEMUA FREE dari SPR, lalu proses (Kas Keluar + GL). SPR tidak diubah.')
            ->columns([
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->badge()->color('info')->placeholder('—')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable()->weight('bold')->wrap(),
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. SPR')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('pengajuan_no')->label('No. Pengajuan')->badge()->color('success')->placeholder('—')
                    ->getStateUsing(fn (Kontrak $r) => self::aktif($r)?->no_pengajuan),
                Tables\Columns\TextColumn::make('pembayaran')->label('Pembayaran')->badge()->color('gray')
                    ->getStateUsing(function (Kontrak $r) {
                        $p = self::aktif($r);
                        if (! $p) {
                            return 'Belum diajukan';
                        }
                        $rel = $p->biaya->filter(fn ($b) => (float) $b->nominal > 0 || $b->status === 'TERPROSES');

                        return $rel->where('status', 'TERPROSES')->count().'/'.$rel->count().' diproses';
                    }),
                Tables\Columns\TextColumn::make('status_pengajuan')->label('Status')->badge()
                    ->getStateUsing(fn (Kontrak $r) => self::aktif($r)?->status ?? 'BELUM')
                    ->color(fn (string $state) => match ($state) { 'SELESAI' => 'success', 'DIAJUKAN' => 'warning', default => 'gray' }),
            ])
            ->filters([
                Tables\Filters\Filter::make('belum_diajukan')->label('Belum diajukan')
                    ->query(fn ($q) => $q->whereDoesntHave('kprPengajuans', fn ($s) => $s->where('status', '!=', 'BATAL'))),
            ])
            ->actions([
                // Buat Pengajuan — daftar pembayaran prefilled dari SPR (biaya + SEMUA FREE).
                Tables\Actions\Action::make('buat')->label('Buat Pengajuan')->icon('heroicon-o-plus')->color('primary')->button()
                    ->visible(fn (Kontrak $record) => ! self::aktif($record))
                    ->modalHeading(fn (Kontrak $record) => 'Buat Pengajuan KPR — '.($record->unit?->kode_unit ?: $record->no_kontrak))
                    ->modalWidth('lg')->modalSubmitActionLabel('Ajukan')
                    ->form([
                        Forms\Components\Section::make('Biaya Proses KPR')
                            ->description('Isi nominal yang perlu dibayar (boleh 0 bila menyusul).')
                            ->columns(2)->schema([
                                Forms\Components\TextInput::make('HT_SEBAGIAN')->label('Biaya HT Sebagian')->numeric()->rupiah()->default(0),
                                Forms\Components\TextInput::make('KPR')->label('Biaya KPR')->numeric()->rupiah()->default(0),
                                Forms\Components\TextInput::make('NOTARIS')->label('Biaya Tagihan Notaris')->numeric()->rupiah()->default(0),
                                Forms\Components\TextInput::make('SISA_HT')->label('Sisa Tagihan HT')->numeric()->rupiah()->default(0),
                            ]),
                        Forms\Components\Placeholder::make('free_info')->label('FREE dari SPR')
                            ->content('SEMUA FREE pada SPR ini (mis. FREE BPHTB) otomatis ditambahkan sebagai item pembayaran. Nominalnya diisi saat "Proses Pembayaran".'),
                        Forms\Components\Repeater::make('extra')->label('Pembayaran Lain (opsional)')
                            ->schema([
                                Forms\Components\TextInput::make('nama')->label('Item')->columnSpan(3),
                                Forms\Components\TextInput::make('nominal')->label('Nominal (Rp)')->numeric()->rupiah()->default(0)->columnSpan(2),
                            ])
                            ->columns(5)->addActionLabel('Tambah pembayaran')->reorderable(false)->defaultItems(0)
                            ->helperText('Tambahan di luar 4 biaya & FREE. Kosongkan bila tak ada.'),
                        Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2),
                    ])
                    ->action(function (Kontrak $record, array $data) {
                        try {
                            $extra = collect($data['extra'] ?? [])->map(fn ($it) => [
                                'nama' => $it['nama'] ?? '',
                                'nominal' => Rupiah::toFloat($it['nominal'] ?? 0),
                            ])->all();
                            $p = app(KprPengajuanService::class)->ajukan($record, [
                                'HT_SEBAGIAN' => Rupiah::toFloat($data['HT_SEBAGIAN'] ?? 0),
                                'KPR' => Rupiah::toFloat($data['KPR'] ?? 0),
                                'NOTARIS' => Rupiah::toFloat($data['NOTARIS'] ?? 0),
                                'SISA_HT' => Rupiah::toFloat($data['SISA_HT'] ?? 0),
                            ], $extra, $data['keterangan'] ?? null);
                            Notification::make()->success()->title('Pengajuan KPR dibuat — '.$p->no_pengajuan)
                                ->body($p->biaya->count().' item pembayaran siap diproses.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal')->body($e->getMessage())->persistent()->send();
                        }
                    }),
                // Proses Pembayaran — satu item → Kas Keluar + posting GL.
                Tables\Actions\Action::make('proses')->label('Proses Pembayaran')->icon('heroicon-o-banknotes')->color('warning')->button()
                    ->visible(fn (Kontrak $record) => ($p = self::aktif($record)) && $p->biaya->where('status', 'PENDING')->isNotEmpty())
                    ->modalHeading(fn (Kontrak $record) => 'Proses Pembayaran — '.(self::aktif($record)?->no_pengajuan))
                    ->modalSubmitActionLabel('Proses (Kas Keluar)')
                    ->form([
                        Forms\Components\Select::make('biaya_id')->label('Pembayaran yang diproses')
                            ->options(fn (Kontrak $record) => optional(self::aktif($record))->biaya->where('status', 'PENDING')
                                ->mapWithKeys(fn ($b) => [$b->id => $b->label.((float) $b->nominal > 0 ? ' — Rp '.number_format((float) $b->nominal, 0, ',', '.') : ' — (nominal menyusul)')]) ?? [])
                            ->required()->live()
                            ->afterStateUpdated(fn ($state, callable $set) => $set('nominal', $state ? (string) (float) (KprPengajuanBiaya::find($state)?->nominal ?? 0) : '0')),
                        Forms\Components\TextInput::make('nominal')->label('Nominal Bayar (Rp)')->numeric()->rupiah()->required()
                            ->helperText('Bisa disesuaikan (mis. nominal yang tadinya menyusul).'),
                        Forms\Components\DatePicker::make('tgl')->label('Tgl Proses')->default(now())->required(),
                        Forms\Components\Select::make('bank_id')->label('Bank / Kas (sumber dana)')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        Forms\Components\TextInput::make('no_referensi')->label('No. Referensi'),
                    ])
                    ->action(function (array $data) {
                        try {
                            $biaya = KprPengajuanBiaya::findOrFail($data['biaya_id']);
                            app(KprPengajuanService::class)->prosesBiaya($biaya, [
                                'nominal' => Rupiah::toFloat($data['nominal'] ?? 0),
                                'tgl' => $data['tgl'] ?? now()->toDateString(),
                                'bank_id' => $data['bank_id'] ?? null,
                                'no_referensi' => $data['no_referensi'] ?? null,
                            ]);
                            Notification::make()->success()->title('Pembayaran diproses')
                                ->body($biaya->label.' → Kas Keluar + posting GL.')->send();
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Gagal')->body($e->getMessage())->persistent()->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }
}
