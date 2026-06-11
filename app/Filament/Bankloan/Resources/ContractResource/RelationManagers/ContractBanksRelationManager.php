<?php

namespace App\Filament\Bankloan\Resources\ContractResource\RelationManagers;

use App\Models\Bank;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Pengajuan ke Bank per kontrak (multi-bank). Tahap lanjut (syarat/interview/SP3K/akad)
 * menyusul sebagai relation manager bertingkat — di build ini fokus assign bank + status.
 */
class ContractBanksRelationManager extends RelationManager
{
    protected static string $relationship = 'contractBanks';

    protected static ?string $title = 'Pengajuan Bank';

    protected static ?string $icon = 'heroicon-o-building-library';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('bank_id')->label('Bank')->required()->searchable()
                ->options(fn () => Bank::orderBy('name')->pluck('name', 'id')),
            Forms\Components\Select::make('status')->label('Status')->default('PENGAJUAN')->options([
                'PENGAJUAN' => 'Pengajuan', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected', 'AKAD' => 'Akad',
            ])->required(),
            Forms\Components\Select::make('requirement_status')->label('Status Syarat')->options([
                'INCOMPLETE' => 'Belum Lengkap', 'COMPLETE' => 'Lengkap', 'VERIFYING' => 'Verifikasi',
            ]),
            Forms\Components\TextInput::make('approved_amount')->label('Disetujui (Rp)')->numeric()->prefix('Rp'),
            Forms\Components\TextInput::make('approved_description')->label('Keterangan Persetujuan')->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('bank.name')->label('Bank')->weight('bold'),
                Tables\Columns\TextColumn::make('requirement_status')->label('Syarat')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('approved_amount')->label('Disetujui')->money('IDR')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                    'APPROVED', 'AKAD' => 'success', 'REJECTED' => 'danger', default => 'warning',
                }),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Ajukan ke Bank')])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}
