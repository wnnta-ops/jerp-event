<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\SalesLevelResource\Pages;
use App\Modules\FO\Adminjual\Models\SalesLevel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SalesLevelResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = SalesLevel::class;

    protected static ?string $navigationIcon = 'heroicon-o-bars-arrow-down';

    protected static ?string $navigationGroup = 'Setup Sales';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Level Sales';

    protected static ?string $pluralModelLabel = 'Level Sales';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Level')->columns(2)->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Kode')->maxLength(32)->required()->unique(ignoreRecord: true)
                    ->helperText('Contoh: SLSNRB, KOORNRB, KANTRA, LEADA, dst.'),
                Forms\Components\Select::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()->preload()
                    ->placeholder('Semua proyek'),
                Forms\Components\TextInput::make('name')
                    ->label('Nama Level')->required()->maxLength(255)
                    ->placeholder('mis. SALES NON AREBI, KOOR NON AREBI'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
            ]),

            Forms\Components\Section::make('Tipe Sales & Hierarki')->columns(2)->schema([
                Forms\Components\Select::make('sales_type_id')
                    ->label('Tipe Sales')
                    ->relationship('salesType', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                    ->searchable()->preload(),
                Forms\Components\Select::make('parent_id')
                    ->label('Atasan (Parent Level)')
                    ->relationship('parent', 'name', fn ($query, $record) => $record
                        ? $query->where('id', '!=', $record->id)   // cegah self-parent
                        : $query)
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                    ->searchable()->preload()
                    ->placeholder('— Tidak ada (level tertinggi)')
                    ->helperText('Contoh: SALES NON AREBI atasan-nya KOOR NON AREBI.'),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('Kode')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name')->label('Nama Level')->searchable()->sortable()->wrap(),
            Tables\Columns\TextColumn::make('salesType.name')->label('Tipe Sales')->badge()->placeholder('—'),
            Tables\Columns\TextColumn::make('parent.name')->label('Atasan')->placeholder('— Top —')->toggleable(),
            Tables\Columns\TextColumn::make('children_count')->counts('children')->label('# Bawahan')->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('agents_count')->counts('agents')->label('# Sales')->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('project.code')->label('Project')->badge()->placeholder('Semua')->toggleable(),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('sales_type_id')->relationship('salesType', 'name')->label('Tipe Sales'),
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
            'index' => Pages\ListSalesLevels::route('/'),
            'create' => Pages\CreateSalesLevel::route('/create'),
            'edit' => Pages\EditSalesLevel::route('/{record}/edit'),
        ];
    }
}
