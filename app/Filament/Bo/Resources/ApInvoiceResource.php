<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Bo\Resources\ApInvoiceResource\Pages;
use App\Models\Company;
use App\Modules\BO\AccountsPayable\Models\ApInvoice;
use App\Modules\BO\AccountsPayable\Services\ApInvoiceService;
use App\Modules\BO\GeneralLedger\Models\ChartOfAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ApInvoiceResource extends Resource
{
    protected static ?string $model = ApInvoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Hutang (AP)';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Invoice Hutang';

    protected static ?string $pluralModelLabel = 'Invoice Hutang (AP)';

    protected static ?string $recordTitleAttribute = 'no_invoice';

    public static function coaOptions(): array
    {
        return ChartOfAccount::query()->postable()->orderBy('kode')->get()
            ->mapWithKeys(fn ($a) => [$a->kode => $a->kode.' — '.$a->nama])->toArray();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Invoice')->columns(3)->schema([
                Forms\Components\Select::make('jenis')->label('Jenis')->options(['PO' => 'PO', 'NONPO' => 'Non-PO'])->default('NONPO')->required(),
                Forms\Components\TextInput::make('no_invoice')->label('No invoice')->disabled()->placeholder('Otomatis'),
                Forms\Components\Select::make('company_id')->label('Company')->relationship('company', 'name')
                    ->default(fn () => Company::query()->value('id'))->required(),
                Forms\Components\Select::make('supplier_id')->label('Supplier')->relationship('supplier', 'nama')
                    ->searchable()->preload()->required(),
                Forms\Components\DatePicker::make('tgl')->label('Tgl')->required()->default(now()),
                Forms\Components\DatePicker::make('jatuh_tempo')->label('Jatuh tempo')->required()->default(now()->addDays(30)),
            ])->disabled(fn (?ApInvoice $r) => $r !== null && ! in_array($r->status, ['DRAFT'], true)),

            Forms\Components\Section::make('Nominal & Akun')->columns(3)->schema([
                Forms\Components\TextInput::make('nominal')->label('DPP')->numeric()->prefix('Rp')->required()->default(0),
                Forms\Components\TextInput::make('ppn')->label('PPN')->numeric()->prefix('Rp')->default(0),
                Forms\Components\TextInput::make('pph')->label('PPh dipotong')->numeric()->prefix('Rp')->default(0),
                Forms\Components\Select::make('akun_beban')->label('Akun beban/aset')->options(static::coaOptions())->searchable()->required()->default('5.9.01.001'),
                Forms\Components\Select::make('akun_hutang')->label('Akun hutang')->options(static::coaOptions())->searchable()->required()->default('2.1.01.001'),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ])->disabled(fn (?ApInvoice $r) => $r !== null && ! in_array($r->status, ['DRAFT'], true)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_invoice')->label('No')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('supplier.nama')->label('Supplier')->searchable()->limit(24),
                Tables\Columns\TextColumn::make('jenis')->label('Jns')->badge(),
                Tables\Columns\TextColumn::make('tgl')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('jatuh_tempo')->label('J.Tempo')->date('d M Y')
                    ->color(fn (ApInvoice $r) => $r->isOverdue() ? 'danger' : null),
                Tables\Columns\TextColumn::make('total')->label('Total')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('sisa')->label('Sisa')->money('IDR', divideBy: 0)->alignEnd()
                    ->color(fn (ApInvoice $r) => $r->sisa > 0 ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (string $state) => match ($state) {
                    'DRAFT' => 'gray', 'APPROVED' => 'warning', 'POSTED' => 'info', 'SEBAGIAN' => 'info', 'LUNAS' => 'success', 'BATAL' => 'danger', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'APPROVED' => 'Approved', 'POSTED' => 'Posted', 'SEBAGIAN' => 'Sebagian', 'LUNAS' => 'Lunas', 'BATAL' => 'Batal',
                ]),
                Tables\Filters\SelectFilter::make('supplier_id')->relationship('supplier', 'nama')->label('Supplier'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (ApInvoice $r) => $r->status === 'DRAFT'),
                Tables\Actions\Action::make('approve')->label('Approve')->icon('heroicon-o-check')->color('warning')
                    ->visible(fn (ApInvoice $r) => $r->status === 'DRAFT')->requiresConfirmation()
                    ->action(fn (ApInvoice $r) => app(ApInvoiceService::class)->approve($r)),
                Tables\Actions\Action::make('post')->label('Posting')->icon('heroicon-o-lock-closed')->color('success')
                    ->visible(fn (ApInvoice $r) => in_array($r->status, ['DRAFT', 'APPROVED'], true))->requiresConfirmation()
                    ->action(fn (ApInvoice $r) => app(ApInvoiceService::class)->post($r)),
                Tables\Actions\Action::make('bayar')->label('Bayar')->icon('heroicon-o-banknotes')->color('info')
                    ->visible(fn (ApInvoice $r) => in_array($r->status, ['POSTED', 'SEBAGIAN'], true))
                    ->form([
                        Forms\Components\TextInput::make('amount')->label('Nominal bayar')->numeric()->required(),
                        Forms\Components\Select::make('akun_kas')->label('Dari kas/bank')->options(static::coaOptions())->searchable()->required(),
                    ])
                    ->action(fn (ApInvoice $r, array $data) => app(ApInvoiceService::class)->pay($r, (float) $data['amount'], $data['akun_kas'])),
            ])
            ->defaultSort('tgl', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApInvoices::route('/'),
            'create' => Pages\CreateApInvoice::route('/create'),
            'edit' => Pages\EditApInvoice::route('/{record}/edit'),
        ];
    }
}
