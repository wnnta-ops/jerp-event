<?php

namespace App\Filament\Bankloan\Resources;

use App\Filament\Bankloan\Resources\RetentionRealizationResource\Pages;
use App\Modules\BankLoan\Models\RetentionRealization;
use App\Modules\BankLoan\Models\RetentionSubmission;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RetentionRealizationResource extends Resource
{
    protected static ?string $model = RetentionRealization::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square';

    protected static ?string $navigationGroup = 'Realisasi & Retensi';

    protected static ?string $modelLabel = 'Realisasi Retensi (RR)';

    protected static ?string $pluralModelLabel = 'Realisasi Retensi (RR)';

    protected static ?int $navigationSort = 30;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('number')->label('No. RR')->disabled()->dehydrated(false)->visibleOn('edit'),
            Forms\Components\Select::make('retention_submission_id')->label('Pengajuan Retensi (PR)')->required()->searchable()
                ->options(fn () => RetentionSubmission::query()->whereIn('status', ['APPROVED', 'REALIZED'])->get()
                    ->mapWithKeys(fn ($p) => [$p->id => $p->number.' — '.($p->contract_number ?? '').' (Rp '.number_format((float) $p->requested_amount, 0, ',', '.').')'])),
            Forms\Components\DatePicker::make('realization_date')->label('Tgl Realisasi')->default(now()),
            Forms\Components\TextInput::make('realized_amount')->label('Nominal Dicairkan (Rp)')->numeric()->default(0)->prefix('Rp'),
            Forms\Components\TextInput::make('remaining_amount')->label('Sisa Retensi (Rp)')->numeric()->default(0)->prefix('Rp')
                ->helperText('Sisa harus 0 agar retensi dianggap selesai (kontrak boleh CLOSED).'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')->label('No. RR')->badge()->color('success')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('submission.number')->label('PR')->placeholder('—'),
                Tables\Columns\TextColumn::make('realization_date')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('realized_amount')->label('Dicairkan')->money('IDR'),
                Tables\Columns\TextColumn::make('remaining_amount')->label('Sisa')->money('IDR')
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'success'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListRetentionRealizations::route('/')];
    }
}
