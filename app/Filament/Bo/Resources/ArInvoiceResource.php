<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Bo\Resources\ArInvoiceResource\Pages;
use App\Models\Company;
use App\Modules\BO\AccountsReceivable\Models\ArInvoice;
use App\Modules\BO\AccountsReceivable\Services\ArInvoiceService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ArInvoiceResource extends Resource
{
    protected static ?string $model = ArInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationGroup = 'Piutang (AR)';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Invoice Piutang';

    protected static ?string $pluralModelLabel = 'Invoice Piutang (AR)';

    protected static ?string $recordTitleAttribute = 'no_invoice';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Invoice')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('jenis')->label('Jenis')->options([
                        'FI' => 'FI — Faktur Invoice', 'FK' => 'FK — Faktur Komersil', 'FP' => 'FP — Faktur Pajak',
                    ])->default('FI')->required(),
                    Forms\Components\TextInput::make('no_invoice')->label('No invoice')->disabled()->placeholder('Otomatis'),
                    Forms\Components\Select::make('company_id')->label('Company')->relationship('company', 'name')
                        ->default(fn () => Company::query()->value('id'))->required(),
                    Forms\Components\DatePicker::make('tgl')->label('Tgl invoice')->required()->default(now()),
                    Forms\Components\DatePicker::make('jatuh_tempo')->label('Jatuh tempo')->required()->default(now()->addDays(30)),
                    Forms\Components\TextInput::make('kontrak_ref')->label('Ref kontrak')->maxLength(64),
                    Forms\Components\TextInput::make('customer_nama')->label('Customer')->maxLength(255)->columnSpan(2),
                    Forms\Components\TextInput::make('customer_id')->label('Customer ID (FO)')->numeric(),
                ])
                ->disabled(fn (?ArInvoice $r) => $r !== null && $r->status !== 'DRAFT'),

            Forms\Components\Section::make('Nominal & Akun')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('nominal')->label('Nominal')->numeric()->rupiah()->required()->default(0),
                    Forms\Components\TextInput::make('akun_piutang')->label('Akun piutang')->default('1.1.02.001')->required(),
                    Forms\Components\TextInput::make('akun_pendapatan')->label('Akun pendapatan')->default('4.1.01.001')->required(),
                    Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
                ])
                ->disabled(fn (?ArInvoice $r) => $r !== null && $r->status !== 'DRAFT'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_invoice')->label('No')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('jenis')->label('Jns')->badge(),
                Tables\Columns\TextColumn::make('tgl')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('jatuh_tempo')->label('J.Tempo')->date('d M Y')->sortable()
                    ->color(fn (ArInvoice $r) => $r->isOverdue() ? 'danger' : null),
                Tables\Columns\TextColumn::make('customer_nama')->label('Customer')->searchable()->limit(24),
                Tables\Columns\TextColumn::make('nominal')->label('Nominal')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('sisa')->label('Sisa')->money('IDR', divideBy: 0)->alignEnd()
                    ->color(fn (ArInvoice $r) => $r->sisa > 0 ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (string $state) => match ($state) {
                    'DRAFT' => 'gray', 'BELUM' => 'warning', 'SEBAGIAN' => 'info', 'LUNAS' => 'success', 'BATAL' => 'danger', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'BELUM' => 'Belum', 'SEBAGIAN' => 'Sebagian', 'LUNAS' => 'Lunas', 'BATAL' => 'Batal',
                ]),
                Tables\Filters\Filter::make('overdue')->label('Jatuh tempo lewat')
                    ->query(fn ($query) => $query->whereIn('status', ['BELUM', 'SEBAGIAN'])->whereDate('jatuh_tempo', '<', now())),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (ArInvoice $r) => $r->status === 'DRAFT'),
                Tables\Actions\Action::make('post')
                    ->label('Posting')->icon('heroicon-o-lock-closed')->color('success')
                    ->visible(fn (ArInvoice $r) => $r->status === 'DRAFT')
                    ->requiresConfirmation()
                    ->action(fn (ArInvoice $r) => app(ArInvoiceService::class)->post($r)),
                Tables\Actions\Action::make('bayar')
                    ->label('Bayar')->icon('heroicon-o-banknotes')->color('info')
                    ->visible(fn (ArInvoice $r) => in_array($r->status, ['BELUM', 'SEBAGIAN'], true))
                    ->form([Forms\Components\TextInput::make('amount')->label('Nominal bayar')->numeric()->required()])
                    ->action(fn (ArInvoice $r, array $data) => app(ArInvoiceService::class)->recordPayment($r, (float) $data['amount'])),
                Tables\Actions\Action::make('denda')
                    ->label('Denda')->icon('heroicon-o-exclamation-triangle')->color('danger')
                    ->visible(fn (ArInvoice $r) => in_array($r->status, ['BELUM', 'SEBAGIAN'], true))
                    ->form([Forms\Components\TextInput::make('denda')->label('Nominal denda')->numeric()->required()])
                    ->action(fn (ArInvoice $r, array $data) => app(ArInvoiceService::class)->addDenda($r, (float) $data['denda'])),
            ])
            ->defaultSort('tgl', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArInvoices::route('/'),
            'create' => Pages\CreateArInvoice::route('/create'),
            'edit' => Pages\EditArInvoice::route('/{record}/edit'),
        ];
    }
}
