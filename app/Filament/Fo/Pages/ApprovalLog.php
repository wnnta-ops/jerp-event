<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Approval\Models\Approval;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Log File — jejak semua approval (jenis, status, siapa, kapan). Read-only. */
class ApprovalLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'approval-log';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Approval';

    protected static string $view = 'filament.fo.pages.approval-log';

    protected static ?string $title = 'Log File Approval';

    public function table(Table $table): Table
    {
        return $table
            ->query(Approval::query())
            ->columns([
                Tables\Columns\TextColumn::make('no_approval')->label('No')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (Approval $r) => $r->status === 'PENDING' ? 'PENDING · L'.$r->current_level : $r->status)
                    ->color(fn (Approval $r) => match ($r->status) {
                        'DRAFT' => 'gray', 'PENDING' => 'warning', 'APPROVED' => 'success',
                        'REJECTED' => 'danger', 'EXECUTED' => 'primary', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('requester.name')->label('Pemohon')->placeholder('—'),
                Tables\Columns\TextColumn::make('level1Approver.name')->label('Approve L1')->placeholder('—')
                    ->description(fn (Approval $r) => $r->level1_at?->format('d M Y H:i')),
                Tables\Columns\TextColumn::make('approver.name')->label('Approve L2')->placeholder('—')
                    ->description(fn (Approval $r) => $r->approved_at?->format('d M Y H:i')),
                Tables\Columns\TextColumn::make('executed_at')->label('Dieksekusi')->dateTime('d M Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('executed_result')->label('Hasil')->limit(40)->placeholder('—')->wrap(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')->options([
                    'DISKON' => 'Diskon', 'BATAL_KONTRAK' => 'Batal kontrak', 'ADJ_HARGA' => 'Adj harga',
                    'RESCHEDULE' => 'Reschedule', 'GANTI_NAMA' => 'Ganti nama', 'GANTI_UNIT' => 'Ganti unit',
                    'REFUND' => 'Refund', 'LAIN' => 'Lain',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'PENDING' => 'Pending', 'APPROVED' => 'Approved',
                    'REJECTED' => 'Rejected', 'EXECUTED' => 'Executed',
                ]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginated([25, 50, 100]);
    }
}
