<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\VaResource\Pages;
use App\Modules\FO\FinanceAR\Models\Va;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VaResource extends Resource
{
    protected static ?string $model = Va::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Virtual Account';

    protected static ?string $pluralModelLabel = 'Virtual Account (VA)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Virtual Account')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'full_name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->full_name)
                        ->searchable(['code', 'full_name', 'nik'])
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('bank_id')
                        ->label('Bank issuer')
                        ->relationship('bank', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\TextInput::make('va_number')
                        ->label('Nomor VA')
                        ->required()
                        ->maxLength(64)
                        ->helperText('Nomor VA dari bank issuer.'),
                    Forms\Components\TextInput::make('rekening_holder')
                        ->label('Atas nama')
                        ->maxLength(255),
                    Forms\Components\DatePicker::make('tgl_aktif')->label('Tgl aktif')->default(now()),
                    Forms\Components\DatePicker::make('tgl_kadaluarsa')
                        ->label('Tgl kadaluarsa')
                        ->helperText('Kosongkan jika permanen.'),
                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                    Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('va_number')->label('No VA')->fontFamily('mono')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('bank.code')->label('Bank')->badge()->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('rekening_holder')->label('Atas nama')->toggleable(),
                Tables\Columns\TextColumn::make('tgl_aktif')->label('Aktif sejak')->date('d M Y')->toggleable(),
                Tables\Columns\TextColumn::make('tgl_kadaluarsa')->label('Kadaluarsa')->date('d M Y')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('bank_id')->relationship('bank', 'name')->label('Bank'),
                Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVas::route('/'),
            'create' => Pages\CreateVa::route('/create'),
            'edit' => Pages\EditVa::route('/{record}/edit'),
        ];
    }
}
