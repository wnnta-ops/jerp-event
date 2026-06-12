<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Bo\Resources\IvtMovementResource\Pages;
use App\Modules\BO\Inventory\Models\IvtMovement;
use App\Modules\BO\Inventory\Services\InventoryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IvtMovementResource extends Resource
{
    protected static ?string $model = IvtMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-up-down';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Movement';

    protected static ?string $pluralModelLabel = 'Pergerakan Stok';

    protected static ?string $recordTitleAttribute = 'no_movement';

    /** @return array<string,string> */
    public static function jenisOptions(): array
    {
        return [
            'BBB' => 'BBB — Barang Masuk (Beli)',
            'BKB' => 'BKB — Barang Keluar',
            'BPB' => 'BPB — Barang Pindah/Terima',
            'FPB' => 'FPB — Form Permintaan Barang',
            'ADJ' => 'ADJ — Adjustment',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\Select::make('jenis')->label('Jenis')->options(static::jenisOptions())->required()
                    ->live()->disabled(fn (?IvtMovement $r) => $r !== null),
                Forms\Components\TextInput::make('no_movement')->label('No')->disabled()->placeholder('Otomatis'),
                Forms\Components\DatePicker::make('tgl')->label('Tgl')->required()->default(now()),
                Forms\Components\Select::make('item_id')->label('Item')->relationship('item', 'nama')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->kode.' — '.$record->nama)->searchable()->preload()->required(),
                Forms\Components\Select::make('lokasi_id')->label('Lokasi')->relationship('lokasi', 'nama')->searchable()->preload(),
                Forms\Components\Select::make('arah')->label('Arah')->options(['MASUK' => 'Masuk', 'KELUAR' => 'Keluar'])
                    ->required()
                    ->default(fn (Forms\Get $get) => $get('jenis') ? IvtMovement::arahFor($get('jenis')) : 'MASUK'),
                Forms\Components\TextInput::make('qty')->label('Qty')->numeric()->required()->minValue(0.01),
                Forms\Components\TextInput::make('nilai')->label('Nilai')->numeric()->rupiah()->default(0),
                Forms\Components\TextInput::make('ref_dokumen')->label('Ref dokumen')->maxLength(64),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ])->disabled(fn (?IvtMovement $r) => $r !== null && $r->status !== 'DRAFT'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_movement')->label('No')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge(),
                Tables\Columns\TextColumn::make('tgl')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('item.nama')->label('Item')->searchable()->limit(24),
                Tables\Columns\TextColumn::make('arah')->label('Arah')->badge()
                    ->color(fn (string $state) => $state === 'MASUK' ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('qty')->label('Qty')->numeric(2)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (string $state) => match ($state) {
                    'DRAFT' => 'gray', 'APPROVED' => 'warning', 'POSTED' => 'success', 'BATAL' => 'danger', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')->options(static::jenisOptions()),
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'APPROVED' => 'Approved', 'POSTED' => 'Posted', 'BATAL' => 'Batal',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (IvtMovement $r) => $r->status === 'DRAFT'),
                Tables\Actions\Action::make('approve')->label('Approve')->icon('heroicon-o-check')->color('warning')
                    ->visible(fn (IvtMovement $r) => $r->jenis === 'FPB' && $r->status === 'DRAFT')->requiresConfirmation()
                    ->action(fn (IvtMovement $r) => app(InventoryService::class)->approve($r)),
                Tables\Actions\Action::make('post')->label('Posting')->icon('heroicon-o-lock-closed')->color('success')
                    ->visible(fn (IvtMovement $r) => ! $r->applied && in_array($r->status, ['DRAFT', 'APPROVED'], true)
                        && ! ($r->jenis === 'FPB' && $r->status === 'DRAFT'))
                    ->requiresConfirmation()
                    ->action(fn (IvtMovement $r) => app(InventoryService::class)->post($r)),
                Tables\Actions\Action::make('void')->label('Void')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (IvtMovement $r) => $r->status !== 'BATAL')->requiresConfirmation()
                    ->action(fn (IvtMovement $r) => app(InventoryService::class)->void($r)),
            ])
            ->defaultSort('tgl', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIvtMovements::route('/'),
            'create' => Pages\CreateIvtMovement::route('/create'),
            'edit' => Pages\EditIvtMovement::route('/{record}/edit'),
        ];
    }
}
