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

    protected static ?string $modelLabel = 'Faktur Penjualan';

    protected static ?string $pluralModelLabel = 'Faktur Penjualan';

    protected static ?int $navigationSort = 50;

    // Faktur Penjualan terbit otomatis dari kontrak — tidak dibuat/ubah/hapus manual.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('no_fp')->label('No. Faktur Penjualan')->required()->maxLength(255),
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
            ->modifyQueryUsing(fn ($query) => $query->with(['kontrak.customer', 'tts']))
            ->columns([
                Tables\Columns\TextColumn::make('no_fp')->label('No. Faktur')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tgl_faktur')->label('Tgl Faktur')->date('d M Y')->sortable()
                    ->placeholder(fn ($record) => optional($record->tgl_terima)->format('d M Y') ?? '—'),
                Tables\Columns\TextColumn::make('kontrak.no_kontrak')->label('Kontrak')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('kontrak.customer.full_name')->label('Customer')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('kontrak.total_kontrak')->label('Nilai')->money('IDR')->alignEnd()->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->color(fn (?string $state) => in_array($state, ['TERBIT', 'TERPAKAI']) ? 'success' : 'gray'),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(['TERBIT' => 'Terbit', 'BELUM_TERPAKAI' => 'Belum Terpakai', 'TERPAKAI' => 'Terpakai'])])
            ->actions([
                Tables\Actions\Action::make('cetak')->label('Cetak')->icon('heroicon-o-printer')->color('success')
                    ->url(fn ($record) => $record->kontrak_id ? route('fo.cetak.faktur', $record) : null, shouldOpenInNewTab: true)
                    ->visible(fn ($record) => (bool) $record->kontrak_id),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFps::route('/')];
    }
}
