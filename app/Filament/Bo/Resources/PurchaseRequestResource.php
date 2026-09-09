<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bo\Resources\PurchaseRequestResource\Pages;
use App\Models\Company;
use App\Modules\BO\AccountsPayable\Models\Supplier;
use App\Modules\BO\PurchaseRequest\Models\PurchaseRequest;
use App\Modules\BO\PurchaseRequest\Services\PurchaseRequestService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PurchaseRequestResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = PurchaseRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Purchase Request';

    protected static ?string $pluralModelLabel = 'Purchase Request';

    protected static ?string $recordTitleAttribute = 'no_pr';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Header')->columns(3)->schema([
                Forms\Components\TextInput::make('no_pr')->label('No PR')->disabled()->placeholder('Otomatis'),
                Forms\Components\DatePicker::make('tgl')->label('Tgl')->required()->default(now()),
                Forms\Components\Select::make('company_id')->label('Company')->relationship('company', 'name')
                    ->default(fn () => Company::query()->value('id'))->required(),
                Forms\Components\Select::make('dept_id')->label('Departemen')->relationship('dept', 'nama')->searchable()->preload(),
                Forms\Components\TextInput::make('keperluan')->label('Keperluan')->columnSpan(2),
            ])->disabled(fn (?PurchaseRequest $r) => $r !== null && $r->status !== 'DRAFT'),

            Forms\Components\Section::make('Item')->schema([
                Forms\Components\Repeater::make('lines')->label('')->relationship()->columns(12)->defaultItems(1)
                    ->schema([
                        Forms\Components\TextInput::make('deskripsi')->label('Deskripsi')->required()->columnSpan(5),
                        Forms\Components\TextInput::make('qty')->label('Qty')->numeric()->default(1)->columnSpan(2),
                        Forms\Components\TextInput::make('satuan')->label('Sat')->default('PCS')->columnSpan(2),
                        Forms\Components\TextInput::make('harga_estimasi')->label('Harga est.')->numeric()->default(0)->columnSpan(3),
                        Forms\Components\Hidden::make('urutan')->default(1),
                    ])
                    ->disabled(fn (?PurchaseRequest $r) => $r !== null && $r->status !== 'DRAFT'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_pr')->label('No PR')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tgl')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('dept.nama')->label('Dept')->toggleable(),
                Tables\Columns\TextColumn::make('keperluan')->label('Keperluan')->limit(30),
                Tables\Columns\TextColumn::make('estimasi_total')->label('Estimasi')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (string $state) => match ($state) {
                    'DRAFT' => 'gray', 'APPROVED_1' => 'warning', 'APPROVED' => 'info', 'PO' => 'success', 'REJECTED' => 'danger', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'APPROVED_1' => 'Approved 1', 'APPROVED' => 'Approved', 'PO' => 'Jadi PO', 'REJECTED' => 'Ditolak',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (PurchaseRequest $r) => $r->status === 'DRAFT'),
                Tables\Actions\Action::make('approve')->label('Approve')->icon('heroicon-o-check')->color('warning')
                    ->visible(fn (PurchaseRequest $r) => in_array($r->status, ['DRAFT', 'APPROVED_1'], true))->requiresConfirmation()
                    ->action(fn (PurchaseRequest $r) => app(PurchaseRequestService::class)->approve($r)),
                Tables\Actions\Action::make('reject')->label('Tolak')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (PurchaseRequest $r) => in_array($r->status, ['DRAFT', 'APPROVED_1', 'APPROVED'], true))
                    ->form([Forms\Components\TextInput::make('reason')->label('Alasan')->required()])
                    ->action(fn (PurchaseRequest $r, array $data) => app(PurchaseRequestService::class)->reject($r, $data['reason'])),
                Tables\Actions\Action::make('toPo')->label('Jadikan PO')->icon('heroicon-o-arrow-right-circle')->color('success')
                    ->visible(fn (PurchaseRequest $r) => $r->status === 'APPROVED')
                    ->form([Forms\Components\Select::make('supplier_id')->label('Supplier')
                        ->options(fn () => Supplier::query()->pluck('nama', 'id'))
                        ->searchable()])
                    ->action(fn (PurchaseRequest $r, array $data) => app(PurchaseRequestService::class)->convertToPo($r, $data['supplier_id'] ?? null)),
            ])
            ->defaultSort('tgl', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseRequests::route('/'),
            'create' => Pages\CreatePurchaseRequest::route('/create'),
            'edit' => Pages\EditPurchaseRequest::route('/{record}/edit'),
        ];
    }
}
