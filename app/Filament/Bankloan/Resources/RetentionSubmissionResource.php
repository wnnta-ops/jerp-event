<?php

namespace App\Filament\Bankloan\Resources;

use App\Filament\Bankloan\Resources\RetentionSubmissionResource\Pages;
use App\Modules\BankLoan\Models\ContractBankRetention;
use App\Modules\BankLoan\Models\RetentionSubmission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RetentionSubmissionResource extends Resource
{
    protected static ?string $model = RetentionSubmission::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-on-square';

    protected static ?string $navigationGroup = 'Realisasi & Retensi';

    protected static ?string $modelLabel = 'Pengajuan Retensi (PR)';

    protected static ?string $pluralModelLabel = 'Pengajuan Retensi (PR)';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('number')->label('No. PR')->disabled()->dehydrated(false)->visibleOn('edit'),
            Forms\Components\Select::make('contract_bank_retention_id')->label('Retensi')->required()->searchable()
                ->options(fn () => ContractBankRetention::with('contractBank.contract')->get()
                    ->mapWithKeys(fn ($r) => [$r->id => 'Retensi #'.$r->id.' — '.($r->contractBank?->contract?->contract_number ?? '').' (Rp '.number_format((float) $r->amount_held, 0, ',', '.').')']))
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    $r = ContractBankRetention::with('contractBank.contract')->find($state);
                    $set('contract_number', $r?->contractBank?->contract?->contract_number);
                }),
            Forms\Components\DatePicker::make('submission_date')->label('Tgl Pengajuan')->default(now()),
            Forms\Components\TextInput::make('requested_amount')->label('Nominal Diminta (Rp)')->numeric()->default(0)->rupiah(),
            Forms\Components\TextInput::make('sales_person_name')->label('Sales'),
            Forms\Components\TextInput::make('contract_number')->label('No. Kontrak')->disabled()->dehydrated(),
            Forms\Components\Select::make('status')->label('Status')->default('DRAFT')
                ->options(['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'APPROVED' => 'Approved', 'REALIZED' => 'Realized', 'REJECTED' => 'Rejected']),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('No. PR')->badge()->color('warning')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('contract_number')->label('Kontrak')->placeholder('—'),
                Tables\Columns\TextColumn::make('submission_date')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('requested_amount')->label('Diminta')->money('IDR'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'APPROVED', 'REALIZED' => 'success', 'REJECTED' => 'danger', 'SUBMITTED' => 'info', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'APPROVED' => 'Approved', 'REALIZED' => 'Realized', 'REJECTED' => 'Rejected']),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRetentionSubmissions::route('/')];
    }
}
