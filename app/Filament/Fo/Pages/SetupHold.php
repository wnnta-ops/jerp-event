<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\Booked\Models\HoldPeriod;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Setup Hold Unit — finance tentukan periode (rentang tanggal) saat tombol Hold
 * aktif di Verifikasi Booked (mis. weekend libur). Maks 2 hari langsung aktif;
 * >2 hari membuat pengajuan Approval (jenis HOLD) dan aktif setelah disetujui.
 */
class SetupHold extends Page implements HasForms, HasTable
{
    use MematuhiIzinHalaman;

    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-pause-circle';

    protected static ?string $title = 'Setup Hold Unit';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'setup-hold';

    protected static string $view = 'filament.fo.pages.setup-hold';

    private const MAX_HARI = 2;

    public function table(Table $table): Table
    {
        // Periode yang sudah lewat jadwal → otomatis non-aktif (SELESAI).
        app(\App\Modules\FO\Booked\Services\HoldService::class)->expirePeriods();

        return $table
            ->query(HoldPeriod::query()->latest('tgl_mulai'))
            ->heading('Periode Hold')
            ->description('Selama periode ACTIVE & mencakup hari ini, tombol "Hold Unit" muncul di Verifikasi Booked. Maks '.self::MAX_HARI.' hari; lebih dari itu butuh Approval.')
            ->columns([
                Tables\Columns\TextColumn::make('tgl_mulai')->label('Mulai')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('tgl_selesai')->label('Selesai')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('durasi_hari')->label('Durasi')->getStateUsing(fn (HoldPeriod $r) => $r->durasi_hari.' hari'),
                Tables\Columns\TextColumn::make('keterangan')->label('Keterangan')->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'ACTIVE' => 'success', 'PENDING' => 'warning', 'REJECTED' => 'danger', default => 'gray',
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('buat')->label('Buat Periode Hold')->icon('heroicon-o-plus')->button()
                    ->modalHeading('Buat Periode Hold')->modalWidth('md')
                    ->form([
                        Forms\Components\DatePicker::make('tgl_mulai')->label('Tgl Mulai')->default(now())->required(),
                        Forms\Components\DatePicker::make('tgl_selesai')->label('Tgl Selesai')->default(now()->addDay())->required(),
                        Forms\Components\Textarea::make('keterangan')->label('Keterangan (mis. Weekend / Libur)')->rows(2),
                        Forms\Components\Placeholder::make('info')->label('')
                            ->content('Maks '.self::MAX_HARI.' hari → langsung aktif. Lebih dari '.self::MAX_HARI.' hari → dibuat pengajuan Approval (jenis HOLD), aktif setelah disetujui.'),
                    ])
                    ->action(fn (array $data) => $this->buatPeriode($data)),
            ])
            ->actions([
                Tables\Actions\Action::make('akhiri')->label('Akhiri')->icon('heroicon-o-stop-circle')->color('gray')
                    ->visible(fn (HoldPeriod $r) => $r->status === 'ACTIVE')->requiresConfirmation()
                    ->action(fn (HoldPeriod $r) => $r->update(['status' => 'SELESAI'])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('tgl_mulai', 'desc');
    }

    private function buatPeriode(array $data): void
    {
        $mulai = Carbon::parse($data['tgl_mulai'])->startOfDay();
        $selesai = Carbon::parse($data['tgl_selesai'])->startOfDay();
        if ($selesai->lt($mulai)) {
            Notification::make()->danger()->title('Tgl selesai harus ≥ tgl mulai')->send();

            return;
        }
        $durasi = (int) $mulai->diffInDays($selesai) + 1;
        $needApproval = $durasi > self::MAX_HARI;

        $period = HoldPeriod::create([
            'tenant_id' => 1,
            'tgl_mulai' => $mulai->toDateString(),
            'tgl_selesai' => $selesai->toDateString(),
            'max_hari' => self::MAX_HARI,
            'keterangan' => $data['keterangan'] ?? null,
            'status' => $needApproval ? 'PENDING' : 'ACTIVE',
            'created_by' => auth()->id(),
        ]);

        if (! $needApproval) {
            Notification::make()->success()->title('Periode hold AKTIF')
                ->body($mulai->format('d M').' – '.$selesai->format('d M').' ('.$durasi.' hari).')->send();

            return;
        }

        $approval = Approval::create([
            'tgl_approval' => now(),
            'jenis' => 'HOLD',
            'subject_type' => HoldPeriod::class,
            'subject_id' => $period->id,
            'amount_dampak' => 0,
            'alasan' => 'Periode Hold '.$mulai->format('d M').' – '.$selesai->format('d M').' ('.$durasi.' hari, > '.self::MAX_HARI.' hari)'.($data['keterangan'] ? ' — '.$data['keterangan'] : ''),
            'detail_lama' => [],
            'detail_baru' => ['tgl_mulai' => $mulai->toDateString(), 'tgl_selesai' => $selesai->toDateString(), 'durasi_hari' => $durasi],
            'status' => 'DRAFT',
            'requested_by' => auth()->id(),
        ]);
        app(ApprovalService::class)->submit($approval);
        $period->update(['approval_id' => $approval->id]);

        Notification::make()->warning()->title('Perlu Approval ('.$durasi.' hari > '.self::MAX_HARI.')')
            ->body('Periode hold menunggu persetujuan — '.$approval->no_approval.'. Aktif setelah disetujui.')
            ->persistent()->send();
    }
}
