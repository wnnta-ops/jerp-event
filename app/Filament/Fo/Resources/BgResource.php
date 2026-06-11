<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\BgResource\Pages;
use App\Models\Bank;
use App\Modules\FO\FinanceAR\Models\Bg;
use App\Modules\FO\MarketingSales\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BgResource extends Resource
{
    protected static ?string $model = Bg::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $modelLabel = 'Bilyet Giro';

    protected static ?string $pluralModelLabel = 'Bilyet Giro (BG)';

    protected static ?int $navigationSort = 55;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('no_bg')->label('No. BG/Cek')->required(),
            Forms\Components\Select::make('bank_id')->label('Bank')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable(),
            Forms\Components\DatePicker::make('tgl_bg')->label('Tgl BG'),
            Forms\Components\DatePicker::make('tgl_jatuh_tempo')->label('Jatuh Tempo'),
            Forms\Components\Select::make('customer_id')->label('Customer')->searchable()
                ->options(fn () => Customer::query()->limit(300)->pluck('full_name', 'id')),
            Forms\Components\TextInput::make('amount')->label('Nominal (Rp)')->numeric()->prefix('Rp')->default(0),
            Forms\Components\TextInput::make('pengelola')->label('Pengelola'),
            Forms\Components\Select::make('status')->label('Status')->default('NORMAL')
                ->options(['NORMAL' => 'Normal', 'JATUH_TEMPO' => 'Jatuh Tempo', 'CAIR' => 'Cair', 'BERMASALAH' => 'Bermasalah', 'DIGANTI' => 'Diganti']),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_bg')->label('No. BG')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('bank.name')->label('Bank')->placeholder('—'),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->placeholder('—')->limit(24),
                Tables\Columns\TextColumn::make('tgl_jatuh_tempo')->label('Jatuh Tempo')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('amount')->label('Nominal')->money('IDR'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $s) => match ($s) {
                    'CAIR' => 'success', 'BERMASALAH' => 'danger', 'JATUH_TEMPO' => 'warning', 'DIGANTI' => 'gray', default => 'info',
                }),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options([
                'NORMAL' => 'Normal', 'JATUH_TEMPO' => 'Jatuh Tempo', 'CAIR' => 'Cair', 'BERMASALAH' => 'Bermasalah', 'DIGANTI' => 'Diganti',
            ])])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                // Ganti Giro: giro bermasalah → buat giro pengganti, tandai lama DIGANTI.
                Tables\Actions\Action::make('ganti')->label('Ganti Giro')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (Bg $r) => ! in_array($r->status, ['DIGANTI', 'CAIR']))
                    ->form([
                        Forms\Components\TextInput::make('no_bg_baru')->label('No. BG Pengganti')->required(),
                        Forms\Components\DatePicker::make('tgl_bg_baru')->label('Tgl BG Pengganti')->default(now()),
                        Forms\Components\DatePicker::make('tgl_jt_baru')->label('Jatuh Tempo Baru'),
                    ])
                    ->action(function (Bg $r, array $data) {
                        $baru = Bg::create([
                            'tenant_id' => $r->tenant_id, 'no_bg' => $data['no_bg_baru'],
                            'tgl_bg' => $data['tgl_bg_baru'] ?? now(), 'tgl_jatuh_tempo' => $data['tgl_jt_baru'] ?? null,
                            'bank_id' => $r->bank_id, 'customer_id' => $r->customer_id, 'tts_id' => $r->tts_id,
                            'amount' => $r->amount, 'pengelola' => $r->pengelola, 'status' => 'NORMAL',
                            'keterangan' => 'Pengganti dari BG '.$r->no_bg,
                        ]);
                        $r->update(['status' => 'DIGANTI', 'bg_pengganti_id' => $baru->id]);
                        Notification::make()->success()->title('Giro diganti → '.$baru->no_bg)->send();
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBgs::route('/')];
    }
}
