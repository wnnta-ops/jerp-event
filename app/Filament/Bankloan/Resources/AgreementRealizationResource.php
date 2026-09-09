<?php

namespace App\Filament\Bankloan\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bankloan\Resources\AgreementRealizationResource\Pages;
use App\Modules\BankLoan\Models\AgreementRealization;
use App\Modules\BankLoan\Models\ContractBankAgreement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AgreementRealizationResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = AgreementRealization::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Realisasi & Retensi';

    protected static ?string $modelLabel = 'Realisasi Akad (RA)';

    protected static ?string $pluralModelLabel = 'Realisasi Akad (RA)';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('number')->label('No. RA')->disabled()->dehydrated(false)
                ->placeholder('otomatis (RA-...)')->visibleOn('edit'),
            Forms\Components\Select::make('contract_bank_agreement_id')->label('Akad')->required()->searchable()
                ->options(fn () => ContractBankAgreement::with('contractBank.contract')->get()
                    ->mapWithKeys(fn ($a) => [$a->id => ($a->agreement_number ?: 'Akad #'.$a->id).' — '.($a->contractBank?->contract?->contract_number ?? '')])),
            Forms\Components\DatePicker::make('realization_date')->label('Tgl Realisasi')->default(now()),
            Forms\Components\TextInput::make('disbursed_amount')->label('Nominal Cair (Rp)')->numeric()->default(0)->rupiah(),
            Forms\Components\TextInput::make('excess_realization_amount')->label('Selisih/Kelebihan (Rp)')->numeric()->default(0)->rupiah(),
            Forms\Components\Select::make('status')->label('Status')->default('DRAFT')
                ->options(['DRAFT' => 'Draft', 'POSTED' => 'Posted', 'CANCELLED' => 'Cancelled']),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('No. RA')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('agreement.contractBank.contract.contract_number')->label('Kontrak')->placeholder('—'),
                Tables\Columns\TextColumn::make('realization_date')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('disbursed_amount')->label('Cair')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('excess_realization_amount')->label('Selisih')->money('IDR'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'POSTED' => 'success', 'CANCELLED' => 'danger', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['DRAFT' => 'Draft', 'POSTED' => 'Posted', 'CANCELLED' => 'Cancelled']),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAgreementRealizations::route('/')];
    }
}
