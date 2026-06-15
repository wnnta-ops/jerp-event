<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\KomisiResource\Pages;
use App\Modules\FO\Komisi\Models\Agent;
use App\Modules\FO\Komisi\Models\Komisi;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Support\Rupiah;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class KomisiResource extends Resource
{
    protected static ?string $model = Komisi::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Komisi';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Komisi';

    protected static ?string $pluralModelLabel = 'Komisi';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas')->columns(3)->schema([
                Forms\Components\TextInput::make('no_komisi')->label('No komisi')->disabled()->dehydrated()->placeholder('Otomatis'),
                Forms\Components\DatePicker::make('tgl_komisi')->label('Tanggal')->default(now())->required(),
                Forms\Components\Select::make('status')->label('Status')->options([
                    'DRAFT' => 'Draft', 'APPROVED' => 'Approved', 'PAID' => 'Paid', 'BATAL' => 'Batal',
                ])->default('DRAFT')->required(),
            ]),

            Forms\Components\Section::make('Agent & Kontrak')->columns(2)->schema([
                Forms\Components\Select::make('agent_id')->label('Agent')
                    ->relationship('agent', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                    ->searchable(['code', 'name'])->preload()->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) {
                        $a = Agent::find($state);
                        if ($a) {
                            $set('persen_komisi', $a->komisi_persen_default);
                        }
                    }),
                Forms\Components\Select::make('kontrak_id')->label('Kontrak')
                    ->relationship('kontrak', 'no_kontrak')
                    ->searchable()->preload()->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $k = Kontrak::find($state);
                        if ($k) {
                            $set('dpp', $k->total_kontrak);
                            $persen = (float) ($get('persen_komisi') ?? 0);
                            $amount = round($k->total_kontrak * $persen / 100, 2);
                            $set('amount', $amount);
                        }
                    }),
            ]),

            Forms\Components\Section::make('Nilai Komisi')->columns(3)->schema([
                Forms\Components\TextInput::make('dpp')->label('DPP')->numeric()->rupiah()->required()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $persen = (float) ($get('persen_komisi') ?? 0);
                        $set('amount', round(Rupiah::toFloat($state) * $persen / 100, 2));
                    }),
                Forms\Components\TextInput::make('persen_komisi')->label('% Komisi')->numeric()->suffix('%')->required()
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $dpp = Rupiah::toFloat($get('dpp'));
                        $set('amount', round($dpp * ((float) $state) / 100, 2));
                    }),
                Forms\Components\TextInput::make('amount')->label('Amount')->numeric()->rupiah()->required()->default(0),
                Forms\Components\TextInput::make('pph_persen')->label('% PPh')->numeric()->suffix('%')->default(0)
                    ->live(debounce: 500)
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        $amount = Rupiah::toFloat($get('amount'));
                        $pph = round($amount * ((float) $state) / 100, 2);
                        $set('pph_amount', $pph);
                        $set('amount_net', round($amount - $pph, 2));
                    }),
                Forms\Components\TextInput::make('pph_amount')->label('PPh (Rp)')->numeric()->rupiah()->default(0),
                Forms\Components\TextInput::make('amount_net')->label('Net')->numeric()->rupiah()->default(0),
            ]),

            Forms\Components\Section::make('Pembayaran')->columns(2)->schema([
                Forms\Components\DatePicker::make('tgl_bayar')->label('Tgl bayar'),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ])->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('no_komisi')->label('No komisi')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('tgl_komisi')->label('Tgl')->date('d M Y')->sortable(),
            Tables\Columns\TextColumn::make('agent.name')->label('Agent')->searchable(),
            Tables\Columns\TextColumn::make('kontrak.no_kontrak')->label('Kontrak')->toggleable(),
            Tables\Columns\TextColumn::make('dpp')->label('DPP')->money('IDR', divideBy: 0)->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('persen_komisi')->label('%')->numeric(2)->suffix('%')->alignEnd(),
            Tables\Columns\TextColumn::make('amount')->label('Komisi')->money('IDR', divideBy: 0)->alignEnd(),
            Tables\Columns\TextColumn::make('amount_net')->label('Net')->money('IDR', divideBy: 0)->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                'DRAFT' => 'gray', 'APPROVED' => 'info', 'PAID' => 'success', 'BATAL' => 'danger', default => 'gray',
            }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'DRAFT' => 'Draft', 'APPROVED' => 'Approved', 'PAID' => 'Paid', 'BATAL' => 'Batal',
            ]),
            Tables\Filters\SelectFilter::make('agent_id')->relationship('agent', 'name')->label('Agent'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->color('info')
                ->visible(fn (Komisi $r) => $r->status === 'DRAFT')
                ->action(function (Komisi $r) {
                    $r->update(['status' => 'APPROVED']);
                    Notification::make()->title('Komisi di-approve')->success()->send();
                }),
            Tables\Actions\Action::make('markPaid')
                ->label('Tandai Paid')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->visible(fn (Komisi $r) => $r->status === 'APPROVED')
                ->form([
                    Forms\Components\DatePicker::make('tgl_bayar')->label('Tgl bayar')->default(now())->required(),
                ])
                ->action(function (Komisi $r, array $data) {
                    $r->update(['status' => 'PAID', 'tgl_bayar' => $data['tgl_bayar']]);
                    Notification::make()->title('Komisi paid')->success()->send();
                }),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('tgl_komisi', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKomisis::route('/'),
            'create' => Pages\CreateKomisi::route('/create'),
            'edit' => Pages\EditKomisi::route('/{record}/edit'),
        ];
    }
}
