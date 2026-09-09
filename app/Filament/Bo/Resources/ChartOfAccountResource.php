<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bo\Clusters\GeneralLedger;
use App\Filament\Bo\Resources\ChartOfAccountResource\Pages;
use App\Modules\BO\GeneralLedger\Models\ChartOfAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ChartOfAccountResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = ChartOfAccount::class;

    protected static ?string $cluster = GeneralLedger::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Master';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'Akun (COA)';

    protected static ?string $pluralModelLabel = 'Chart of Account';

    protected static ?string $recordTitleAttribute = 'nama';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Akun')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('kode')
                        ->label('Kode')
                        ->required()
                        ->maxLength(32)
                        ->placeholder('mis. 1.1.01.001'),
                    Forms\Components\TextInput::make('nama')->label('Nama akun')->required()->columnSpan(2),
                    Forms\Components\Select::make('tipe_akun')
                        ->label('Tipe')
                        ->options([
                            'ASSET' => 'Aset',
                            'LIABILITY' => 'Liabilitas',
                            'EQUITY' => 'Ekuitas',
                            'REVENUE' => 'Pendapatan',
                            'EXPENSE' => 'Beban',
                        ])
                        ->live()
                        ->afterStateUpdated(fn ($state, Forms\Set $set) => $set(
                            'saldo_normal',
                            ChartOfAccount::normalBalanceFor($state ?? 'ASSET')
                        ))
                        ->required(),
                    Forms\Components\Select::make('saldo_normal')
                        ->label('Saldo normal')
                        ->options(['DEBET' => 'Debet', 'KREDIT' => 'Kredit'])
                        ->required(),
                    Forms\Components\TextInput::make('level')->label('Level')->numeric()->default(4)->minValue(1)->maxValue(6),
                    Forms\Components\TextInput::make('parent_kode')->label('Kode induk')->maxLength(32),
                ]),

            Forms\Components\Section::make('Atribut')
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('is_header')->label('Akun header')->helperText('Tidak bisa diposting.'),
                    Forms\Components\Toggle::make('is_pajak')->label('Akun pajak'),
                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                    Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('kode')->label('Kode')->sortable()->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('nama')->label('Nama')->searchable()
                    ->formatStateUsing(fn ($state, ChartOfAccount $r) => str_repeat('— ', max(0, $r->level - 1)).$state),
                Tables\Columns\TextColumn::make('tipe_akun')->label('Tipe')->badge()->color(fn (string $state) => match ($state) {
                    'ASSET' => 'info',
                    'LIABILITY' => 'warning',
                    'EQUITY' => 'gray',
                    'REVENUE' => 'success',
                    'EXPENSE' => 'danger',
                    default => 'gray',
                }),
                Tables\Columns\TextColumn::make('saldo_normal')->label('Saldo')->badge(),
                Tables\Columns\IconColumn::make('is_header')->label('Header')->boolean()->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipe_akun')->options([
                    'ASSET' => 'Aset', 'LIABILITY' => 'Liabilitas', 'EQUITY' => 'Ekuitas',
                    'REVENUE' => 'Pendapatan', 'EXPENSE' => 'Beban',
                ]),
                Tables\Filters\TernaryFilter::make('is_header')->label('Akun header'),
                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('kode')
            ->paginated([25, 50, 100, 'all']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChartOfAccounts::route('/'),
            'create' => Pages\CreateChartOfAccount::route('/create'),
            'edit' => Pages\EditChartOfAccount::route('/{record}/edit'),
        ];
    }
}
