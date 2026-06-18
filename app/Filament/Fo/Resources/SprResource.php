<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\SprResource\Pages;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * SPR (Surat Pemesanan Rumah final) — kontrak hasil finalisasi SPRS.
 * Form & tabel sama dengan SPRS (tab Edit/Jadwal Tagihan/Jadwal Komisi/Gimmick),
 * hanya menampilkan kontrak tahap SPR.
 */
class SprResource extends Resource
{
    protected static ?string $model = Kontrak::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'spr';

    protected static ?string $modelLabel = 'SPR';

    protected static ?string $pluralModelLabel = 'SPR';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tahap', 'SPR')->with('skema');
    }

    public static function form(Form $form): Form
    {
        return KontrakResource::form($form);
    }

    public static function table(Table $table): Table
    {
        // Menu SPR → tombol "Cetak SPR".
        return KontrakResource::buildTable($table, 'Cetak SPR');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSprs::route('/'),
            'edit' => Pages\EditSpr::route('/{record}/edit'),
        ];
    }
}
