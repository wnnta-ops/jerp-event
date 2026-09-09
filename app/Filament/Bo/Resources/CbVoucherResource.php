<?php

namespace App\Filament\Bo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bo\Resources\CbVoucherResource\Pages;
use App\Models\Company;
use App\Modules\BO\CashBank\Models\CbVoucher;
use App\Modules\BO\CashBank\Services\CbVoucherService;
use App\Modules\BO\GeneralLedger\Models\ChartOfAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CbVoucherResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = CbVoucher::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Cash & Bank';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Voucher Kas/Bank';

    protected static ?string $pluralModelLabel = 'Voucher Kas & Bank';

    protected static ?string $recordTitleAttribute = 'no_voucher';

    /** @return array<string,string> */
    public static function jenisOptions(): array
    {
        return [
            'BKM' => 'BKM — Bukti Kas Masuk',
            'BKK' => 'BKK — Bukti Kas Keluar',
            'BGM' => 'BGM — Bukti Giro Masuk',
            'BGK' => 'BGK — Bukti Giro Keluar',
            'BNG' => 'BNG — Biaya/Bunga Bank',
            'BS' => 'BS — Bank Setor/Statement',
            'ADM' => 'ADM — Biaya Administrasi',
            'ANO' => 'ANO — Dana Anonim',
            'PBS' => 'PBS — Reimbursement',
        ];
    }

    public static function coaOptions(): array
    {
        return ChartOfAccount::query()->postable()->orderBy('kode')->get()
            ->mapWithKeys(fn ($a) => [$a->kode => $a->kode.' — '.$a->nama])->toArray();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas Voucher')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('jenis')->label('Jenis')->options(static::jenisOptions())->required()
                        ->disabled(fn (?CbVoucher $r) => $r !== null),
                    Forms\Components\TextInput::make('no_voucher')->label('No voucher')->disabled()->placeholder('Otomatis'),
                    Forms\Components\DatePicker::make('tgl')->label('Tanggal')->required()->default(now()),
                    Forms\Components\Select::make('company_id')->label('Company')->relationship('company', 'name')
                        ->default(fn () => Company::query()->value('id'))->required(),
                    Forms\Components\Select::make('bank_id')->label('Bank')->relationship('bank', 'name')
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)->searchable()->preload(),
                    Forms\Components\Select::make('cost_center_id')->label('Cost Center')->relationship('costCenter', 'name'),
                ]),

            Forms\Components\Section::make('Akun & Nominal')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('akun_kas')->label('Akun Kas/Bank')->options(static::coaOptions())
                        ->searchable()->required(),
                    Forms\Components\Select::make('akun_lawan')->label('Akun Lawan')->options(static::coaOptions())
                        ->searchable()->required(),
                    Forms\Components\TextInput::make('nominal')->label('Nominal')->numeric()->rupiah()->required()->default(0),
                    Forms\Components\TextInput::make('pihak')->label('Pembayar/Penerima')->maxLength(255),
                    Forms\Components\TextInput::make('no_referensi')->label('No referensi')->maxLength(64),
                    Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
                ])
                ->disabled(fn (?CbVoucher $r) => $r !== null && $r->status !== 'DRAFT'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_voucher')->label('No')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge()
                    ->color(fn (CbVoucher $r) => $r->isMasuk() ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('tgl')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('akun_kas')->label('Kas/Bank')->toggleable(),
                Tables\Columns\TextColumn::make('akun_lawan')->label('Lawan')->toggleable(),
                Tables\Columns\TextColumn::make('pihak')->label('Pihak')->limit(24)->toggleable(),
                Tables\Columns\TextColumn::make('nominal')->label('Nominal')->money('IDR', divideBy: 0)->alignEnd(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (string $state) => match ($state) {
                    'DRAFT' => 'gray', 'POSTED' => 'success', 'BATAL' => 'danger', default => 'gray',
                }),
                Tables\Columns\TextColumn::make('jurnal.no_jurnal')->label('Jurnal')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('jenis')->options(static::jenisOptions()),
                Tables\Filters\SelectFilter::make('status')->options([
                    'DRAFT' => 'Draft', 'POSTED' => 'Posted', 'BATAL' => 'Batal',
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (CbVoucher $r) => $r->status === 'DRAFT'),
                Tables\Actions\Action::make('post')
                    ->label('Posting')->icon('heroicon-o-lock-closed')->color('success')
                    ->visible(fn (CbVoucher $r) => $r->status === 'DRAFT')
                    ->requiresConfirmation()
                    ->action(fn (CbVoucher $r) => app(CbVoucherService::class)->post($r)),
                Tables\Actions\Action::make('void')
                    ->label('Void')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (CbVoucher $r) => $r->status !== 'BATAL')
                    ->requiresConfirmation()
                    ->form([Forms\Components\TextInput::make('reason')->label('Alasan')->required()])
                    ->action(fn (CbVoucher $r, array $data) => app(CbVoucherService::class)->void($r, $data['reason'])),
            ])
            ->defaultSort('tgl', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCbVouchers::route('/'),
            'create' => Pages\CreateCbVoucher::route('/create'),
            'edit' => Pages\EditCbVoucher::route('/{record}/edit'),
        ];
    }
}
