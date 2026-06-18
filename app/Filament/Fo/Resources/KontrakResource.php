<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\KontrakResource\Pages;
use App\Modules\FO\Adminjual\Models\Gimmick;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Support\Rupiah;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class KontrakResource extends Resource
{
    protected static ?string $model = Kontrak::class;

    // Menu SPRS hanya menampilkan kontrak tahap SPRS; yang sudah final ada di menu SPR.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tahap', 'SPRS')->with('skema');
    }

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'SPRS';

    protected static ?string $pluralModelLabel = 'SPRS';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Kontrak')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Edit Kontrak')
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            Forms\Components\Section::make('Identitas Kontrak')
                                ->visibleOn('create')
                                ->columns(2)
                                ->schema([
                                    Forms\Components\TextInput::make('no_kontrak')
                                        ->label('No kontrak')
                                        ->maxLength(32)
                                        ->placeholder('Otomatis')
                                        ->disabled()
                                        ->dehydrated()
                                        ->helperText('Di-generate otomatis saat simpan.'),
                                    Forms\Components\DatePicker::make('tgl_kontrak')->label('Tanggal kontrak')->required()->default(now()),
                                    Forms\Components\Select::make('reservasi_id')
                                        ->label('Reservasi (opsional)')
                                        ->relationship('reservasi', 'no_reservasi')
                                        ->searchable()
                                        ->preload()
                                        ->live()
                                        ->afterStateUpdated(function ($state, callable $set) {
                                            $r = Reservasi::find($state);
                                            if ($r) {
                                                $set('customer_id', $r->customer_id);
                                                $set('unit_id', $r->unit_id);
                                                $set('harga_jual', $r->harga_jual);
                                            }
                                        }),
                                    Forms\Components\Select::make('customer_id')
                                        ->label('Customer')
                                        ->relationship('customer', 'full_name')
                                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->full_name)
                                        ->searchable(['code', 'full_name'])
                                        ->preload()
                                        ->required(),
                                    Forms\Components\Select::make('unit_id')
                                        ->label('Unit')
                                        ->relationship('unit', 'kode_unit')
                                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->kode_unit ?: trim(($record->blok ? $record->blok.' / ' : '').$record->no_unit))
                                        ->searchable(['kode_unit', 'blok', 'no_unit'])
                                        ->preload()
                                        ->required(),
                                ]),

                            Forms\Components\Section::make('Harga')
                                ->visibleOn('create')
                                ->columns(2)
                                ->schema([
                                    Forms\Components\TextInput::make('harga_jual')->label('Harga jual')->numeric()->rupiah()->default(0)->required(),
                                    Forms\Components\TextInput::make('diskon')->label('Diskon')->numeric()->rupiah()->default(0),
                                    Forms\Components\TextInput::make('ppn')->label('PPN')->numeric()->rupiah()->default(0),
                                    Forms\Components\TextInput::make('total_kontrak')->label('Total kontrak')->numeric()->rupiah()->default(0)->required(),
                                ]),

                            Forms\Components\Section::make('Cara Bayar')
                                ->visibleOn('create')
                                ->columns(3)
                                ->schema([
                                    Forms\Components\Select::make('cara_bayar')
                                        ->label('Cara bayar')
                                        ->options([
                                            'CASH' => 'Cash',
                                            'CASH_BERTAHAP' => 'Cash bertahap',
                                            'CICILAN' => 'Cicilan internal',
                                            'KPR' => 'KPR',
                                        ])
                                        ->default('KPR')
                                        ->required(),
                                    Forms\Components\TextInput::make('tenor_bulan')->label('Tenor (bulan)')->numeric(),
                                    Forms\Components\TextInput::make('dp_persen')->label('DP %')->numeric()->suffix('%'),
                                    Forms\Components\TextInput::make('dp_amount')->label('DP (Rp)')->numeric()->rupiah(),
                                ]),

                            Forms\Components\Section::make('Status')
                                ->visibleOn('create')
                                ->columns(2)
                                ->schema([
                                    Forms\Components\Select::make('status')
                                        ->label('Status')
                                        ->options([
                                            'DRAFT' => 'Draft',
                                            'BOOKING' => 'Booking',
                                            'AKTIF' => 'Aktif',
                                            'LUNAS' => 'Lunas',
                                            'BATAL' => 'Batal',
                                        ])
                                        ->default('DRAFT')
                                        ->required(),
                                    Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
                                ]),

                            Forms\Components\Section::make('Hasil Akhir Kontrak')
                                ->visibleOn('edit')
                                ->description('Read-only — otomatis mengikuti perubahan dari Adjustment Kontrak, tab Skema Bayar, Jadwal Komisi, & Gimmick.')
                                ->schema([
                                    Forms\Components\Placeholder::make('ringkasan')->label('')
                                        ->content(fn (?Kontrak $record) => self::kontrakSummary($record)),
                                ]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Diskon')
                        ->icon('heroicon-o-receipt-percent')
                        ->visible(fn (string $operation) => $operation === 'edit')
                        ->schema([
                            Forms\Components\Section::make('Diskon — mengurangi Harga Unit')
                                ->description('Diskon mengurangi harga unit & WAJIB melalui Approval — diterapkan otomatis (Nilai Kontrak, DPP/PPN & Jadwal Tagihan dibentuk ulang) setelah disetujui. SPR (final): 2 level. SPRS: single-level langsung GM (menu Approval Diskon SPRS).')
                                ->schema([
                                    Forms\Components\Placeholder::make('diskon_status')->label('')
                                        ->content(fn (?Kontrak $record) => new HtmlString(self::diskonStatusHtml($record))),
                                    Forms\Components\Actions::make([
                                        Forms\Components\Actions\Action::make('ajukan_diskon')
                                            ->label('Ajukan Diskon ke Approval')->icon('heroicon-o-receipt-percent')->color('warning')->button()
                                            ->modalHeading('Pengajuan Diskon')->modalWidth('lg')->modalSubmitActionLabel('Ajukan ke Approval')
                                            ->fillForm(fn ($livewire) => ['diskon_baru' => (float) ($livewire->record->diskon ?? 0)])
                                            ->form([
                                                Forms\Components\TextInput::make('diskon_baru')->label('Diskon (Rp)')->numeric()->rupiah()->default(0)->required()->live(onBlur: true),
                                                Forms\Components\Placeholder::make('preview')->label('Nilai Kontrak setelah diskon')
                                                    ->content(function (Forms\Get $get, $livewire) {
                                                        $gross = (float) ($livewire->record->harga_jual ?? 0);
                                                        $total = max(0, $gross - Rupiah::toFloat($get('diskon_baru')));

                                                        return new HtmlString('<strong>Rp '.number_format($total, 0, ',', '.').'</strong>'
                                                            .' <span style="color:#94a3b8;">(Harga Unit Rp '.number_format($gross, 0, ',', '.').' − Diskon)</span>');
                                                    }),
                                                Forms\Components\Textarea::make('alasan')->label('Alasan / Catatan')->rows(2),
                                            ])
                                            ->action(fn (array $data, $livewire) => $livewire->submitDiskon($data)),
                                    ]),
                                ]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Jadwal Komisi')
                        ->icon('heroicon-o-banknotes')
                        ->schema([
                            Forms\Components\Repeater::make('komisi')->relationship()->label('')
                                ->columns(12)->defaultItems(0)->addActionLabel('Tambah Komisi')
                                ->itemLabel(fn (array $state): ?string => isset($state['amount'])
                                    ? ('Gross Rp '.number_format(Rupiah::toFloat($state['amount'] ?? 0), 0, ',', '.'))
                                    : null)
                                ->schema([
                                    Forms\Components\Select::make('agent_id')->label('Agent / Sales')->columnSpan(4)
                                        ->relationship('agent', 'name')->searchable()->preload()->required(),
                                    Forms\Components\DatePicker::make('tgl_komisi')->label('Tgl Komisi')->columnSpan(2)
                                        ->default(now())->native(false)->displayFormat('d M Y')->required(),
                                    Forms\Components\TextInput::make('dpp')->label('DPP')->columnSpan(3)->numeric()->rupiah()->default(0),
                                    Forms\Components\TextInput::make('persen_komisi')->label('% Komisi')->columnSpan(3)->numeric()->suffix('%')->default(0),
                                    Forms\Components\TextInput::make('amount')->label('Gross Komisi')->columnSpan(3)->numeric()->rupiah()->default(0),
                                    Forms\Components\TextInput::make('pph_amount')->label('PPH')->columnSpan(3)->numeric()->rupiah()->default(0),
                                    Forms\Components\TextInput::make('amount_net')->label('Nilai Bayar (Net)')->columnSpan(3)->numeric()->rupiah()->default(0),
                                    Forms\Components\Select::make('status')->label('Status')->columnSpan(3)
                                        ->options(['DRAFT' => 'Draft', 'APPROVED' => 'Approved', 'PAID' => 'Cair/Paid', 'BATAL' => 'Batal'])
                                        ->default('DRAFT')->required(),
                                ]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Gimmick')
                        ->icon('heroicon-o-gift')
                        ->schema([
                            Forms\Components\Repeater::make('gimmicks')->relationship()->label('')
                                ->helperText('Baris GMC di Jadwal Tagihan otomatis menyesuaikan gimmick ini setelah Simpan.')
                                ->columns(12)->defaultItems(0)->addActionLabel('Tambah Gimmick')
                                ->reorderable()->orderColumn('urutan')
                                ->itemLabel(fn (array $state): ?string => $state['nama_barang'] ?? null)
                                ->schema([
                                    Forms\Components\Select::make('gimmick_id')->label('Item Gimmick (dari Master)')->columnSpan(9)
                                        ->options(fn () => Gimmick::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                                        ->searchable()->required()->live()
                                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                                            $set('nama_barang', $state ? Gimmick::find($state)?->name : null);
                                        }),
                                    Forms\Components\Toggle::make('diberikan')->label('Aktif (tampil di SPR)')->columnSpan(3)->default(true)->inline(false),
                                    Forms\Components\Hidden::make('nama_barang'),
                                ]),
                        ]),

                    Forms\Components\Tabs\Tab::make('Skema Bayar')
                        ->icon('heroicon-o-calendar-days')
                        ->schema([
                            Forms\Components\Select::make('skema_id')->label('Skema Bayar')
                                ->relationship('skema', 'name')
                                ->getOptionLabelFromRecordUsing(fn ($record) => trim(($record->code ? $record->code.' — ' : '').$record->name))
                                ->searchable()->preload()
                                ->helperText('Termin (struktur DP/angsuran) diatur di Setup Skema Bayar (master). Ganti skema lalu klik Simpan → Jadwal Tagihan otomatis tergantikan dengan skema baru.'),

                            Forms\Components\Repeater::make('tagihan')->relationship()->label('Jadwal Tagihan')
                                ->columns(12)->defaultItems(0)->addActionLabel('Tambah Tagihan')
                                ->reorderable()->orderColumn('urutan')
                                // Duplikat baris (termasuk tanggal & nilai) → tambah tagihan serupa
                                // lebih cepat tanpa pilih ulang tanggal tiap baris.
                                ->cloneable()
                                ->live()
                                ->itemLabel(fn (array $state): ?string => ($state['nama_tagihan'] ?? null)
                                    ? (($state['tipe'] ?? '').' — '.$state['nama_tagihan'])
                                    : null)
                                ->schema([
                                    Forms\Components\Select::make('tipe')->label('Tipe')->columnSpan(2)
                                        ->options(['BF' => 'BF (Booking Fee)', 'DP' => 'DP', 'TDP' => 'Tambah DP', 'ANG' => 'Angsuran', 'ADM' => 'Administrasi', 'LAIN' => 'Lain'])
                                        ->default('ANG')->required()->live(),
                                    Forms\Components\TextInput::make('nama_tagihan')->label('Nama Tagihan')->columnSpan(4)->required(),
                                    Forms\Components\DatePicker::make('jatuh_tempo')->label('Jatuh Tempo')->columnSpan(2)->native(false)->displayFormat('d M Y'),
                                    Forms\Components\TextInput::make('nilai')->label('Nilai')->columnSpan(2)->numeric()->rupiah()->default(0)->live(onBlur: true),
                                    Forms\Components\TextInput::make('pelunasan')->label('Pelunasan')->columnSpan(2)->numeric()->rupiah()->default(0),
                                ]),

                            // Rincian Nilai Kontrak vs Tagihan+Biaya + Selisih (mirip eProperty).
                            // Selisih harus 0 agar SPR bisa disimpan (lihat EditKontrak::beforeSave).
                            Forms\Components\Placeholder::make('ringkasan_tagihan')->label('')
                                ->content(fn (Forms\Get $get, ?Kontrak $record) => new HtmlString(
                                    self::ringkasanTagihanHtml(
                                        (array) $get('tagihan'),
                                        (float) ($record?->total_kontrak ?? $get('total_kontrak') ?? 0),
                                    )
                                )),
                        ]),
                ]),
        ]);
    }

    /**
     * Total Jadwal Tagihan untuk ringkasan & validasi selisih: jumlah seluruh
     * baris tagihan dibandingkan ke Nilai Kontrak (harga unit). Tanpa biaya ADM.
     *
     * @param  array<int,array<string,mixed>>  $tagihan
     * @return array{total:float,nilai_kontrak:float,selisih:float}
     */
    /** Kwitansi (BKM) sudah terbit untuk kontrak ini? Gate promosi SPRS → SPR. */
    public static function kwitansiTerbit(Kontrak $record): bool
    {
        return \App\Modules\FO\FinanceAR\Models\Bkm::query()->where('kontrak_id', $record->id)->exists();
    }

    public static function hitungTagihan(array $tagihan, float $nilaiKontrak): array
    {
        $total = 0.0;
        foreach ($tagihan as $t) {
            // GMC (gimmick FREE) tidak ditagih ke customer — tak dihitung di selisih.
            if (($t['tipe'] ?? '') === 'GMC') {
                continue;
            }
            $total += Rupiah::toFloat($t['nilai'] ?? 0);
        }

        return [
            'total' => $total,
            'nilai_kontrak' => $nilaiKontrak,
            'selisih' => round($nilaiKontrak - $total, 2),
        ];
    }

    /** Kartu ringkasan (HTML) Nilai Kontrak vs Total Tagihan + Selisih (mirip eProperty). */
    private static function ringkasanTagihanHtml(array $tagihan, float $nilaiKontrak): string
    {
        $c = self::hitungTagihan($tagihan, $nilaiKontrak);
        $rp = fn (float $v): string => 'Rp '.number_format($v, 0, ',', '.');
        $pas = abs($c['selisih']) < 1;
        $warna = $pas ? '#16a34a' : '#dc2626';
        $baris = fn (string $label, string $nilai, string $extra = ''): string => '<div style="display:flex;justify-content:space-between;gap:24px;padding:3px 0;'.$extra.'">'
            .'<span>'.$label.'</span><span style="font-variant-numeric:tabular-nums;">'.$nilai.'</span></div>';

        return '<div style="max-width:440px;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;font-size:13px;">'
            .$baris('Nilai Kontrak (Harga Unit)', $rp($c['nilai_kontrak']))
            .$baris('Total Tagihan', $rp($c['total']), 'border-top:1px solid #e2e8f0;margin-top:4px;padding-top:6px;font-weight:600;')
            .$baris(
                '<strong style="color:'.$warna.';">Selisih</strong>',
                '<strong style="color:'.$warna.';">'.$rp($c['selisih']).($pas ? '  ✓ pas' : '').'</strong>',
                'margin-top:2px;'
            )
            .($pas ? '' : '<div style="color:#dc2626;margin-top:6px;font-size:12px;">Total Tagihan harus pas dengan Nilai Kontrak (selisih 0) agar SPR bisa disimpan.</div>')
            .'</div>';
    }

    public static function table(Table $table): Table
    {
        // Menu SPRS → tombol "Cetak SPRS".
        return self::buildTable($table, 'Cetak SPRS');
    }

    /** Tabel kontrak (dipakai SPRS & SPR); $cetakLabel mengikuti menu. */
    public static function buildTable(Table $table, string $cetakLabel = 'Cetak SPRS'): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No kontrak')->searchable()->sortable()->badge(),
                Tables\Columns\TextColumn::make('tgl_kontrak')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('unit.kode_unit')
                    ->label('Unit')
                    ->placeholder('—')
                    ->searchable(),
                // Judul tetap "Cara bayar", isinya nama Skema Bayar kontrak.
                Tables\Columns\TextColumn::make('skema_bayar')->label('Cara bayar')->badge()
                    ->state(fn (Kontrak $r) => $r->skema?->name ?: ($r->cara_bayar ?: '—'))
                    ->color(fn (Kontrak $r) => strtoupper((string) $r->cara_bayar) === 'KPR' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Total')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'DRAFT' => 'gray',
                    'BOOKING' => 'info',
                    'AKTIF' => 'success',
                    'LUNAS' => 'primary',
                    'BATAL' => 'danger',
                    default => 'gray',
                }),
                Tables\Columns\TextColumn::make('tahap')->label('Tahap')->badge()
                    ->formatStateUsing(fn (?string $state) => $state === 'SPR' ? '✓ Sudah SPR' : 'SPRS')
                    ->color(fn (?string $state) => $state === 'SPR' ? 'success' : 'warning'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('cara_bayar')->options([
                    'CASH' => 'Cash', 'CASH_BERTAHAP' => 'Cash bertahap', 'CICILAN' => 'Cicilan internal', 'KPR' => 'KPR',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'BOOKING' => 'Booking', 'AKTIF' => 'Aktif', 'LUNAS' => 'Lunas', 'BATAL' => 'Batal',
                ]),
                Tables\Filters\SelectFilter::make('tahap')->label('Tahap')->options([
                    'SPRS' => 'SPRS (belum final)', 'SPR' => 'Sudah SPR',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Simpan sebagai SPR (dipersingkat "SPR") — finalkan SPRS → SPR, unit TERJUAL.
                Tables\Actions\Action::make('jadikan_spr')
                    ->label('SPR')
                    ->icon('heroicon-o-document-check')
                    ->color('success')
                    // SPR baru MUNCUL setelah finance "Buka Kuitansi" (kwitansi/BKM terbit) utk kontrak ini.
                    ->visible(fn (Kontrak $record) => $record->tahap !== 'SPR' && self::kwitansiTerbit($record))
                    ->requiresConfirmation()
                    ->modalHeading('Simpan sebagai SPR')
                    ->modalDescription('Finalkan SPRS ini menjadi SPR. Unit akan ditandai TERJUAL.')
                    ->action(function ($record) {
                        if (! self::kwitansiTerbit($record)) {
                            Notification::make()->warning()->title('Belum bisa SPR')
                                ->body('Kwitansi belum terbit dari finance. Buka Kuitansi dulu di menu TTS.')->send();

                            return;
                        }
                        $record->update(['tahap' => 'SPR']);
                        if ($record->unit_id && $unit = Unit::find($record->unit_id)) {
                            $unit->update(['status' => 'TERJUAL']);
                        }
                        Notification::make()->success()->title('Disimpan sebagai SPR')
                            ->body('Kontrak '.$record->no_kontrak.' kini SPR — unit ditandai TERJUAL.')->send();
                    }),
                Tables\Actions\Action::make('cetak_spr')
                    ->label($cetakLabel)
                    ->icon('heroicon-o-document-text')
                    // SPRS → template SPRS (sprs.blade), SPR final → template SPR.
                    ->url(fn (Kontrak $record) => $record->tahap === 'SPRS'
                        ? route('fo.cetak.sprs-kontrak', $record)
                        : route('fo.cetak.spr', $record), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('cetak_jadwal')->label('Jadwal')->icon('heroicon-o-calendar-days')
                    ->url(fn ($record) => route('fo.cetak.jadwal', $record), shouldOpenInNewTab: true),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('tgl_kontrak', 'desc');
    }

    /** Ringkasan hasil akhir kontrak (read-only, seperti SPR) untuk tab Edit Kontrak saat edit. */
    /** Status diskon tab Diskon: harga/diskon/nilai kontrak saat ini + pengajuan diskon yang masih open. */
    public static function diskonStatusHtml(?Kontrak $k): string
    {
        if (! $k) {
            return '—';
        }
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $total = (float) $k->total_kontrak;
        $ppn = (float) $k->ppn ?: round($total - round($total / 1.11), 2);
        $dpp = $total - $ppn;

        $row = fn ($l, $v, $strong = false) => '<tr><td style="padding:3px 12px;color:#94a3b8;width:220px;">'.$l
            .'</td><td style="padding:3px 12px;text-align:right;'.($strong ? 'font-weight:700;' : '').'">'.$v.'</td></tr>';

        $html = '<table style="font-size:13px;border-collapse:collapse;min-width:380px;">'
            .$row('Harga Unit (Gross)', $rp($k->harga_jual))
            .$row('Diskon saat ini', '− '.$rp($k->diskon))
            .$row('DPP', $rp($dpp))
            .$row('PPN (11%)', $rp($ppn))
            .'<tr><td colspan="2" style="border-top:1px solid #475569;"></td></tr>'
            .$row('Nilai Kontrak', $rp($total), true)
            .'</table>';

        $open = \App\Modules\FO\Approval\Models\Approval::where('subject_type', Kontrak::class)
            ->where('subject_id', $k->id)->whereIn('jenis', ['DISKON', 'DISKON_SPRS'])
            ->whereIn('status', ['DRAFT', 'PENDING'])->count();
        if ($open > 0) {
            $html .= '<div style="margin-top:10px;color:#fbbf24;font-size:13px;">⏳ '.$open.' pengajuan diskon menunggu persetujuan (lihat menu Approval).</div>';
        }

        return $html;
    }

    public static function kontrakSummary(?Kontrak $k): HtmlString
    {
        if (! $k) {
            return new HtmlString('—');
        }

        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $total = (float) $k->total_kontrak;
        $ppn = (float) $k->ppn;
        $dpp = $ppn > 0 ? $total - $ppn : round($total / 1.11);
        if ($ppn <= 0) {
            $ppn = $total - $dpp;
        }
        $unit = $k->unit?->kode_unit ?: trim(($k->unit?->blok ? $k->unit->blok.' / ' : '').($k->unit?->no_unit ?? ''));

        $row = fn ($l, $v) => '<tr><td style="padding:2px 10px;color:#94a3b8;width:190px;">'.$l.'</td><td style="padding:2px 10px;">: '.$v.'</td></tr>';
        $kv = '<table style="font-size:13px;border-collapse:collapse;width:100%;">'
            .$row('No. Kontrak', e($k->no_kontrak))
            .$row('Tanggal', e(optional($k->tgl_kontrak)->format('d M Y')))
            .$row('Customer', e($k->customer?->full_name ?: '—'))
            .$row('Unit', e($unit ?: '—'))
            .$row('Sales', e($k->agent?->name ?: '—'))
            .$row('Skema Bayar', e($k->skema?->name ?: '—'))
            .$row('Price List (Gross)', $rp($k->harga_jual))
            .$row('Diskon', $rp($k->diskon))
            .$row('DPP', $rp($dpp))
            .$row('PPN', $rp($ppn))
            .$row('Nilai Kontrak', '<strong>'.$rp($total).'</strong>')
            .$row('Status / Tahap', e($k->status).' / '.($k->tahap === 'SPR' ? 'SPR (final)' : 'SPRS'))
            .'</table>';

        // Jadwal Tagihan
        $tg = '';
        foreach ($k->tagihan as $t) {
            $tg .= '<tr>'
                .'<td style="padding:2px 10px;border-top:1px solid #475569;">'.e($t->tipe).'</td>'
                .'<td style="padding:2px 10px;border-top:1px solid #475569;">'.e($t->nama_tagihan).'</td>'
                .'<td style="padding:2px 10px;border-top:1px solid #475569;">'.e(optional($t->jatuh_tempo)->format('d M Y')).'</td>'
                .'<td style="padding:2px 10px;border-top:1px solid #475569;text-align:right;">'.$rp($t->nilai).'</td>'
                .'</tr>';
        }
        $tgTable = $tg
            ? '<table style="font-size:12px;border-collapse:collapse;width:100%;"><thead><tr>'
                .'<th style="text-align:left;padding:2px 10px;">Tipe</th><th style="text-align:left;padding:2px 10px;">Tagihan</th>'
                .'<th style="text-align:left;padding:2px 10px;">Jatuh Tempo</th><th style="text-align:right;padding:2px 10px;">Nilai</th>'
                .'</tr></thead><tbody>'.$tg.'</tbody></table>'
            : '<span style="color:#94a3b8;">Belum ada jadwal tagihan.</span>';

        // Gimmick (yang diberikan)
        $gim = $k->gimmicks->where('diberikan', true)->pluck('nama_barang')->filter();
        $gimList = $gim->count()
            ? '<ul style="margin:0;padding-left:18px;font-size:12px;">'.$gim->map(fn ($n) => '<li>'.e($n).'</li>')->implode('').'</ul>'
            : '<span style="color:#94a3b8;">—</span>';

        return new HtmlString(
            $kv
            .'<div style="margin-top:14px;font-weight:700;font-size:13px;">Jadwal Tagihan</div>'.$tgTable
            .'<div style="margin-top:14px;font-weight:700;font-size:13px;">Sudah termasuk (Gimmick)</div>'.$gimList
        );
    }

    /** Preview HTML detail skema bayar (header + termin) untuk tab Skema Bayar. */
    public static function skemaDetail(mixed $skemaId): HtmlString
    {
        if (! $skemaId) {
            return new HtmlString('<span style="color:#94a3b8;">Pilih skema untuk lihat detail termin.</span>');
        }

        $skema = Skema::with('lines')->find($skemaId);
        if (! $skema) {
            return new HtmlString('—');
        }

        $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.').'%';
        $head = 'Cara bayar: <strong>'.e($skema->cara_bayar).'</strong> · DP '.$pct($skema->dp_persen)
            .' · Tenor '.(int) $skema->tenor_bulan.' bln · '.$skema->lines->count().' termin';

        $td = 'padding:3px 9px;border-top:1px solid #475569;';
        $rows = '';
        $i = 0;
        foreach ($skema->lines as $l) {
            $i++;
            $nilai = $l->basis === 'NOMINAL'
                ? 'Rp '.number_format((float) $l->nominal, 0, ',', '.')
                : $pct($l->persen);
            $jadwal = ((int) $l->jadwal_nilai).' '.e($l->jadwal_basis ?: 'D');
            $rows .= '<tr>'
                .'<td style="'.$td.'text-align:center;">'.$i.'</td>'
                .'<td style="'.$td.'">'.e($l->tipe ?: '-').'</td>'
                .'<td style="'.$td.'">'.e($l->nama_termin).'</td>'
                .'<td style="'.$td.'text-align:right;">'.$nilai.'</td>'
                .'<td style="'.$td.'text-align:center;">'.$jadwal.'</td>'
                .'<td style="'.$td.'text-align:center;">'.((int) $l->setelah).'</td>'
                .'<td style="'.$td.'text-align:center;">'.($l->flag_bf ? '✓' : '–').'</td>'
                .'<td style="'.$td.'text-align:center;">'.($l->flag_kpr ? '✓' : '–').'</td>'
                .'</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="8" style="padding:6px 10px;color:#94a3b8;">Skema ini belum punya baris termin.</td></tr>';
        }

        $th = 'padding:3px 9px;background:#1e293b;';

        return new HtmlString(
            '<div style="font-size:12px;margin-bottom:6px;">'.$head.'</div>'
            .'<table style="font-size:12px;border-collapse:collapse;width:100%;">'
            .'<thead><tr>'
            .'<th style="'.$th.'text-align:center;width:36px;">No.</th>'
            .'<th style="'.$th.'text-align:left;">Tipe</th>'
            .'<th style="'.$th.'text-align:left;">Nama</th>'
            .'<th style="'.$th.'text-align:right;">Nilai</th>'
            .'<th style="'.$th.'text-align:center;">Jadwal</th>'
            .'<th style="'.$th.'text-align:center;">Setelah</th>'
            .'<th style="'.$th.'text-align:center;">BF</th>'
            .'<th style="'.$th.'text-align:center;">KPR</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<div style="font-size:11px;color:#94a3b8;margin-top:4px;">Jadwal: nilai + satuan (M=Bulan, D=Hari). Ubah struktur skema di master Skema Cara Bayar.</div>'
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKontraks::route('/'),
            'create' => Pages\CreateKontrak::route('/create'),
            'edit' => Pages\EditKontrak::route('/{record}/edit'),
        ];
    }
}
