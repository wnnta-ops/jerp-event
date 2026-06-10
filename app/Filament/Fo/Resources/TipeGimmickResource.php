<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\TipeGimmickResource\Pages;
use App\Modules\FO\Adminjual\Models\TipeGimmick;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TipeGimmickResource extends Resource
{
    protected static ?string $model = TipeGimmick::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Master Jual';

    protected static ?int $navigationSort = 28;

    protected static ?string $slug = 'tipe-gimmick';

    protected static ?string $modelLabel = 'Tipe Gimmick';

    protected static ?string $pluralModelLabel = 'Tipe Gimmick';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tipe Gimmick')->columns(2)
                ->description('Paket gimmick per project/cluster/periode (mis. CLUSTER 3.3, REGULER, PRIORITAS). Item-nya didaftarkan di Pendaftaran Gimmick.')
                ->schema([
                    Forms\Components\TextInput::make('kode')->label('No. ID')
                        ->default('#AUTO')->disabled()->dehydrated(false),
                    Forms\Components\Select::make('project_id')->label('Project')
                        ->relationship('project', 'name')->searchable()->preload()->placeholder('Semua'),
                    Forms\Components\TextInput::make('nama')->label('Nama')->required()->maxLength(255)
                        ->placeholder('mis. CLUSTER 3.3, REGULER, PRIORITAS')->columnSpanFull(),
                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('kode')->label('No. ID')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('project.name')->label('Project')->placeholder('Semua')->toggleable(),
            Tables\Columns\TextColumn::make('gimmicks_count')->counts('gimmicks')->label('# Item')->alignEnd(),
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
            'index' => Pages\ListTipeGimmicks::route('/'),
            'create' => Pages\CreateTipeGimmick::route('/create'),
            'edit' => Pages\EditTipeGimmick::route('/{record}/edit'),
        ];
    }
}
