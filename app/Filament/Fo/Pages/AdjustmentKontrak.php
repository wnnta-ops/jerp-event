<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Adjustment Kontrak — ubah harga kontrak SPR yang sudah terbentuk.
 * Tampil daftar kontrak dulu; pilih satu → form adjustment muncul. Nilai
 * DPP/PPN/Nilai Kontrak otomatis dihitung ulang dari input:
 *   Nilai Kontrak = Price List (Gross) + Bunga − Diskon Skema − Diskon Tambahan
 *   DPP = Nilai Kontrak / 1.11 ; PPN = Nilai Kontrak − DPP
 * Tidak boleh di bawah Harga Minimum unit.
 */
class AdjustmentKontrak extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $title = 'Adjustment Kontrak';

    protected static ?string $navigationLabel = 'Adjustment Kontrak';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'adjustment-kontrak';

    protected static string $view = 'filament.fo.pages.adjustment-kontrak';

    public ?array $data = [];

    public ?int $selectedKontrakId = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    /** Daftar kontrak — pilih untuk adjustment. */
    public function table(Table $table): Table
    {
        return $table
            ->query(Kontrak::query()->with(['unit', 'customer', 'agent']))
            ->heading('Daftar Kontrak')
            ->description('Pilih kontrak yang akan di-adjust harganya.')
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit.no_unit')->label('Unit')
                    ->formatStateUsing(fn ($record) => trim(($record->unit?->blok ? $record->unit->blok.' / ' : '').($record->unit?->no_unit ?? '')))
                    ->searchable(['blok', 'no_unit']),
                Tables\Columns\TextColumn::make('tgl_kontrak')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer / Sales')
                    ->description(fn ($record) => $record->agent?->name)
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Nilai Kontrak')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'AKTIF' => 'success', 'LUNAS' => 'primary', 'BATAL' => 'danger', 'BOOKING' => 'info', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'BOOKING' => 'Booking', 'AKTIF' => 'Aktif', 'LUNAS' => 'Lunas', 'BATAL' => 'Batal',
                ])->default('AKTIF'),
            ])
            ->actions([
                Tables\Actions\Action::make('adjust')->label('Adjustment')->icon('heroicon-o-adjustments-horizontal')
                    ->button()->color('warning')
                    ->action(fn (Kontrak $record) => $this->selectKontrak($record->id)),
            ])
            ->defaultSort('tgl_kontrak', 'desc')
            ->paginated([10, 25, 50]);
    }

    public function selectKontrak(int $id): void
    {
        $this->selectedKontrakId = $id;
        $this->form->fill(array_merge(['kontrak_id' => $id], self::kontrakData($id)));
    }

    public function backToList(): void
    {
        $this->selectedKontrakId = null;
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        $rp = fn (Forms\Components\TextInput $c) => $c->numeric()->prefix('Rp')->default(0);
        $live = fn (Forms\Components\TextInput $c) => $c->live(onBlur: true)
            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => self::recalc($get, $set));

        return $form->statePath('data')->inlineLabel()->schema([
            Forms\Components\Hidden::make('kontrak_id'),

            Forms\Components\Placeholder::make('skema_v')->label('Skema')
                ->content(fn (Forms\Get $get) => $get('skema_nama') ?: '—'),

            Forms\Components\Grid::make(2)->schema([
                // ── KIRI: sebelum + perhitungan baru ──
                Forms\Components\Group::make()->schema([
                    Forms\Components\Section::make('Perincian Kontrak Sebelum Adjustment')->compact()->schema([
                        self::ro('Nilai Kontrak', 'nilai_kontrak_lama'),
                        self::ro('DPP', 'dpp_lama'),
                        self::ro('PPN', 'ppn_lama'),
                    ]),
                    Forms\Components\Section::make('Perhitungan Harga Baru')->compact()
                        ->description('Nilai DPP/PPN/Nilai Kontrak otomatis mengikuti input harga.')
                        ->schema([
                            $live($rp(Forms\Components\TextInput::make('price_list_gross')->label('Price List (Gross)'))),
                            $live($rp(Forms\Components\TextInput::make('bunga')->label('Bunga'))),
                            $live($rp(Forms\Components\TextInput::make('diskon_skema')->label('Diskon Skema'))),
                            $live($rp(Forms\Components\TextInput::make('diskon_tambahan')->label('Diskon Tambahan'))),
                            $rp(Forms\Components\TextInput::make('nilai_dpp')->label('Nilai DPP'))->readOnly()->dehydrated(false),
                            $rp(Forms\Components\TextInput::make('nilai_ppn')->label('Nilai PPN'))->readOnly()->dehydrated(false),
                            $rp(Forms\Components\TextInput::make('nilai_kontrak')->label('Nilai Kontrak'))->readOnly()->dehydrated(false)
                                ->extraInputAttributes(['class' => 'font-bold']),
                        ]),
                ]),

                // ── KANAN: info kontrak + ringkasan ──
                Forms\Components\Group::make()->schema([
                    Forms\Components\Section::make('Info Kontrak')->compact()->schema([
                        Forms\Components\Placeholder::make('info_no_v')->label('No. Kontrak')->content(fn (Forms\Get $get) => $get('info_no') ?: '—'),
                        Forms\Components\Placeholder::make('info_unit_v')->label('Unit')->content(fn (Forms\Get $get) => $get('info_unit') ?: '—'),
                        Forms\Components\Placeholder::make('info_customer_v')->label('Customer')->content(fn (Forms\Get $get) => $get('info_customer') ?: '—'),
                        Forms\Components\Placeholder::make('info_sales_v')->label('Sales')->content(fn (Forms\Get $get) => $get('info_sales') ?: '—'),
                        self::ro('Nilai Kontrak', 'nilai_kontrak'),
                        self::ro('Total Tagihan', 'total_tagihan'),
                        self::ro('Selisih', 'selisih'),
                    ]),
                ]),
            ]),
        ]);
    }

    private static function ro(string $label, string $key): Forms\Components\Placeholder
    {
        return Forms\Components\Placeholder::make($key.'_v')->label($label)
            ->content(fn (Forms\Get $get) => 'Rp '.number_format((float) $get($key), 0, ',', '.'));
    }

    /** Hitung semua nilai (sebelum + prefill + hasil) untuk satu kontrak. */
    public static function kontrakData(int $id): array
    {
        $k = Kontrak::with(['unit', 'customer', 'agent', 'skema'])->find($id);
        if (! $k) {
            return [];
        }

        $total = (float) $k->total_kontrak;
        $ppnLama = (float) $k->ppn;
        $dppLama = $ppnLama > 0 ? $total - $ppnLama : round($total / 1.11);
        if ($ppnLama <= 0) {
            $ppnLama = $total - $dppLama;
        }

        $gross = (float) $k->harga_jual;
        $ds = (float) $k->diskon;
        $nk = max(0, $gross - $ds);
        $dpp = round($nk / 1.11);
        $totalTagihan = (float) $k->tagihan()->sum('nilai');

        return [
            'skema_nama' => $k->skema?->name ?: ($k->skema?->code ?: '—'),
            'nilai_kontrak_lama' => $total,
            'dpp_lama' => $dppLama,
            'ppn_lama' => $ppnLama,
            'total_tagihan' => $totalTagihan,
            'info_no' => $k->no_kontrak,
            'info_unit' => trim(($k->unit?->blok ? $k->unit->blok.' / ' : '').($k->unit?->no_unit ?? '')),
            'info_customer' => $k->customer?->full_name,
            'info_sales' => $k->agent?->name,
            'price_list_gross' => $gross,
            'bunga' => 0,
            'diskon_skema' => $ds,
            'diskon_tambahan' => 0,
            'nilai_dpp' => $dpp,
            'nilai_ppn' => $nk - $dpp,
            'nilai_kontrak' => $nk,
            'selisih' => $nk - $totalTagihan,
        ];
    }

    public static function recalc(Forms\Get $get, Forms\Set $set): void
    {
        $gross = (float) $get('price_list_gross');
        $bunga = (float) $get('bunga');
        $ds = (float) $get('diskon_skema');
        $dt = (float) $get('diskon_tambahan');

        $nk = max(0, $gross + $bunga - $ds - $dt);
        $dpp = round($nk / 1.11);
        $set('nilai_kontrak', $nk);
        $set('nilai_dpp', $dpp);
        $set('nilai_ppn', $nk - $dpp);
        $set('selisih', $nk - (float) $get('total_tagihan'));
    }

    public function submit(): void
    {
        $d = $this->form->getState();
        $k = Kontrak::findOrFail($d['kontrak_id']);

        $gross = (float) ($d['price_list_gross'] ?? 0);
        $bunga = (float) ($d['bunga'] ?? 0);
        $ds = (float) ($d['diskon_skema'] ?? 0);
        $dt = (float) ($d['diskon_tambahan'] ?? 0);
        $nk = max(0, $gross + $bunga - $ds - $dt);
        $dpp = round($nk / 1.11);
        $ppn = $nk - $dpp;

        $k->update([
            'harga_jual' => $gross,
            'diskon' => $ds + $dt,
            'ppn' => $ppn,
            'total_kontrak' => $nk,
            'keterangan' => trim(($k->keterangan ? $k->keterangan."\n" : '').'Adjustment harga '.now()->format('d M Y H:i').': Nilai Kontrak Rp '.number_format($nk, 0, ',', '.')),
        ]);

        Notification::make()->success()
            ->title('Adjustment kontrak tersimpan')
            ->body('Nilai Kontrak baru Rp '.number_format($nk, 0, ',', '.').'. Jalankan Reschedule Tagihan untuk menyesuaikan tagihan.')
            ->send();

        $this->backToList();
    }
}
