<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\TransferAnonimResource\Pages;
use App\Models\Bank;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Models\TransferAnonim;
use App\Modules\FO\MarketingSales\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('tgl_transfer')->label('Tgl Transfer')->default(now()),
            Forms\Components\Select::make('bank_id')->label('Bank Penerima')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable(),
            Forms\Components\TextInput::make('amount')->label('Nominal (Rp)')->numeric()->prefix('Rp')->default(0),
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
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $s) => match ($s) {
                    'SELESAI' => 'success', 'TERIDENTIFIKASI' => 'warning', default => 'gray',
                }),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options([
                'BARU' => 'Baru', 'TERIDENTIFIKASI' => 'Teridentifikasi', 'SELESAI' => 'Selesai',
            ])])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\Action::make('identifikasi')->label('Identifikasi')->icon('heroicon-o-user')->color('warning')
                    ->visible(fn (TransferAnonim $r) => $r->status === 'BARU')
                    ->form([Forms\Components\Select::make('customer_id')->label('Customer')->required()->searchable()
                        ->options(fn () => Customer::query()->limit(300)->pluck('full_name', 'id'))])
                    ->action(function (TransferAnonim $r, array $data) {
                        $r->update(['customer_id' => $data['customer_id'], 'status' => 'TERIDENTIFIKASI']);
                        Notification::make()->success()->title('Teridentifikasi')->send();
                    }),
                // Jadikan TTS → buat Tanda Terima dari transfer ini.
                Tables\Actions\Action::make('jadikan_tts')->label('Jadikan TTS')->icon('heroicon-o-ticket')->color('success')
                    ->visible(fn (TransferAnonim $r) => $r->status === 'TERIDENTIFIKASI' && $r->customer_id)
                    ->requiresConfirmation()
                    ->action(function (TransferAnonim $r) {
                        $tts = Tts::create([
                            'tenant_id' => $r->tenant_id, 'tgl_tts' => now()->toDateString(), 'tgl_terima' => $r->tgl_transfer,
                            'customer_id' => $r->customer_id, 'jenis_pembayaran' => 'LAIN', 'cara_bayar' => 'TRANSFER',
                            'amount' => $r->amount, 'bank_id' => $r->bank_id, 'no_referensi' => $r->keterangan_bank,
                            'status' => 'KONFIRMASI', 'keterangan' => 'Dari transfer anonim',
                        ]);
                        $r->update(['tts_id' => $tts->id, 'status' => 'SELESAI']);
                        Notification::make()->success()->title('TTS dibuat: '.$tts->no_tts)->send();
                    }),
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
