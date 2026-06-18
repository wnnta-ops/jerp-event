<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\TransferAnonimResource\Pages;
use App\Models\Bank;
use App\Modules\FO\FinanceAR\Models\TransferAnonim;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TransferAnonimResource extends Resource
{
    protected static ?string $model = TransferAnonim::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $modelLabel = 'Transfer Anonim';

    protected static ?string $pluralModelLabel = 'Transfer Anonim';

    protected static ?int $navigationSort = 65;

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // Transfer yang sudah SELESAI (terhubung via Verifikasi Data) tidak tampil lagi.
        return parent::getEloquentQuery()->where('status', '!=', 'SELESAI');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('tgl_transfer')->label('Tgl Transfer')->default(now()),
            Forms\Components\Select::make('bank_id')->label('Bank Penerima')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable(),
            Forms\Components\TextInput::make('amount')->label('Nominal (Rp)')->numeric()->rupiah()->default(0),
            Forms\Components\TextInput::make('keterangan_bank')->label('Berita/Referensi Bank')->columnSpanFull(),
            Forms\Components\Textarea::make('keterangan')->label('Catatan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tgl_transfer')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('bank.name')->label('Bank')->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')->label('Nominal')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('keterangan_bank')->label('Berita Bank')->limit(36)->placeholder('—'),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->placeholder('—')->limit(20),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'SELESAI' => 'success', 'TERIDENTIFIKASI' => 'warning', default => 'gray',
                }),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options([
                'BARU' => 'Baru', 'TERIDENTIFIKASI' => 'Teridentifikasi',
            ])])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                // Identifikasi & Jadikan TTS dihapus — status berubah otomatis (→ SELESAI)
                // saat transfer ini dipilih sebagai bukti di Verifikasi Data Booked.
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTransferAnonims::route('/')];
    }
}
