<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\TerminKomisiResource\Pages;
use App\Modules\FO\Adminjual\Models\SalesLevel;
use App\Modules\FO\Komisi\Models\TerminKomisi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TerminKomisiResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = TerminKomisi::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Setup Komisi';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'termin-komisi';

    protected static ?string $modelLabel = 'Termin Komisi';

    protected static ?string $pluralModelLabel = 'Termin Komisi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Termin Komisi')->columns(2)->schema([
                Forms\Components\TextInput::make('kode')->label('Kode')
                    ->default('#AUTO#')->disabled()->dehydrated(false),
                Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                Forms\Components\TextInput::make('nama')->label('Nama')->required()->maxLength(255)->columnSpanFull(),
                Forms\Components\Select::make('sales_type_id')->label('Tipe Sales')
                    ->relationship('salesType', 'name')->searchable()->preload()->placeholder('Semua'),
                Forms\Components\Select::make('project_id')->label('Project')
                    ->relationship('project', 'name')->searchable()->preload()->placeholder('Semua'),
                Forms\Components\Select::make('skema_komisi_id')->label('Skema Komisi')
                    ->relationship('skema', 'nama')->searchable()->preload()
                    ->helperText('Termin ini mengatur pencairan komisi dari skema tsb.'),
                Forms\Components\Radio::make('cara_bayar')->label('Cara Bayar')->inline()
                    ->options([
                        'SEMUA' => 'Semua', 'KPR' => 'KPR',
                        'CASH_BERTAHAP' => 'Cash Bertahap', 'CASH_KERAS' => 'Cash Keras',
                    ])->default('SEMUA')->required(),
            ]),

            Forms\Components\Section::make('Kondisi Termin')
                ->description('Tiap baris = satu pencairan. % Cair = porsi komisi yang dibayar saat syarat terpenuhi. Syarat % (Lunas/BF/DP/Angsuran) diabaikan kalau 0.')
                ->schema([
                    Forms\Components\Repeater::make('kondisi')->relationship()->label('')
                        ->columns(2)->defaultItems(1)->addActionLabel('Tambah Baris')
                        ->itemLabel(fn (array $state): ?string => ($state['nama'] ?? null)
                            ? (($state['nama'] ?? '').' ('.($state['persen_cair'] ?? 0).'%)')
                            : null)
                        ->schema([
                            Forms\Components\Select::make('sales_level_id')->label('Level')
                                ->options(function (Forms\Get $get) {
                                    $st = $get('../../sales_type_id');

                                    return SalesLevel::query()->where('is_active', true)
                                        ->when($st, fn ($q) => $q->where('sales_type_id', $st))
                                        ->orderBy('name')->pluck('name', 'id');
                                })
                                ->searchable()->required()->helperText('Dari master Level Sales.'),
                            Forms\Components\TextInput::make('nama')->label('Nama Termin')->placeholder('mis. Termin 1'),
                            Forms\Components\TextInput::make('persen_cair')->label('% Cair')->numeric()->default(100)->suffix('%')->required(),
                            Forms\Components\Radio::make('tipe_syarat')->label('Tipe Syarat Cair')->inline()
                                ->options(['SEMUA' => 'Semua', 'SALAH_SATU' => 'Salah Satu'])
                                ->default('SALAH_SATU')->required(),
                            Forms\Components\Fieldset::make('Syarat Cair (%) — 0 = abaikan')->columns(4)->schema([
                                Forms\Components\TextInput::make('persen_lunas')->label('% Lunas')->numeric()->default(0),
                                Forms\Components\TextInput::make('persen_bf')->label('% BF')->numeric()->default(0),
                                Forms\Components\TextInput::make('persen_dp')->label('% DP')->numeric()->default(0),
                                Forms\Components\TextInput::make('persen_ang')->label('% Angsuran')->numeric()->default(0),
                            ]),
                            Forms\Components\Fieldset::make('Syarat Dokumen')->columns(3)->schema([
                                Forms\Components\Checkbox::make('syarat_ppjb')->label('PPJB'),
                                Forms\Components\Checkbox::make('syarat_ajb')->label('AJB'),
                                Forms\Components\Checkbox::make('syarat_akad')->label('AKAD'),
                            ]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('kode')->label('Kode')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable()->sortable()->wrap(),
            Tables\Columns\TextColumn::make('skema.nama')->label('Skema Komisi')->placeholder('—')->toggleable(),
            Tables\Columns\TextColumn::make('cara_bayar')->label('Cara Bayar')->badge()->toggleable(),
            Tables\Columns\TextColumn::make('kondisi_count')->counts('kondisi')->label('# Termin')->alignEnd()->toggleable(),
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
            'index' => Pages\ListTerminKomisis::route('/'),
            'create' => Pages\CreateTerminKomisi::route('/create'),
            'edit' => Pages\EditTerminKomisi::route('/{record}/edit'),
        ];
    }
}
