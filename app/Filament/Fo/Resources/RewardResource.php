<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\RewardResource\Pages;
use App\Modules\FO\Komisi\Models\Reward;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RewardResource extends Resource
{
    protected static ?string $model = Reward::class;

    protected static ?string $navigationIcon = 'heroicon-o-trophy';

    protected static ?string $navigationGroup = 'Komisi';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'Reward';

    protected static ?string $pluralModelLabel = 'Reward';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Reward')->columns(3)->schema([
                Forms\Components\TextInput::make('no_reward')->label('No reward')->disabled()->dehydrated()->placeholder('Otomatis'),
                Forms\Components\DatePicker::make('tgl_reward')->label('Tanggal')->default(now())->required(),
                Forms\Components\Select::make('agent_id')->label('Agent')
                    ->relationship('agent', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                    ->searchable()->preload()->required(),
                Forms\Components\Select::make('jenis')->label('Jenis')->options([
                    'BONUS' => 'Bonus', 'TROPI' => 'Tropi', 'TIKET' => 'Tiket',
                    'CASHBACK' => 'Cashback', 'LAIN' => 'Lain',
                ])->default('BONUS')->required(),
                Forms\Components\TextInput::make('nama_reward')->label('Nama reward')->required()->maxLength(255),
                Forms\Components\TextInput::make('amount')->label('Nilai')->numeric()->prefix('Rp')->default(0),
                Forms\Components\Select::make('status')->label('Status')->options([
                    'DRAFT' => 'Draft', 'APPROVED' => 'Approved', 'CLAIMED' => 'Claimed', 'BATAL' => 'Batal',
                ])->default('DRAFT')->required(),
                Forms\Components\DatePicker::make('tgl_claim')->label('Tgl claim'),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('no_reward')->label('No reward')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('tgl_reward')->label('Tgl')->date('d M Y')->sortable(),
            Tables\Columns\TextColumn::make('agent.name')->label('Agent')->searchable(),
            Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge(),
            Tables\Columns\TextColumn::make('nama_reward')->label('Reward')->searchable(),
            Tables\Columns\TextColumn::make('amount')->label('Nilai')->money('IDR', divideBy: 0)->alignEnd(),
            Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                'DRAFT' => 'gray', 'APPROVED' => 'info', 'CLAIMED' => 'success', 'BATAL' => 'danger', default => 'gray',
            }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'DRAFT' => 'Draft', 'APPROVED' => 'Approved', 'CLAIMED' => 'Claimed', 'BATAL' => 'Batal',
            ]),
            Tables\Filters\SelectFilter::make('jenis')->options([
                'BONUS' => 'Bonus', 'TROPI' => 'Tropi', 'TIKET' => 'Tiket', 'CASHBACK' => 'Cashback', 'LAIN' => 'Lain',
            ]),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('claim')
                ->label('Claim')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (Reward $r) => $r->status === 'APPROVED')
                ->action(function (Reward $r) {
                    $r->update(['status' => 'CLAIMED', 'tgl_claim' => now()->toDateString()]);
                    Notification::make()->title('Reward claimed')->success()->send();
                }),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('tgl_reward', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRewards::route('/'),
            'create' => Pages\CreateReward::route('/create'),
            'edit' => Pages\EditReward::route('/{record}/edit'),
        ];
    }
}
