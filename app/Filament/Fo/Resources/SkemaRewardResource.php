<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\SkemaRewardResource\Pages;
use App\Modules\FO\Adminjual\Models\SalesLevel;
use App\Modules\FO\Komisi\Models\SkemaReward;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SkemaRewardResource extends Resource
{
    protected static ?string $model = SkemaReward::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Setup Komisi';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'skema-reward';

    protected static ?string $modelLabel = 'Skema Reward';

    protected static ?string $pluralModelLabel = 'Setup Skema Reward';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Skema Reward')->columns(2)->schema([
                Forms\Components\TextInput::make('kode')->label('Kode')
                    ->default('#AUTO#')->disabled()->dehydrated(false),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                Forms\Components\TextInput::make('nama')->label('Nama')->required()->maxLength(255)->columnSpanFull(),
                Forms\Components\DatePicker::make('periode_dari')->label('Periode Closing Fee — dari')->native(false)->displayFormat('d M Y'),
                Forms\Components\DatePicker::make('periode_sampai')->label('sampai')->native(false)->displayFormat('d M Y'),
                Forms\Components\Select::make('project_id')->label('Project')
                    ->relationship('project', 'name')->searchable()->preload()->placeholder('Semua'),
                Forms\Components\Select::make('sales_type_id')->label('Tipe Sales')
                    ->relationship('salesType', 'name')->searchable()->preload()->placeholder('Semua'),
                Forms\Components\Radio::make('rumus_closing_fee')->label('Rumus Closing Fee')->inline()
                    ->options(['SATUAN_PER_UNIT' => 'Satuan Per Unit', 'TARGET_KUMULATIF' => 'Target Kumulatif'])
                    ->default('TARGET_KUMULATIF')->required()->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Rumus Reward (per Level)')->schema([
                Forms\Components\Repeater::make('lines')->relationship()->label('')
                    ->columns(12)->defaultItems(1)->addActionLabel('Tambah Baris')
                    ->schema([
                        Forms\Components\Select::make('sales_level_id')->label('Level')->columnSpan(3)
                            ->options(function (Forms\Get $get) {
                                $st = $get('../../sales_type_id');

                                return SalesLevel::query()->where('is_active', true)
                                    ->when($st, fn ($q) => $q->where('sales_type_id', $st))
                                    ->orderBy('name')->pluck('name', 'id');
                            })
                            ->searchable()->required()
                            ->helperText('Dari master Level Sales.'),
                        Forms\Components\Radio::make('tipe_target')->label('Tipe Target')->columnSpan(2)
                            ->options(['UNIT' => 'Unit', 'NILAI' => 'Nilai'])->default('UNIT')->required(),
                        Forms\Components\TextInput::make('target_bawah')->label('Target Bawah')->columnSpan(2)->numeric()->default(0),
                        Forms\Components\TextInput::make('target_atas')->label('Target Atas')->columnSpan(2)->numeric()->default(0),
                        Forms\Components\Radio::make('tipe_reward')->label('Tipe Reward')->columnSpan(3)->live()
                            ->options(['UANG' => 'Uang', 'NON_UANG' => 'Non Uang'])->default('UANG')->required(),
                        Forms\Components\TextInput::make('reward_amount')->label('Reward (Rp)')->columnSpan(6)->numeric()->default(0)
                            ->visible(fn (Forms\Get $get) => $get('tipe_reward') !== 'NON_UANG'),
                        Forms\Components\TextInput::make('reward_keterangan')->label('Reward (Non Uang)')->columnSpan(6)
                            ->placeholder('mis. Tiket, Tropi, Umroh')
                            ->visible(fn (Forms\Get $get) => $get('tipe_reward') === 'NON_UANG'),
                    ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('kode')->label('Kode')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable()->sortable()->wrap(),
            Tables\Columns\TextColumn::make('project.name')->label('Project')->placeholder('Semua')->toggleable(),
            Tables\Columns\TextColumn::make('salesType.name')->label('Tipe Sales')->placeholder('Semua')->toggleable(),
            Tables\Columns\TextColumn::make('rumus_closing_fee')->label('Rumus')->badge()->toggleable(),
            Tables\Columns\TextColumn::make('lines_count')->counts('lines')->label('# Baris')->alignEnd()->toggleable(),
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
            'index' => Pages\ListSkemaRewards::route('/'),
            'create' => Pages\CreateSkemaReward::route('/create'),
            'edit' => Pages\EditSkemaReward::route('/{record}/edit'),
        ];
    }
}
