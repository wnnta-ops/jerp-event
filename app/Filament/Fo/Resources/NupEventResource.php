<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\NupEventResource\Pages;
use App\Modules\FO\Launching\Models\NupEvent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class NupEventResource extends Resource
{
    protected static ?string $model = NupEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Launching & NUP';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Event Launching';

    protected static ?string $pluralModelLabel = 'Event Launching (NUP)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Event')->columns(2)->schema([
                Forms\Components\TextInput::make('code')->label('Kode event')->required()->maxLength(32)->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('name')->label('Nama event')->required()->maxLength(255),
                Forms\Components\Select::make('project_id')->label('Proyek')->relationship('project', 'name')->required()->searchable()->preload(),
                Forms\Components\DatePicker::make('tgl_launching')->label('Tgl launching')->required(),
                Forms\Components\DateTimePicker::make('open_registration_at')->label('Buka pendaftaran'),
                Forms\Components\DateTimePicker::make('close_registration_at')->label('Tutup pendaftaran'),
                Forms\Components\TextInput::make('kuota')->label('Kuota')->numeric()->default(0),
                Forms\Components\TextInput::make('booking_fee')->label('Booking fee')->numeric()->rupiah()->default(0),
                Forms\Components\Select::make('status')->label('Status')->options([
                    'DRAFT' => 'Draft', 'OPEN' => 'Buka pendaftaran', 'CLOSED' => 'Tutup pendaftaran',
                    'EXECUTED' => 'Sudah dilaksanakan', 'BATAL' => 'Batal',
                ])->default('DRAFT')->required(),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('code')->label('Kode')->badge()->searchable()->sortable(),
            Tables\Columns\TextColumn::make('name')->label('Nama')->searchable(),
            Tables\Columns\TextColumn::make('project.name')->label('Proyek')->toggleable(),
            Tables\Columns\TextColumn::make('tgl_launching')->label('Tgl launching')->date('d M Y')->sortable(),
            Tables\Columns\TextColumn::make('kuota')->label('Kuota')->numeric()->alignEnd(),
            Tables\Columns\TextColumn::make('registrations_count')->counts('registrations')->label('Pendaftar')->alignEnd(),
            Tables\Columns\TextColumn::make('booking_fee')->label('BF')->money('IDR', divideBy: 0)->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                'DRAFT' => 'gray', 'OPEN' => 'success', 'CLOSED' => 'warning',
                'EXECUTED' => 'primary', 'BATAL' => 'danger', default => 'gray',
            }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'DRAFT' => 'Draft', 'OPEN' => 'Open', 'CLOSED' => 'Closed', 'EXECUTED' => 'Executed', 'BATAL' => 'Batal',
            ]),
            Tables\Filters\SelectFilter::make('project_id')->relationship('project', 'name')->label('Proyek'),
        ])->actions([
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('tgl_launching', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNupEvents::route('/'),
            'create' => Pages\CreateNupEvent::route('/create'),
            'edit' => Pages\EditNupEvent::route('/{record}/edit'),
        ];
    }
}
