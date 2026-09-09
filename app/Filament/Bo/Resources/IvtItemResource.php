<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bo\Resources\IvtItemResource\Pages;
use App\Modules\BO\Inventory\Models\IvtItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IvtItemResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = IvtItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Item';

    protected static ?string $pluralModelLabel = 'Item Inventory';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\TextInput::make('kode')->label('Kode')->required()->maxLength(32),
                Forms\Components\TextInput::make('nama')->label('Nama')->required()->columnSpan(2),
                Forms\Components\Select::make('kategori_id')->label('Kategori')->relationship('kategori', 'nama')->searchable()->preload(),
                Forms\Components\TextInput::make('satuan')->label('Satuan')->default('PCS')->maxLength(16),
                Forms\Components\TextInput::make('harga_pokok')->label('Harga pokok')->numeric()->rupiah()->default(0),
                Forms\Components\TextInput::make('stok')->label('Stok awal')->numeric()->default(0)
                    ->disabled(fn (?IvtItem $r) => $r !== null)->helperText('Perubahan stok via movement.'),
                Forms\Components\TextInput::make('min_stok')->label('Stok minimum')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kode')->label('Kode')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable(),
                Tables\Columns\TextColumn::make('kategori.nama')->label('Kategori')->toggleable(),
                Tables\Columns\TextColumn::make('satuan')->label('Sat'),
                Tables\Columns\TextColumn::make('stok')->label('Stok')->numeric(2)->alignEnd()
                    ->color(fn (IvtItem $r) => $r->isBelowMinimum() ? 'danger' : 'success')
                    ->description(fn (IvtItem $r) => $r->isBelowMinimum() ? 'di bawah minimum' : null),
                Tables\Columns\TextColumn::make('harga_pokok')->label('HPP')->money('IDR', divideBy: 0)->alignEnd()->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kategori_id')->relationship('kategori', 'nama')->label('Kategori'),
                Tables\Filters\Filter::make('below_min')->label('Di bawah minimum')
                    ->query(fn ($query) => $query->whereColumn('stok', '<', 'min_stok')),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->defaultSort('kode');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIvtItems::route('/'),
            'create' => Pages\CreateIvtItem::route('/create'),
            'edit' => Pages\EditIvtItem::route('/{record}/edit'),
        ];
    }
}
