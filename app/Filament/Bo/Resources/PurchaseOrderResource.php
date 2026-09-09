<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bo\Resources\PurchaseOrderResource\Pages;
use App\Modules\BO\PurchaseRequest\Models\PurchaseOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PurchaseOrderResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Purchase Order';

    protected static ?string $pluralModelLabel = 'Purchase Order';

    protected static ?string $recordTitleAttribute = 'no_po';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Header')->columns(3)->schema([
                Forms\Components\TextInput::make('no_po')->label('No PO')->disabled(),
                Forms\Components\DatePicker::make('tgl')->label('Tgl')->disabled(),
                Forms\Components\Select::make('supplier_id')->label('Supplier')->relationship('supplier', 'nama')->searchable()->preload(),
                Forms\Components\Select::make('status')->label('Status')->options([
                    'DRAFT' => 'Draft', 'TERKIRIM' => 'Terkirim', 'SELESAI' => 'Selesai', 'BATAL' => 'Batal',
                ])->required(),
                Forms\Components\TextInput::make('keterangan')->label('Keterangan')->columnSpan(2),
            ]),
            Forms\Components\Section::make('Item')->schema([
                Forms\Components\Repeater::make('lines')->label('')->relationship()->columns(12)->disabled()
                    ->schema([
                        Forms\Components\TextInput::make('deskripsi')->columnSpan(5),
                        Forms\Components\TextInput::make('qty')->columnSpan(2),
                        Forms\Components\TextInput::make('satuan')->columnSpan(2),
                        Forms\Components\TextInput::make('harga')->columnSpan(3),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_po')->label('No PO')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tgl')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('purchaseRequest.no_pr')->label('Dari PR')->toggleable(),
                Tables\Columns\TextColumn::make('supplier.nama')->label('Supplier')->placeholder('—'),
                Tables\Columns\TextColumn::make('total')->label('Total')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (string $state) => match ($state) {
                    'DRAFT' => 'gray', 'TERKIRIM' => 'warning', 'SELESAI' => 'success', 'BATAL' => 'danger', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'TERKIRIM' => 'Terkirim', 'SELESAI' => 'Selesai', 'BATAL' => 'Batal',
                ]),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('tgl', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
