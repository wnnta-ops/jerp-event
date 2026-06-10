<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\TipeTarifResource\Pages;
use App\Modules\FO\Komisi\Models\TipeTarif;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TipeTarifResource extends Resource
{
    protected static ?string $model = TipeTarif::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Setup Komisi';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'tipe-tarif';

    protected static ?string $modelLabel = 'Tipe Tarif';

    protected static ?string $pluralModelLabel = 'Setup Tarif';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dokumen')->columns(2)->schema([
                Forms\Components\TextInput::make('kode')
                    ->label('No. Tipe Biaya')
                    ->default('#AUTO#')->disabled()->dehydrated(false),
                Forms\Components\Select::make('project_id')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()->preload()
                    ->placeholder('Semua proyek'),
                Forms\Components\TextInput::make('nama')
                    ->label('Nama')->required()->maxLength(255)
                    ->placeholder('mis. PPH, BPHTB')->columnSpanFull(),
                Forms\Components\Group::make()->columns(2)->schema([
                    Forms\Components\TextInput::make('nilai_biaya')
                        ->label('Nilai Biaya')->numeric()->required()->default(0),
                    Forms\Components\Radio::make('satuan')
                        ->label('Satuan')->inline()
                        ->options(['RP' => 'Rp', 'PERSEN' => '%'])
                        ->default('PERSEN')->required(),
                ]),
                Forms\Components\TextInput::make('nilai_pengurang')
                    ->label('Nilai Pengurang')->numeric()->default(0)
                    ->helperText('Pengurang basis sebelum tarif diterapkan (mis. NJOPTKP BPHTB). 0 kalau tak ada.'),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('kode')->label('No.')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('nilai_biaya')->label('Nilai')
                ->formatStateUsing(fn ($state, TipeTarif $r) => $r->satuan === 'PERSEN'
                    ? rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%'
                    : 'Rp '.number_format((float) $state, 0, ',', '.'))
                ->alignEnd(),
            Tables\Columns\TextColumn::make('nilai_pengurang')->label('Pengurang')->money('IDR', locale: 'id')->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('project.name')->label('Project')->placeholder('Semua')->toggleable(),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Project'),
            Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('kode');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTipeTarifs::route('/'),
            'create' => Pages\CreateTipeTarif::route('/create'),
            'edit' => Pages\EditTipeTarif::route('/{record}/edit'),
        ];
    }
}
