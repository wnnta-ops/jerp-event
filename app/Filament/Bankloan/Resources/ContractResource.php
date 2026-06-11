<?php

namespace App\Filament\Bankloan\Resources;

use App\Filament\Bankloan\Resources\ContractResource\Pages;
use App\Filament\Bankloan\Resources\ContractResource\RelationManagers;
use App\Modules\BankLoan\Models\Contract;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Kontrak KPR — data DARI FO (sync). Tak ada create manual; operator KPR memproses
 * status + pre-interview, lalu BI Checking & pengajuan Bank via relation manager.
 */
class ContractResource extends Resource
{
    protected static ?string $model = Contract::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-check';

    protected static ?string $navigationGroup = 'Kontrak KPR';

    protected static ?string $modelLabel = 'Kontrak KPR';

    protected static ?string $pluralModelLabel = 'Kontrak KPR';

    protected static ?int $navigationSort = 10;

    public static function canCreate(): bool
    {
        return false; // kontrak datang dari FO (sync), bukan input manual
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Data Kontrak (dari FO)')
                ->description('Mirror otomatis dari FO Kontrak KPR — read-only.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('contract_number')->label('No. Kontrak')->disabled(),
                    Forms\Components\Placeholder::make('customer_name')->label('Customer')
                        ->content(fn (?Contract $record) => $record?->customer?->name ?? '—'),
                    Forms\Components\TextInput::make('unit_code')->label('Unit')->disabled(),
                    Forms\Components\TextInput::make('booking_number')->label('No. Booking')->disabled(),
                    Forms\Components\TextInput::make('sales_person_name')->label('Sales')->disabled(),
                    Forms\Components\Placeholder::make('project_name')->label('Project')
                        ->content(fn (?Contract $record) => $record?->project?->name ?? '—'),
                    Forms\Components\TextInput::make('total_price')->label('Harga Kontrak')->disabled()->prefix('Rp')->numeric(),
                    Forms\Components\TextInput::make('submission_amount')->label('Nominal KPR')->disabled()->prefix('Rp')->numeric(),
                    Forms\Components\TextInput::make('down_payment')->label('DP')->disabled()->prefix('Rp')->numeric(),
                ]),

            Forms\Components\Section::make('Status & Pre-Interview')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('active_status')->label('Status KPR')
                        ->options([
                            'DRAFT' => 'Draft', 'REGISTERED' => 'Registered',
                            'ACTIVE' => 'Active (akad)', 'CLOSED' => 'Closed (lunas)', 'CANCELLED' => 'Cancelled',
                        ])->required(),
                    Forms\Components\TextInput::make('buyback_status')->label('Status Buyback')->placeholder('—'),
                    Forms\Components\Group::make()->relationship('preInterview')->columns(2)->columnSpanFull()->schema([
                        Forms\Components\TextInput::make('fund_source')->label('Sumber Dana'),
                        Forms\Components\TextInput::make('purchasing_purpose')->label('Tujuan Pembelian'),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('contract_number')->label('No. Kontrak')->badge()->color('primary')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Customer')->searchable()->description(fn ($r) => $r->unit_code),
                Tables\Columns\TextColumn::make('project.name')->label('Project')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('submission_amount')->label('Nominal KPR')->money('IDR')->sortable(),
                Tables\Columns\TextColumn::make('contract_banks_count')->counts('contractBanks')->label('Bank')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('active_status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'ACTIVE' => 'success', 'CLOSED' => 'info', 'CANCELLED' => 'danger', 'REGISTERED' => 'warning', default => 'gray',
                }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('active_status')->label('Status')->options([
                    'DRAFT' => 'Draft', 'REGISTERED' => 'Registered', 'ACTIVE' => 'Active', 'CLOSED' => 'Closed', 'CANCELLED' => 'Cancelled',
                ]),
            ])
            ->actions([Tables\Actions\EditAction::make()->label('Proses')])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\BiCheckingRelationManager::class,
            RelationManagers\ContractBanksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContracts::route('/'),
            'edit' => Pages\EditContract::route('/{record}/edit'),
        ];
    }
}
