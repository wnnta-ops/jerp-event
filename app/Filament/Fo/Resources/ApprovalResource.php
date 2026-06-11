<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ApprovalResource\Pages;
use App\Modules\FO\Approval\Models\Approval;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ApprovalResource extends Resource
{
    protected static ?string $model = Approval::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Approval';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Approval';

    protected static ?string $pluralModelLabel = 'Approval';

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
                    'GANTI_NAMA' => 'Ganti nama',
                    'GANTI_UNIT' => 'Ganti unit',
                    'REFUND' => 'Refund',
                    'LAIN' => 'Lain',
                ])->default('DISKON')->required(),
            ]),

            Forms\Components\Section::make('Subject (objek yang dimohon)')->columns(2)->schema([
                Forms\Components\Select::make('subject_type')->label('Tipe objek')->options([
                    'App\\Modules\\FO\\MarketingSales\\Models\\Kontrak' => 'Kontrak',
                    'App\\Modules\\FO\\MarketingSales\\Models\\Reservasi' => 'Reservasi',
                    'App\\Modules\\FO\\FinanceAR\\Models\\Tts' => 'TTS',
                ]),
                Forms\Components\TextInput::make('subject_id')->label('ID objek')->numeric(),
                Forms\Components\TextInput::make('amount_dampak')->label('Dampak (Rp)')->numeric()->prefix('Rp'),
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
            Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                'DRAFT' => 'gray', 'PENDING' => 'warning', 'APPROVED' => 'success',
                'REJECTED' => 'danger', 'EXECUTED' => 'primary', default => 'gray',
            }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'DRAFT' => 'Draft', 'PENDING' => 'Pending', 'APPROVED' => 'Approved',
                'REJECTED' => 'Rejected', 'EXECUTED' => 'Executed',
            ]),
            Tables\Filters\SelectFilter::make('jenis')->options([
                'DISKON' => 'Diskon', 'BATAL_KONTRAK' => 'Batal kontrak', 'ADJ_HARGA' => 'Adj harga',
                'RESCHEDULE' => 'Reschedule', 'GANTI_NAMA' => 'Ganti nama', 'GANTI_UNIT' => 'Ganti unit',
                'REFUND' => 'Refund', 'LAIN' => 'Lain',
            ]),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('submit')
                ->label('Submit')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->visible(fn (Approval $r) => $r->status === 'DRAFT')
                ->action(function (Approval $r) {
                    $r->update(['status' => 'PENDING']);
                    Notification::make()->title('Submitted untuk approval')->success()->send();
                }),
            Tables\Actions\Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (Approval $r) => $r->status === 'PENDING')
                ->form([
                    Forms\Components\Textarea::make('catatan_approver')->label('Catatan')->rows(2),
                ])
                ->action(function (Approval $r, array $data) {
                    $r->update([
                        'status' => 'APPROVED',
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                        'catatan_approver' => $data['catatan_approver'] ?? null,
                    ]);
                    Notification::make()->title('Approved')->success()->send();
                }),
            Tables\Actions\Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Approval $r) => $r->status === 'PENDING')
                ->form([
                    Forms\Components\Textarea::make('catatan_approver')->label('Alasan reject')->rows(2)->required(),
                ])
                ->action(function (Approval $r, array $data) {
                    $r->update([
                        'status' => 'REJECTED',
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                        'catatan_approver' => $data['catatan_approver'],
                    ]);
                    Notification::make()->title('Rejected')->success()->send();
                }),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('tgl_approval', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovals::route('/'),
            'create' => Pages\CreateApproval::route('/create'),
            'edit' => Pages\EditApproval::route('/{record}/edit'),
        ];
    }
}
