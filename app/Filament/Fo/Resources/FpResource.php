<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\FpResource\Pages;
use App\Models\Project;
use App\Modules\FO\FinanceAR\Models\Fp;
use App\Modules\FO\FinanceAR\Models\Tts;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FpResource extends Resource
{
    protected static ?string $model = Fp::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $modelLabel = 'Faktur Pajak';

    protected static ?string $pluralModelLabel = 'Faktur Pajak (FP)';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('no_fp')->label('No. Faktur Pajak')->required()->maxLength(255),
            Forms\Components\DatePicker::make('tgl_terima')->label('Tgl Terima')->default(now()),
            Forms\Components\Select::make('tts_id')->label('TTS / Kwitansi')->searchable()
                ->options(fn () => Tts::query()->latest()->limit(300)->get()->mapWithKeys(fn ($t) => [$t->id => $t->no_tts])),
            Forms\Components\TextInput::make('no_kwitansi')->label('No. Kwitansi (manual)'),
            Forms\Components\Select::make('project_id')->label('Project')->options(fn () => Project::orderBy('name')->pluck('name', 'id'))->searchable(),
            Forms\Components\Select::make('status')->label('Status')->default('BELUM_TERPAKAI')
                ->options(['BELUM_TERPAKAI' => 'Belum Terpakai', 'TERPAKAI' => 'Terpakai']),
            Forms\Components\Textarea::make('keterangan')->label('Keterangan')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('no_fp')->label('No. FP')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tgl_terima')->label('Tgl Terima')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('tts.no_tts')->label('TTS')->placeholder('—'),
                Tables\Columns\TextColumn::make('project.name')->label('Project')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $s) => $s === 'TERPAKAI' ? 'success' : 'gray'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['BELUM_TERPAKAI' => 'Belum Terpakai', 'TERPAKAI' => 'Terpakai'])])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFps::route('/')];
    }
}
