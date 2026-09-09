<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\SalesTypeResource\Pages;
use App\Modules\FO\Adminjual\Models\SalesType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SalesTypeResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = SalesType::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Tipe Sales';

    protected static ?string $pluralModelLabel = 'Tipe Sales';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tipe Sales')->columns(2)->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Kode')
                    ->maxLength(32)->required()->unique(ignoreRecord: true)
                    ->helperText('Pendek, unique. Contoh: INH, MAX, NRB, ARB.'),
                Forms\Components\Select::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()->preload()
                    ->placeholder('Semua proyek')
                    ->helperText('Kosongkan kalau berlaku untuk semua proyek.'),
                Forms\Components\TextInput::make('name')
                    ->label('Nama Tipe')->required()->maxLength(255)
                    ->placeholder('mis. INHOUSE, MAXIMA, AGENT NON AREBI, AGENT AREBI'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('Kode')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name')->label('Nama Tipe')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('project.name')->label('Project')->placeholder('Semua')->toggleable(),
            Tables\Columns\TextColumn::make('levels_count')->counts('levels')->label('# Level')->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('agents_count')->counts('agents')->label('# Sales')->alignEnd()->toggleable(),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Project'),
            Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSalesTypes::route('/'),
            'create' => Pages\CreateSalesType::route('/create'),
            'edit' => Pages\EditSalesType::route('/{record}/edit'),
        ];
    }
}
