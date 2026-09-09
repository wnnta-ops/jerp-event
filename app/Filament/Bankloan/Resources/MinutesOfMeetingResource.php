<?php

namespace App\Filament\Bankloan\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Bankloan\Resources\MinutesOfMeetingResource\Pages;
use App\Models\Bank;
use App\Models\Project;
use App\Modules\BankLoan\Models\MinutesOfMeeting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MinutesOfMeetingResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = MinutesOfMeeting::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Meeting';

    protected static ?string $modelLabel = 'MoM';

    protected static ?string $pluralModelLabel = 'Minutes of Meeting';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('Judul')->required()->maxLength(255)->columnSpanFull(),
            Forms\Components\DatePicker::make('meeting_date')->label('Tanggal Meeting'),
            Forms\Components\Select::make('bank_id')->label('Bank')->options(fn () => Bank::orderBy('name')->pluck('name', 'id'))->searchable(),
            Forms\Components\Select::make('project_id')->label('Project')->options(fn () => Project::orderBy('name')->pluck('name', 'id'))->searchable(),
            Forms\Components\Textarea::make('description')->label('Hasil / Catatan')->rows(4)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('meeting_date')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('title')->label('Judul')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('bank.name')->label('Bank')->placeholder('—'),
                Tables\Columns\TextColumn::make('project.name')->label('Project')->placeholder('—'),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('meeting_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMinutesOfMeetings::route('/')];
    }
}
