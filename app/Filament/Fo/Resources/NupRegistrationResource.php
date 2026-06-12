<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\NupRegistrationResource\Pages;
use App\Modules\FO\Launching\Models\NupRegistration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NupRegistrationResource extends Resource
{
    protected static ?string $model = NupRegistration::class;

    // Daftar pendaftaran NUP (launching) saja; Booked Manual terpisah.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('jenis', 'NUP');
    }

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'Launching & NUP';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Pendaftaran NUP';

    protected static ?string $pluralModelLabel = 'Pendaftaran NUP';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Event')->columns(3)->schema([
                Forms\Components\Select::make('nup_event_id')->label('Event')
                    ->relationship('event', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->name)
                    ->required()->searchable()->preload(),
                Forms\Components\TextInput::make('no_nup')->label('No NUP')->disabled()->dehydrated()->placeholder('Otomatis'),
                Forms\Components\TextInput::make('urutan')->label('Urutan')->numeric()->required(),
            ]),

            Forms\Components\Section::make('Pendaftar')->columns(2)->schema([
                Forms\Components\Select::make('customer_id')->label('Customer (opsional)')
                    ->relationship('customer', 'full_name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->code.' — '.$record->full_name)
                    ->searchable(['code', 'full_name', 'nik'])->preload(),
                Forms\Components\TextInput::make('nama_pendaftar')->label('Nama pendaftar')->required()->maxLength(255),
                Forms\Components\TextInput::make('nik')->label('NIK')->maxLength(32),
                Forms\Components\TextInput::make('phone')->label('HP')->tel()->maxLength(32),
                Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(255),
                Forms\Components\Select::make('channel')->label('Channel')->options([
                    'PUBLIC' => 'Public web', 'ADMIN' => 'Admin', 'AGENT' => 'Agent',
                ])->default('ADMIN'),
            ]),

            Forms\Components\Section::make('Status')->columns(3)->schema([
                Forms\Components\DateTimePicker::make('tgl_daftar')->label('Tgl daftar')->default(now())->required(),
                Forms\Components\DateTimePicker::make('tgl_check_in')->label('Tgl check-in'),
                Forms\Components\DateTimePicker::make('tgl_payment')->label('Tgl payment'),
                Forms\Components\TextInput::make('booking_fee_paid')->label('Booking fee dibayar')->numeric()->rupiah()->default(0),
                Forms\Components\Select::make('status')->label('Status')->options([
                    'DAFTAR' => 'Daftar', 'CHECK_IN' => 'Check-in', 'BAYAR' => 'Bayar',
                    'PILIH_UNIT' => 'Pilih unit', 'TIDAK_HADIR' => 'Tidak hadir', 'BATAL' => 'Batal',
                ])->default('DAFTAR')->required(),
                Forms\Components\Textarea::make('keterangan')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('event.code')->label('Event')->badge()->sortable(),
            Tables\Columns\TextColumn::make('no_nup')->label('No NUP')->badge()->color('primary')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('urutan')->label('Urutan')->numeric()->alignEnd()->sortable(),
            Tables\Columns\TextColumn::make('nama_pendaftar')->label('Pendaftar')->searchable(),
            Tables\Columns\TextColumn::make('phone')->label('HP')->toggleable(),
            Tables\Columns\TextColumn::make('channel')->label('Channel')->badge()->color('gray'),
            Tables\Columns\TextColumn::make('tgl_daftar')->label('Tgl daftar')->dateTime('d M Y H:i')->toggleable(),
            Tables\Columns\TextColumn::make('booking_fee_paid')->label('BF paid')->money('IDR', divideBy: 0)->alignEnd()->toggleable(),
            Tables\Columns\TextColumn::make('status')->label('Status')->badge()->color(fn (?string $state) => match ($state) {
                'DAFTAR' => 'gray', 'CHECK_IN' => 'info', 'BAYAR' => 'success',
                'PILIH_UNIT' => 'primary', 'TIDAK_HADIR' => 'warning', 'BATAL' => 'danger',
                default => 'gray',
            }),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'DAFTAR' => 'Daftar', 'CHECK_IN' => 'Check-in', 'BAYAR' => 'Bayar',
                'PILIH_UNIT' => 'Pilih unit', 'TIDAK_HADIR' => 'Tidak hadir', 'BATAL' => 'Batal',
            ]),
            Tables\Filters\SelectFilter::make('nup_event_id')->relationship('event', 'name')->label('Event'),
            Tables\Filters\SelectFilter::make('channel')->options([
                'PUBLIC' => 'Public', 'ADMIN' => 'Admin', 'AGENT' => 'Agent',
            ]),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('checkIn')
                ->label('Check-in')
                ->icon('heroicon-o-check-badge')
                ->color('info')
                ->visible(fn (NupRegistration $r) => $r->status === 'DAFTAR')
                ->action(function (NupRegistration $r) {
                    $r->update(['status' => 'CHECK_IN', 'tgl_check_in' => now()]);
                    Notification::make()->title('Check-in')->success()->send();
                }),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('urutan');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNupRegistrations::route('/'),
            'create' => Pages\CreateNupRegistration::route('/create'),
            'edit' => Pages\EditNupRegistration::route('/{record}/edit'),
        ];
    }
}
