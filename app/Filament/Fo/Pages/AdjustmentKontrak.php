<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Support\Rupiah;
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
use Illuminate\Support\HtmlString;

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
    use MematuhiIzinHalaman;

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
            ->query(Kontrak::query()->with(['unit', 'customer', 'agent'])->where('tahap', 'SPR'))
            ->heading('Daftar Kontrak (SPR)')
            ->description('Hanya kontrak yang sudah SPR (final). Pilih untuk adjustment — pengajuan masuk ke Approval Adjustment.')
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('tgl_kontrak')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer / Sales')
                    ->description(fn ($record) => $record->agent?->name)
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Nilai Kontrak')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'AKTIF' => 'success', 'LUNAS' => 'primary', 'BATAL' => 'danger', 'BOOKING' => 'info', default => 'gray',
                }),
                Tables\Columns\TextColumn::make('adj_count')->label('Adjustment')->badge()
                    ->getStateUsing(fn (Kontrak $record) => Approval::where('subject_type', Kontrak::class)->where('subject_id', $record->id)->where('jenis', 'ADJ_HARGA')->count())
                    ->formatStateUsing(fn ($state) => $state.'×')
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
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
                Tables\Actions\Action::make('riwayat')->label('Riwayat')->icon('heroicon-o-clock')->color('gray')->link()
                    ->modalHeading(fn (Kontrak $record) => 'Riwayat Adjustment — '.$record->no_kontrak)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (Kontrak $record) => new HtmlString(self::riwayatApprovalHtml($record, 'ADJ_HARGA'))),
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
        $rp = fn (Forms\Components\TextInput $c) => $c->numeric()->rupiah()->default(0);
        $live = fn (Forms\Components\TextInput $c) => $c->live(onBlur: true)
            ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => self::recalc($get, $set));

        return $form->statePath('data')->inlineLabel()->schema([
            Forms\Components\Hidden::make('kontrak_id'),

            Forms\Components\Placeholder::make('skema_v')->label('Skema')
                ->content(fn (Forms\Get $get) => $get('skema_nama') ?: '—'),

            // Konten di kolom kiri (lebih ramping), kolom kanan dibiarkan kosong
            // (box "Info Kontrak" dihilangkan).
            Forms\Components\Grid::make(2)->schema([
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
            ]),
        ]);
    }

    private static function ro(string $label, string $key): Forms\Components\Placeholder
    {
        return Forms\Components\Placeholder::make($key.'_v')->label($label)
            ->content(fn (Forms\Get $get) => 'Rp '.number_format(Rupiah::toFloat($get($key)), 0, ',', '.'));
    }

    /** Tabel HTML riwayat approval (jenis tertentu) untuk satu kontrak. */
    public static function riwayatApprovalHtml(Kontrak $k, string $jenis): string
    {
        $rows = Approval::where('subject_type', Kontrak::class)->where('subject_id', $k->id)
            ->where('jenis', $jenis)->orderByDesc('id')->get();
        if ($rows->isEmpty()) {
            return '<div style="color:#94a3b8;font-size:13px;">Belum ada riwayat.</div>';
        }
        $body = $rows->map(function ($a) {
            $db = (array) $a->detail_baru;
            $dl = (array) $a->detail_lama;
            $nilai = $db['total_kontrak_baru'] ?? $db['nilai_kontrak_baru'] ?? $db['nilai_kontrak'] ?? $dl['nilai_kontrak'] ?? null;

            return '<tr>'
                .'<td style="padding:4px 10px;">'.e($a->no_approval).'</td>'
                .'<td style="padding:4px 10px;">'.e(optional($a->tgl_approval)->format('d M Y')).'</td>'
                .'<td style="padding:4px 10px;">'.e($a->status).'</td>'
                .'<td style="padding:4px 10px;text-align:right;">'.($nilai !== null ? 'Rp '.number_format((float) $nilai, 0, ',', '.') : '—').'</td>'
                .'</tr>';
        })->implode('');

        return '<table style="width:100%;font-size:13px;border-collapse:collapse;">'
            .'<thead><tr style="text-align:left;color:#94a3b8;border-bottom:1px solid #334155;">'
            .'<th style="padding:4px 10px;">No. Approval</th><th style="padding:4px 10px;">Tgl</th>'
            .'<th style="padding:4px 10px;">Status</th><th style="padding:4px 10px;text-align:right;">Nilai Kontrak</th></tr></thead>'
            .'<tbody>'.$body.'</tbody></table>';
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
        // Field rupiah pakai mask ribuan → baca dengan Rupiah::toFloat (bukan (float))
        // agar "500.000.000" tak terpotong jadi 500.
        $gross = Rupiah::toFloat($get('price_list_gross'));
        $bunga = Rupiah::toFloat($get('bunga'));
        $ds = Rupiah::toFloat($get('diskon_skema'));
        $dt = Rupiah::toFloat($get('diskon_tambahan'));

        $nk = max(0, $gross + $bunga - $ds - $dt);
        $dpp = round($nk / 1.11);
        $set('nilai_kontrak', $nk);
        $set('nilai_dpp', $dpp);
        $set('nilai_ppn', $nk - $dpp);
        $set('selisih', $nk - Rupiah::toFloat($get('total_tagihan')));
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

        // Kontrak yang SUDAH SPR (final) → adjustment WAJIB lewat Approval Adjustment
        // (2 level). Perubahan baru diterapkan ke kontrak setelah disetujui (L2).
        if ($k->tahap === 'SPR') {
            $approval = Approval::create([
                'tgl_approval' => now(),
                'jenis' => 'ADJ_HARGA',
                'subject_type' => Kontrak::class,
                'subject_id' => $k->id,
                'amount_dampak' => $nk - (float) $k->total_kontrak,
                'alasan' => 'Adjustment harga kontrak '.$k->no_kontrak,
                'detail_lama' => ['nilai_kontrak' => (float) $k->total_kontrak, 'harga_jual' => (float) $k->harga_jual, 'diskon' => (float) $k->diskon],
                'detail_baru' => ['harga_jual_baru' => $gross, 'total_kontrak_baru' => $nk, 'diskon' => $ds + $dt],
                'status' => 'DRAFT',
                'requested_by' => auth()->id(),
            ]);
            app(ApprovalService::class)->submit($approval);

            Notification::make()->success()
                ->title('Diajukan ke Approval Adjustment — '.$approval->no_approval)
                ->body('Kontrak SPR: adjustment menunggu persetujuan (2 level). Perubahan diterapkan otomatis setelah disetujui.')
                ->persistent()->send();

            $this->backToList();

            return;
        }

        // Kontrak belum SPR (SPRS) → terapkan langsung.
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
