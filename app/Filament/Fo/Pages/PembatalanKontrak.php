<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
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
 * Pembatalan Kontrak — daftar kontrak SPR; ajukan pembatalan (alasan + pelunasan/
 * pengembalian). Pengajuan masuk ke Approval Pembatalan (BATAL_KONTRAK); kontrak baru
 * BATAL setelah disetujui (unit dikembalikan TERSEDIA).
 */
class PembatalanKontrak extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-x-circle';

    protected static ?string $title = 'Pembatalan Kontrak';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'pembatalan-kontrak';

    protected static string $view = 'filament.fo.pages.pembatalan-kontrak';

    public function table(Table $table): Table
    {
        return $table
            ->query(Kontrak::query()->with(['unit', 'customer', 'agent'])->where('tahap', 'SPR')->where('status', 'AKTIF'))
            ->heading('Daftar Kontrak (SPR)')
            ->description('Hanya kontrak SPR (final). Ajukan pembatalan — pengajuan masuk ke Approval Pembatalan.')
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('agent.name')->label('Sales')->placeholder('—'),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Nilai Kontrak')->money('IDR', divideBy: 0)->alignEnd(),
            ])
            ->actions([
                Tables\Actions\Action::make('ajukan')->label('Ajukan Pembatalan')->icon('heroicon-o-x-circle')->color('danger')->button()
                    ->modalHeading('Pengajuan Pembatalan Kontrak')
                    ->modalDescription(fn (Kontrak $record) => 'Status kontrak '.$record->no_kontrak.' akan menjadi BATAL setelah disetujui.')
                    ->modalWidth('xl')
                    ->modalSubmitActionLabel('Ajukan ke Approval')
                    ->form([
                        Forms\Components\Radio::make('alasan')->label('Alasan Pembatalan')->required()->live()
                            ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                // Reject Bank / Kol 5 / Tutup Buku → UTJ otomatis kembali.
                                // Batal Sepihak → 0 (UTJ hangus). Lainnya → diisi manual.
                                if (in_array($state, ['REJECT_BANK', 'KOL_5', 'TUTUP_BUKU'], true)) {
                                    $set('refund_utj', (float) $get('utj_amount'));
                                } elseif ($state === 'BATAL_SEPIHAK') {
                                    $set('refund_utj', 0);
                                }
                            })
                            ->options([
                                'BATAL_SEPIHAK' => 'Batal Sepihak',
                                'REJECT_BANK' => 'Reject Bank',
                                'KOL_5' => 'Kol 5',
                                'TUTUP_BUKU' => 'Tutup Buku',
                                'LAINNYA' => 'Lainnya',
                            ]),
                        Forms\Components\Textarea::make('alasan_lainnya')->label('Alasan Lainnya')->rows(2)
                            ->visible(fn (Forms\Get $get) => $get('alasan') === 'LAINNYA')
                            ->required(fn (Forms\Get $get) => $get('alasan') === 'LAINNYA'),
                        Forms\Components\DatePicker::make('tgl_pembatalan')->label('Tgl Pembatalan')->default(now())->required(),
                        Forms\Components\Hidden::make('utj_amount')->default(fn (Kontrak $record) => (float) ($record->reservasi?->booking_fee ?? 0)),
                        Forms\Components\DatePicker::make('tgl_refund')->label('Tgl Refund'),
                        Forms\Components\TextInput::make('refund_utj')->label('Refund (UTJ)')->numeric()->rupiah()->default(0)
                            ->readOnly(fn (Forms\Get $get) => $get('alasan') !== 'LAINNYA')
                            ->helperText('Batal Sepihak: 0 (UTJ hangus). Reject Bank / Kol 5 / Tutup Buku: UTJ otomatis. Lainnya: isi manual.'),
                    ])
                    ->action(function (Kontrak $record, array $data) {
                        $alasanLabel = $data['alasan'] === 'LAINNYA'
                            ? ($data['alasan_lainnya'] ?? 'Lainnya')
                            : str_replace('_', ' ', $data['alasan']);

                        $approval = Approval::create([
                            'tgl_approval' => now(),
                            'jenis' => 'BATAL_KONTRAK',
                            'subject_type' => Kontrak::class,
                            'subject_id' => $record->id,
                            'amount_dampak' => (float) ($data['refund_utj'] ?? 0),
                            'alasan' => 'Pembatalan: '.$alasanLabel,
                            'detail_lama' => ['status' => $record->status, 'nilai_kontrak' => (float) $record->total_kontrak],
                            'detail_baru' => [
                                'status_baru' => 'BATAL',
                                'alasan' => $data['alasan'],
                                'alasan_text' => $alasanLabel,
                                'tgl_pembatalan' => $data['tgl_pembatalan'] ?? null,
                                'tgl_pengembalian' => $data['tgl_refund'] ?? null,
                                'total_pengembalian' => (float) ($data['refund_utj'] ?? 0),
                            ],
                            'status' => 'DRAFT',
                            'requested_by' => auth()->id(),
                        ]);
                        app(ApprovalService::class)->submit($approval);

                        Notification::make()->success()
                            ->title('Diajukan ke Approval Pembatalan — '.$approval->no_approval)
                            ->body('Kontrak '.$record->no_kontrak.' menunggu persetujuan (2 level). BATAL setelah disetujui.')
                            ->persistent()->send();
                    }),
            ])
            ->defaultSort('tgl_kontrak', 'desc')
            ->paginated([10, 25, 50]);
    }
}
