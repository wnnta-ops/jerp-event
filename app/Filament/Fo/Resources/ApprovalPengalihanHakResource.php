<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\ApprovalPengalihanHakResource\Pages;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalPengalihanHakResource extends ApprovalResource
{
    protected static ?string $jenisScope = 'GANTI_NAMA';

    protected static ?string $slug = 'approval-pengalihan-hak';

    protected static ?string $navigationLabel = 'Approval Pengalihan Hak';

    protected static ?string $modelLabel = 'Approval Pengalihan Hak';

    protected static ?string $pluralModelLabel = 'Approval Pengalihan Hak';

    /** Eager-load subject Kontrak beserta SPR (reservasi), customer, & unit — cegah N+1 di tabel. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['subject' => function (MorphTo $morphTo) {
            $morphTo->morphWith([
                Kontrak::class => ['reservasi', 'customer', 'unit'],
            ]);
        }]);
    }

    /** Subject sebagai Kontrak (null bila bukan). */
    protected static function kontrakOf(Approval $r): ?Kontrak
    {
        return $r->subject instanceof Kontrak ? $r->subject : null;
    }

    /**
     * Format tabel mengikuti "FORMAT APPROVAL":
     * NO. SPR · TANGGAL SPR · NAMA USER · NO. UNIT · STATUS · DETAIL.
     */
    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('no_spr')->label('No. SPR')->badge()->color('primary')
                ->state(fn (Approval $r) => static::kontrakOf($r)?->reservasi?->no_reservasi ?? '—'),

            Tables\Columns\TextColumn::make('tgl_spr')->label('Tanggal SPR')
                ->state(fn (Approval $r) => optional(static::kontrakOf($r)?->reservasi?->tgl_reservasi)->format('d M Y') ?? '—'),

            Tables\Columns\TextColumn::make('nama_user')->label('Nama User')
                ->state(fn (Approval $r) => static::kontrakOf($r)?->customer?->full_name ?? '—')
                ->wrap(),

            Tables\Columns\TextColumn::make('no_unit')->label('No. Unit')
                ->state(fn (Approval $r) => ($u = static::kontrakOf($r)?->unit) ? ($u->kode_unit ?: $u->no_unit ?: '—') : '—'),

            Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (Approval $r) => $r->status === 'PENDING'
                    ? 'PENDING · '.(ApprovalService::isSingleLevel($r->jenis) ? 'GM' : 'L'.$r->current_level)
                    : $r->status)
                ->color(fn (Approval $r) => match ($r->status) {
                    'DRAFT' => 'gray', 'PENDING' => 'warning', 'APPROVED' => 'success',
                    'REJECTED' => 'danger', 'EXECUTED' => 'primary', default => 'gray',
                }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'DRAFT' => 'Draft', 'PENDING' => 'Pending', 'APPROVED' => 'Approved',
                'REJECTED' => 'Rejected', 'EXECUTED' => 'Executed',
            ]),
        ])->headerActions([
            Tables\Actions\CreateAction::make()->label('Ajukan Approval'),
        ])->actions([
            Tables\Actions\ViewAction::make()->label('Detail')->icon('heroicon-o-eye')->color('gray')
                ->modalWidth('5xl')
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
            'index' => Pages\ListApprovalPengalihanHaks::route('/'),
        ];
    }
}
