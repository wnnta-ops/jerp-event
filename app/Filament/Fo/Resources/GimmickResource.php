<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\GimmickResource\Pages;
use App\Modules\FO\Adminjual\Models\Gimmick;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GimmickResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Gimmick::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Master Jual';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Pendaftaran Gimmick';

    protected static ?string $pluralModelLabel = 'Pendaftaran Gimmick';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Spesifikasi')->columns(2)
                ->description('Item gimmick (turunan Tipe Gimmick) yang nanti tercantum di SPR — mis. FREE CANOPY, FREE BPHTB, Logam Mulia 1 Gr.')
                ->schema([
                    Forms\Components\TextInput::make('code')->label('No.')
                        ->default('#AUTO')->disabled()->dehydrated(false),
                    Forms\Components\Select::make('project_id')->label('Project')
                        ->relationship('project', 'name')->searchable()->preload()->placeholder('Semua'),
                    Forms\Components\Select::make('tipe_gimmick_id')->label('Tipe Barang (Tipe Gimmick)')
                        ->relationship('tipeGimmick', 'nama')->searchable()->preload()->required()
                        ->helperText('Paket gimmick (CLUSTER 3.3 / REGULER / PRIORITAS). Kelola di menu Tipe Gimmick.')
                        ->columnSpanFull(),

                    // CREATE: input banyak nama + harga sekaligus → tiap baris jadi 1 item.
                    Forms\Components\Repeater::make('nama_barang_list')->label('Nama Barang (bisa banyak)')
                        ->schema([
                            Forms\Components\TextInput::make('nama')->label('Nama Barang')->required()
                                ->placeholder('mis. FREE CANOPY')->columnSpan(3),
                            Forms\Components\TextInput::make('harga')->label('Harga')->numeric()->rupiah()->default(0)->columnSpan(2)
                                ->helperText('Tidak ditagih ke customer — untuk BO (subsidi developer).'),
                        ])->columns(5)
                        ->addActionLabel('Tambah Barang')->defaultItems(1)->minItems(1)->reorderable(false)
                        ->visibleOn('create')->dehydrated()->columnSpanFull(),

                    // EDIT: satu item = satu nama + harga.
                    Forms\Components\Group::make()->columns(5)->visibleOn('edit')->columnSpanFull()->schema([
                        Forms\Components\TextInput::make('name')->label('Nama Barang')->required()->maxLength(255)->columnSpan(3),
                        Forms\Components\TextInput::make('nilai')->label('Harga')->numeric()->rupiah()->default(0)->columnSpan(2)
                            ->helperText('Tidak ditagih ke customer — untuk BO (subsidi developer).'),
                    ]),

                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                    Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('No.')->badge()->searchable()->sortable()->toggleable(),
            Tables\Columns\TextColumn::make('name')->label('Nama Barang')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('tipeGimmick.nama')->label('Tipe Gimmick')->badge()->placeholder('—')->searchable(),
            Tables\Columns\TextColumn::make('project.code')->label('Project')->toggleable(),
            Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->filters([
            Tables\Filters\SelectFilter::make('tipe_gimmick_id')->relationship('tipeGimmick', 'nama')->label('Tipe Gimmick'),
            Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Project'),
            Tables\Filters\TernaryFilter::make('is_active'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('name');
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
