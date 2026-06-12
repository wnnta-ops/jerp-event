<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\MemoResource\Pages;
use App\Modules\FO\FinanceAR\Models\Memo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MemoResource extends Resource
{
    protected static ?string $model = Memo::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?int $navigationSort = 40;

    protected static ?string $modelLabel = 'Memo';

    protected static ?string $pluralModelLabel = 'Memo Finansial';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Memo')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('no_memo')
                        ->label('No Memo')
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Otomatis'),
                    Forms\Components\DatePicker::make('tgl_memo')->label('Tgl memo')->required()->default(now()),
                    Forms\Components\Select::make('jenis')
                        ->label('Jenis')
                        ->options([
                            'CASHBACK' => 'Cashback',
                            'ADJUSTMENT' => 'Adjustment',
                            'KOREKSI' => 'Koreksi',
                            'DISKON' => 'Diskon',
                            'LAIN' => 'Lain',
                        ])
                        ->required(),
                ]),

            Forms\Components\Section::make('Customer & Kontrak')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'full_name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->full_name)
                        ->searchable(['code', 'full_name'])
                        ->preload()
                        ->required(),
                    Forms\Components\Select::make('kontrak_id')
                        ->label('Kontrak')
                        ->relationship('kontrak', 'no_kontrak')
                        ->searchable()
                        ->preload(),
                ]),

            Forms\Components\Section::make('Nilai & Posting')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('arah')
                        ->label('Arah')
                        ->options(['DEBIT' => 'Debit (kewajiban customer +)', 'KREDIT' => 'Kredit (kewajiban customer −)'])
                        ->default('KREDIT')
                        ->required(),
                    Forms\Components\TextInput::make('amount')
                        ->label('Jumlah')
                        ->numeric()
                        ->rupiah()
                        ->required()
                        ->default(0),
                    Forms\Components\TextInput::make('akun_pasangan')
                        ->label('Akun pasangan (GL)')
                        ->maxLength(32)
                        ->helperText('Kode akun untuk offset di GL.'),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options(['DRAFT' => 'Draft', 'POSTED' => 'Posted', 'BATAL' => 'Batal'])
                        ->default('DRAFT')
                        ->required(),
                ]),

            Forms\Components\Section::make('Keterangan')
                ->schema([
                    Forms\Components\Textarea::make('keterangan')->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_memo')->label('No Memo')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tgl_memo')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('kontrak.no_kontrak')->label('Kontrak')->toggleable(),
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge(),
                Tables\Columns\TextColumn::make('arah')->label('Arah')->badge()->color(fn (?string $state) => $state === 'DEBIT' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('amount')->label('Jumlah')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'DRAFT' => 'gray',
                    'POSTED' => 'success',
                    'BATAL' => 'danger',
                    default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')->options([
                    'CASHBACK' => 'Cashback', 'ADJUSTMENT' => 'Adjustment', 'KOREKSI' => 'Koreksi', 'DISKON' => 'Diskon', 'LAIN' => 'Lain',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'POSTED' => 'Posted', 'BATAL' => 'Batal',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('postMemo')
                    ->label('Posting')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Memo $r) => $r->status === 'DRAFT')
                    ->action(function (Memo $r) {
                        $r->update(['status' => 'POSTED']);
                        Notification::make()
                            ->title('Memo di-posting')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('tgl_memo', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMemos::route('/'),
            'create' => Pages\CreateMemo::route('/create'),
            'edit' => Pages\EditMemo::route('/{record}/edit'),
        ];
    }
}
