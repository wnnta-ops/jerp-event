<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\GimmickResource\Pages;
use App\Modules\FO\Adminjual\Models\Gimmick;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GimmickResource extends Resource
{
    protected static ?string $model = Gimmick::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Master Jual';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Gimmick';

    protected static ?string $pluralModelLabel = 'Gimmick / Promo';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Gimmick')->columns(2)->schema([
                Forms\Components\TextInput::make('code')->label('Kode')->required()->maxLength(32)->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('name')->label('Nama')->required()->maxLength(255),
                Forms\Components\Select::make('project_id')->label('Proyek')->relationship('project', 'name')->searchable()->preload(),
                Forms\Components\Select::make('jenis')->label('Jenis')->options([
                    'BARANG' => 'Barang (AC, kulkas, dll.)',
                    'JASA' => 'Jasa',
                    'CASHBACK' => 'Cashback',
                    'DISKON' => 'Diskon',
                    'LAIN' => 'Lain',
                ])->default('BARANG')->required(),
                Forms\Components\TextInput::make('nilai')->label('Nilai (Rp)')->numeric()->prefix('Rp')->default(0),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                Forms\Components\DatePicker::make('berlaku_mulai')->label('Berlaku mulai'),
                Forms\Components\DatePicker::make('berlaku_sampai')->label('Berlaku sampai'),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('Kode')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
            Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge(),
            Tables\Columns\TextColumn::make('nilai')->label('Nilai')->money('IDR', divideBy: 0)->alignEnd(),
            Tables\Columns\TextColumn::make('project.code')->label('Proyek')->toggleable(),
            Tables\Columns\TextColumn::make('berlaku_mulai')->label('Mulai')->date('d M Y')->toggleable(),
            Tables\Columns\TextColumn::make('berlaku_sampai')->label('Sampai')->date('d M Y')->toggleable(),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('jenis')->options([
                'BARANG' => 'Barang', 'JASA' => 'Jasa', 'CASHBACK' => 'Cashback', 'DISKON' => 'Diskon', 'LAIN' => 'Lain',
            ]),
            Tables\Filters\TernaryFilter::make('is_active'),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGimmicks::route('/'),
            'create' => Pages\CreateGimmick::route('/create'),
            'edit' => Pages\EditGimmick::route('/{record}/edit'),
        ];
    }
}
