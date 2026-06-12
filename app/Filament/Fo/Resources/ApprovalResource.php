<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ApprovalResource\Pages;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ApprovalResource extends Resource
{
    protected static ?string $model = Approval::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Approval';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Approval';

    protected static ?string $pluralModelLabel = 'Approval';

    /** Sub-resource per jenis meng-override ini (mis. 'DISKON') untuk membatasi & default. */
    protected static ?string $jenisScope = null;

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery();

        return static::$jenisScope ? $q->where('jenis', static::$jenisScope) : $q;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Approval')->columns(3)->schema([
                Forms\Components\TextInput::make('no_approval')->label('No approval')->disabled()->dehydrated()->placeholder('Otomatis'),
                Forms\Components\DatePicker::make('tgl_approval')->label('Tanggal')->default(now())->required(),
                Forms\Components\Select::make('jenis')->label('Jenis')->options([
                    'DISKON' => 'Diskon harga',
                    'BATAL_KONTRAK' => 'Batal kontrak',
                    'ADJ_HARGA' => 'Adjust harga',
                    'RESCHEDULE' => 'Reschedule angsuran',
                    'GANTI_NAMA' => 'Ganti nama (pengalihan hak)',
                    'GANTI_UNIT' => 'Ganti unit (pindah unit)',
                    'REFUND' => 'Refund',
                    'LAIN' => 'Lain (customize)',
                ])->default(static::$jenisScope ?? 'DISKON')
                    ->disabled(fn () => filled(static::$jenisScope))->dehydrated()->required(),
            ]),

            Forms\Components\Section::make('Subject (objek yang dimohon)')->columns(2)->schema([
                Forms\Components\Select::make('subject_type')->label('Tipe objek')->options([
                    'App\\Modules\\FO\\MarketingSales\\Models\\Kontrak' => 'Kontrak',
                    'App\\Modules\\FO\\MarketingSales\\Models\\Reservasi' => 'Reservasi',
                    'App\\Modules\\FO\\FinanceAR\\Models\\Tts' => 'TTS',
                ]),
                Forms\Components\TextInput::make('subject_id')->label('ID objek')->numeric(),
                Forms\Components\TextInput::make('amount_dampak')->label('Dampak (Rp)')->numeric()->rupiah(),
            ]),

            Forms\Components\Section::make('Detail Permohonan')->schema([
                Forms\Components\Textarea::make('alasan')->label('Alasan')->rows(3)->required(),
                Forms\Components\KeyValue::make('detail_lama')->label('Detail sebelum')
                    ->keyLabel('Field')->valueLabel('Nilai sebelum'),
                Forms\Components\KeyValue::make('detail_baru')->label('Detail diminta')
                    ->keyLabel('Field')->valueLabel('Nilai diminta'),
            ])->collapsible(),

            Forms\Components\Section::make('Status & Approver')->columns(2)->schema([
                Forms\Components\Select::make('status')->label('Status')->options([
                    'DRAFT' => 'Draft', 'PENDING' => 'Pending', 'APPROVED' => 'Approved',
                    'REJECTED' => 'Rejected', 'EXECUTED' => 'Executed',
                ])->default('DRAFT')->required(),
                Forms\Components\Select::make('requested_by')->label('Diminta oleh')->relationship('requester', 'name')->searchable()->preload(),
                Forms\Components\Select::make('approved_by')->label('Di-approve oleh')->relationship('approver', 'name')->searchable()->preload(),
                Forms\Components\DateTimePicker::make('approved_at')->label('Tgl approve'),
                Forms\Components\Textarea::make('catatan_approver')->label('Catatan approver')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Permohonan')->columns(3)->schema([
                Infolists\Components\TextEntry::make('no_approval')->label('No')->badge()->color('primary'),
                Infolists\Components\TextEntry::make('jenis')->label('Jenis')->badge(),
                Infolists\Components\TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (Approval $r) => $r->status === 'PENDING' ? 'PENDING · L'.$r->current_level : $r->status)
                    ->color(fn (Approval $r) => match ($r->status) {
                        'DRAFT' => 'gray', 'PENDING' => 'warning', 'APPROVED' => 'success',
                        'REJECTED' => 'danger', 'EXECUTED' => 'primary', default => 'gray',
                    }),
                Infolists\Components\TextEntry::make('tgl_approval')->label('Tanggal')->date('d M Y'),
                Infolists\Components\TextEntry::make('requester.name')->label('Pemohon')->placeholder('—'),
                Infolists\Components\TextEntry::make('amount_dampak')->label('Dampak')->money('IDR'),
                Infolists\Components\TextEntry::make('objek')->label('Objek')->state(fn (Approval $r) => self::subjectLabel($r))->columnSpanFull(),
                Infolists\Components\TextEntry::make('alasan')->label('Alasan')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Detail Perubahan yang Diminta')
                ->description('Bandingkan kondisi sebelum vs yang diminta sebelum menyetujui.')
                ->schema([
                    Infolists\Components\TextEntry::make('perubahan')->hiddenLabel()
                        ->state(fn (Approval $r) => self::renderPerubahan($r))->html()->columnSpanFull(),
                ]),
            Infolists\Components\Section::make('Catatan & Hasil')->columns(2)->schema([
                Infolists\Components\TextEntry::make('level1_note')->label('Catatan Level 1')->placeholder('—'),
                Infolists\Components\TextEntry::make('catatan_approver')->label('Catatan Level 2')->placeholder('—'),
                Infolists\Components\TextEntry::make('executed_result')->label('Hasil Eksekusi')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    /** Label objek (subject) — Kontrak tampil no/customer/unit. */
    public static function subjectLabel(Approval $r): string
    {
        $s = $r->subject;
        if ($s instanceof Kontrak) {
            return trim($s->no_kontrak.' — '.($s->customer?->full_name ?? '').' — '.($s->unit?->kode_unit ?? ''), ' —');
        }

        return $r->subject_type ? class_basename($r->subject_type).' #'.$r->subject_id : '—';
    }

    /** Tabel HTML perbandingan Sebelum vs Diminta (dari detail_lama & detail_baru). */
    public static function renderPerubahan(Approval $r): string
    {
        $label = fn (string $k) => ucwords(trim(str_replace(['_', ' baru', ' id'], [' ', '', ''], $k)));
        $val = fn ($v) => is_numeric($v) ? 'Rp '.number_format((float) $v, 0, ',', '.') : e((string) $v);
        $col = function (?array $arr) use ($label, $val): string {
            if (empty($arr)) {
                return '<em style="color:#94a3b8;">—</em>';
            }
            $rows = '';
            foreach ($arr as $k => $v) {
                $rows .= '<tr><td style="color:#94a3b8;padding:3px 14px 3px 0;">'.$label((string) $k).'</td>'
                    .'<td style="text-align:right;font-weight:600;padding:3px 0;">'.$val($v).'</td></tr>';
            }

            return '<table style="width:100%;border-collapse:collapse;">'.$rows.'</table>';
        };

        return '<div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">'
            .'<div><div style="font-weight:700;color:#ef4444;margin-bottom:6px;">⊖ Sebelum</div>'.$col((array) $r->detail_lama).'</div>'
            .'<div><div style="font-weight:700;color:#22c55e;margin-bottom:6px;">⊕ Diminta</div>'.$col((array) $r->detail_baru).'</div>'
            .'</div>';
    }

    /** @return array<int,Tables\Actions\Action> Aksi alur 2-level (dipakai di baris & modal Detail). */
    protected static function workflowActions(): array
    {
        return [
            Tables\Actions\Action::make('submit')->label('Submit')->icon('heroicon-o-paper-airplane')->color('info')
                ->visible(fn (Approval $r) => $r->status === 'DRAFT')->requiresConfirmation()
                ->action(function (Approval $r) {
                    app(ApprovalService::class)->submit($r);
                    Notification::make()->title('Diajukan — menunggu Approval Level 1')->success()->send();
                }),
            Tables\Actions\Action::make('approve_l1')->label('Approve L1')->icon('heroicon-o-check')->color('warning')
                ->visible(fn (Approval $r) => $r->isAwaitingLevel1())
                ->form([Forms\Components\Textarea::make('note')->label('Catatan Level 1')->rows(2)])
                ->action(function (Approval $r, array $data) {
                    app(ApprovalService::class)->approveLevel1($r, $data['note'] ?? null);
                    Notification::make()->title('Disetujui Level 1 → menunggu Level 2 (final)')->success()->send();
                }),
            Tables\Actions\Action::make('approve_l2')->label('Approve L2 (final)')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Approval $r) => $r->isAwaitingLevel2())
                ->requiresConfirmation()
                ->modalDescription('Persetujuan final akan LANGSUNG menerapkan perubahan ke kontrak.')
                ->form([Forms\Components\Textarea::make('note')->label('Catatan Level 2')->rows(2)])
                ->action(function (Approval $r, array $data) {
                    try {
                        $a = app(ApprovalService::class)->approveLevel2($r, $data['note'] ?? null);
                        Notification::make()->title('Disetujui & dieksekusi')->body($a->executed_result)->success()->persistent()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Gagal eksekusi')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Tables\Actions\Action::make('reject')->label('Reject')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Approval $r) => $r->status === 'PENDING')
                ->form([Forms\Components\Textarea::make('note')->label('Alasan reject')->rows(2)->required()])
                ->action(function (Approval $r, array $data) {
                    app(ApprovalService::class)->reject($r, $data['note']);
                    Notification::make()->title('Ditolak')->warning()->send();
                }),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('no_approval')->label('No')->badge()->color('primary')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('tgl_approval')->label('Tgl')->date('d M Y')->sortable(),
            Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge(),
            Tables\Columns\TextColumn::make('subject_type')
                ->label('Subject')
                ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '—')
                ->toggleable(),
            Tables\Columns\TextColumn::make('subject_id')->label('ID')->toggleable(),
            Tables\Columns\TextColumn::make('amount_dampak')->label('Dampak')->money('IDR', divideBy: 0)->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('requester.full_name')->label('Pemohon')->toggleable(),
            Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (Approval $r) => $r->status === 'PENDING' ? 'PENDING · L'.$r->current_level : $r->status)
                ->color(fn (Approval $r) => match ($r->status) {
                    'DRAFT' => 'gray', 'PENDING' => 'warning', 'APPROVED' => 'success',
                    'REJECTED' => 'danger', 'EXECUTED' => 'primary', default => 'gray',
                })->description(fn (Approval $r) => $r->executed_result ? Str::limit($r->executed_result, 50) : null),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'DRAFT' => 'Draft', 'PENDING' => 'Pending', 'APPROVED' => 'Approved',
                'REJECTED' => 'Rejected', 'EXECUTED' => 'Executed',
            ]),
            Tables\Filters\SelectFilter::make('jenis')->visible(fn () => blank(static::$jenisScope))->options([
                'DISKON' => 'Diskon', 'BATAL_KONTRAK' => 'Batal kontrak', 'ADJ_HARGA' => 'Adj harga',
                'RESCHEDULE' => 'Reschedule', 'GANTI_NAMA' => 'Ganti nama', 'GANTI_UNIT' => 'Ganti unit',
                'REFUND' => 'Refund', 'LAIN' => 'Lain',
            ]),
        ])->headerActions([
            Tables\Actions\CreateAction::make()->label('Ajukan Approval'),
        ])->actions([
            Tables\Actions\ViewAction::make()->label('Detail')->icon('heroicon-o-eye')->color('gray')
                ->extraModalFooterActions(self::workflowActions()),
            ...self::workflowActions(),
            Tables\Actions\EditAction::make()->visible(fn (Approval $r) => $r->status === 'DRAFT'),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('tgl_approval', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovals::route('/'),
        ];
    }
}
