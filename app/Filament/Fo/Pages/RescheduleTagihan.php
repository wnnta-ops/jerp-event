<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\RescheduleTagihanService;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * Reschedule Tagihan — ganti Skema Bayar kontrak yang sudah SPR (padanan eProperty
 * Kontrak → Reschedule Tagihan). Pilih kontrak → pilih skema baru & tanggal mulai →
 * pratinjau jadwal baru → diajukan ke Approval Reschedule. Jadwal baru baru diterapkan
 * setelah disetujui (lihat RescheduleTagihanService untuk aturannya).
 */
class RescheduleTagihan extends Page implements HasForms, HasTable
{
    use MematuhiIzinHalaman;

    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $title = 'Reschedule Tagihan';

    protected static ?string $navigationLabel = 'Reschedule Tagihan';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'reschedule-tagihan';

    protected static string $view = 'filament.fo.pages.reschedule-tagihan';

    public ?array $data = [];

    // Locked: kontrak yang diajukan hanya boleh berasal dari pilihan di server (selectKontrak).
    #[Locked]
    public ?int $selectedKontrakId = null;

    /** Jadwal baru hasil pratinjau (null = belum dihitung). */
    public ?array $pratinjau = null;

    public ?string $pratinjauError = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Kontrak::query()->with(['unit', 'customer', 'agent', 'skema'])->where('tahap', 'SPR'))
            ->heading('Daftar Kontrak (SPR)')
            ->description('Pilih kontrak untuk mengganti Skema Bayar — pengajuan masuk ke Approval Reschedule.')
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('tgl_kontrak')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer / Sales')
                    ->description(fn ($record) => $record->agent?->name)
                    ->searchable(),
                Tables\Columns\TextColumn::make('skema.name')->label('Skema Bayar')->placeholder('—')->wrap(),
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
                Tables\Actions\Action::make('reschedule')->label('Reschedule')->icon('heroicon-o-calendar-days')
                    ->button()->color('warning')
                    ->action(fn (Kontrak $record) => $this->selectKontrak($record->id)),
                Tables\Actions\Action::make('riwayat')->label('Riwayat')->icon('heroicon-o-clock')->color('gray')->link()
                    ->modalHeading(fn (Kontrak $record) => 'Riwayat Reschedule — '.$record->no_kontrak)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup')
                    ->modalContent(fn (Kontrak $record) => new HtmlString(AdjustmentKontrak::riwayatApprovalHtml($record, 'RESCHEDULE'))),
            ])
            ->defaultSort('tgl_kontrak', 'desc')
            ->paginated([10, 25, 50]);
    }

    public function selectKontrak(int $id): void
    {
        $k = Kontrak::with(['unit', 'customer', 'agent', 'skema'])->findOrFail($id);
        $this->selectedKontrakId = $id;
        $this->pratinjau = null;
        $this->pratinjauError = null;
        $this->form->fill([
            'kontrak_id' => $id,
            'info_no' => $k->no_kontrak,
            'info_unit' => $k->unit?->kode_unit,
            'info_customer' => $k->customer?->full_name,
            'info_sales' => $k->agent?->name,
            'skema_lama' => $k->skema ? trim(($k->skema->code ? $k->skema->code.' — ' : '').$k->skema->name) : '—',
            'tgl_mulai' => now()->toDateString(),
        ]);
    }

    public function backToList(): void
    {
        $this->selectedKontrakId = null;
        $this->pratinjau = null;
        $this->pratinjauError = null;
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        $info = fn (string $key, string $label) => Forms\Components\Placeholder::make($key.'_v')->label($label)
            ->content(fn (Forms\Get $get) => $get($key) ?: '—');

        return $form->statePath('data')->schema([
            Forms\Components\Hidden::make('kontrak_id'),
            Forms\Components\Section::make('Kontrak')->columns(5)->schema([
                $info('info_no', 'No. Kontrak'),
                $info('info_unit', 'Unit'),
                $info('info_customer', 'Customer'),
                $info('info_sales', 'Sales'),
                $info('skema_lama', 'Skema Saat Ini'),
            ]),
            Forms\Components\Section::make('Skema Baru')->columns(3)->schema([
                Forms\Components\Select::make('skema_id')->label('Skema Bayar Baru')->columnSpan(2)
                    ->options(fn () => Skema::query()->where('is_active', true)->orderBy('name')->get()
                        ->mapWithKeys(fn (Skema $s) => [$s->id => trim(($s->code ? $s->code.' — ' : '').$s->name)]))
                    ->searchable()->required()->live()
                    ->afterStateUpdated(fn () => $this->hitungPratinjau()),
                Forms\Components\DatePicker::make('tgl_mulai')->label('Tgl. Mulai Jadwal')->native(false)->displayFormat('d M Y')
                    ->required()->live()
                    ->helperText('Jatuh tempo termin dihitung dari tanggal ini.')
                    ->afterStateUpdated(fn () => $this->hitungPratinjau()),
                Forms\Components\Textarea::make('alasan')->label('Alasan')->rows(2)->required()->columnSpanFull(),
            ]),
        ]);
    }

    public function hitungPratinjau(): void
    {
        $this->pratinjau = null;
        $this->pratinjauError = null;
        $skemaId = (int) ($this->data['skema_id'] ?? 0);
        $tgl = $this->data['tgl_mulai'] ?? null;
        if (! $this->selectedKontrakId || ! $skemaId || ! $tgl) {
            return;
        }

        try {
            $this->pratinjau = app(RescheduleTagihanService::class)
                ->pratinjau(Kontrak::findOrFail($this->selectedKontrakId), $skemaId, Carbon::parse($tgl));
        } catch (\RuntimeException $e) {
            $this->pratinjauError = $e->getMessage();
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function getJadwalLamaProperty(): array
    {
        if (! $this->selectedKontrakId) {
            return [];
        }

        return Kontrak::findOrFail($this->selectedKontrakId)->tagihan()->orderBy('urutan')->orderBy('id')->get()
            ->map(fn ($t) => RescheduleTagihanService::baris($t))->all();
    }

    /** Total termin non-ADM pratinjau − Nilai Kontrak (≠ 0 berarti skema menghasilkan jadwal tak pas). */
    public function getSelisihPratinjauProperty(): float
    {
        if (! $this->pratinjau || ! $this->selectedKontrakId) {
            return 0.0;
        }
        $termin = array_sum(array_column(array_filter($this->pratinjau, fn ($r) => $r['tipe'] !== 'ADM'), 'nilai'));

        return round($termin - (float) Kontrak::findOrFail($this->selectedKontrakId)->total_kontrak, 2);
    }

    public function submit(): void
    {
        $d = $this->form->getState();
        $k = Kontrak::with('skema')->findOrFail($this->selectedKontrakId);

        $proses = Approval::where('subject_type', Kontrak::class)->where('subject_id', $k->id)
            ->whereIn('status', ['DRAFT', 'PENDING', 'APPROVED'])->first();
        if ($proses) {
            Notification::make()->danger()->title('Kontrak masih dalam proses approval')
                ->body('Selesaikan dulu '.$proses->no_approval.' ('.$proses->jenis.').')->send();

            return;
        }

        $this->hitungPratinjau();
        if ($this->pratinjauError !== null || $this->pratinjau === null) {
            Notification::make()->danger()->title('Jadwal baru tidak bisa dibentuk')->body($this->pratinjauError)->send();

            return;
        }

        $skemaBaru = Skema::findOrFail($d['skema_id']);
        $approval = Approval::create([
            'tgl_approval' => now(),
            'jenis' => 'RESCHEDULE',
            'subject_type' => Kontrak::class,
            'subject_id' => $k->id,
            'amount_dampak' => 0,
            'alasan' => $d['alasan'],
            'detail_lama' => [
                'skema' => $k->skema?->name ?? '—',
                'jadwal' => $this->jadwalLama,
            ],
            'detail_baru' => [
                'skema' => $skemaBaru->name,
                'skema_id' => $skemaBaru->id,
                'tgl_mulai' => Carbon::parse($d['tgl_mulai'])->toDateString(),
                'jadwal' => $this->pratinjau,
            ],
            'status' => 'DRAFT',
            'requested_by' => auth()->id(),
        ]);
        app(ApprovalService::class)->submit($approval);

        Notification::make()->success()
            ->title('Diajukan ke Approval Reschedule — '.$approval->no_approval)
            ->body('Jadwal tagihan baru diterapkan otomatis setelah disetujui.')
            ->persistent()->send();

        $this->backToList();
    }

    /** Tabel HTML jadwal tagihan (dipakai halaman ini & detail Approval Reschedule). */
    public static function jadwalHtml(array $rows): string
    {
        if (empty($rows)) {
            return '<div style="color:#94a3b8;font-size:13px;">—</div>';
        }
        $rp = fn ($v) => number_format((float) $v, 0, ',', '.');
        $body = '';
        foreach (array_values($rows) as $i => $r) {
            $tgl = ! empty($r['jatuh_tempo']) ? Carbon::parse($r['jatuh_tempo'])->format('d M Y') : '—';
            $body .= '<tr style="border-bottom:1px solid rgba(148,163,184,.2);">'
                .'<td style="padding:4px 8px;">'.($i + 1).'</td>'
                .'<td style="padding:4px 8px;">'.e($r['tipe'] ?? '').'</td>'
                .'<td style="padding:4px 8px;">'.e($r['nama_tagihan'] ?? '').'</td>'
                .'<td style="padding:4px 8px;white-space:nowrap;">'.$tgl.'</td>'
                .'<td style="padding:4px 8px;text-align:right;">'.$rp($r['nilai'] ?? 0).'</td>'
                .'<td style="padding:4px 8px;text-align:right;">'.$rp($r['pelunasan'] ?? 0).'</td>'
                .'</tr>';
        }
        $total = array_sum(array_column($rows, 'nilai'));
        $bayar = array_sum(array_column($rows, 'pelunasan'));

        return '<div style="overflow-x:auto;"><table style="width:100%;font-size:13px;border-collapse:collapse;">'
            .'<thead><tr style="text-align:left;color:#94a3b8;border-bottom:1px solid #334155;">'
            .'<th style="padding:4px 8px;">No</th><th style="padding:4px 8px;">Tipe</th><th style="padding:4px 8px;">Nama Tagihan</th>'
            .'<th style="padding:4px 8px;">Jatuh Tempo</th><th style="padding:4px 8px;text-align:right;">Nilai</th>'
            .'<th style="padding:4px 8px;text-align:right;">Pelunasan</th></tr></thead>'
            .'<tbody>'.$body.'</tbody>'
            .'<tfoot><tr style="font-weight:700;"><td colspan="4" style="padding:6px 8px;">Total</td>'
            .'<td style="padding:6px 8px;text-align:right;">'.$rp($total).'</td>'
            .'<td style="padding:6px 8px;text-align:right;">'.$rp($bayar).'</td></tr></tfoot>'
            .'</table></div>';
    }
}
