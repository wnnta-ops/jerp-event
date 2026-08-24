<?php

namespace App\Filament\Backup\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Backup\Resources\BackupScheduleResource\Pages;
use App\Models\BackupSchedule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BackupScheduleResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = BackupSchedule::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Jadwal & Lokasi Backup';

    protected static ?string $modelLabel = 'Jadwal Backup';

    protected static ?string $pluralModelLabel = 'Jadwal & Lokasi Backup';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'jadwal-backup';

    private const FREK = ['DAILY' => 'Harian', 'WEEKLY' => 'Mingguan', 'MONTHLY' => 'Bulanan', 'YEARLY' => 'Tahunan'];

    private const HARI = [0 => 'Minggu', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu'];

    private const BULAN = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

    private const DISK = ['backup_target' => 'Folder lokal (atur path manual)', 'local' => 'Local (storage/app)', 's3' => 'Amazon S3'];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Jadwal Backup Otomatis')->columns(2)
                ->description('Backup database otomatis & dikompres jadi .zip sesuai jadwal.')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nama jadwal')->required()->default('Jadwal Backup'),
                    Forms\Components\Toggle::make('enabled')->label('Aktifkan jadwal ini')->inline(false)->default(true),
                    Forms\Components\Select::make('frequency')->label('Frekuensi')->live()->required()->options(self::FREK)->default('WEEKLY'),
                    Forms\Components\TimePicker::make('time')->label('Jam jalan')->seconds(false)->required()->default('02:00')
                        ->helperText('Zona waktu '.config('app.timezone', 'UTC')),
                    Forms\Components\Select::make('weekly_day')->label('Hari (mingguan)')->options(self::HARI)->default(1)
                        ->visible(fn (Forms\Get $get) => $get('frequency') === 'WEEKLY'),
                    Forms\Components\TextInput::make('monthly_day')->label('Tanggal (bulanan)')->numeric()->minValue(1)->maxValue(28)->default(1)
                        ->visible(fn (Forms\Get $get) => $get('frequency') === 'MONTHLY'),
                    Forms\Components\Select::make('yearly_month')->label('Bulan (tahunan)')->options(self::BULAN)->default(1)
                        ->visible(fn (Forms\Get $get) => $get('frequency') === 'YEARLY'),
                    Forms\Components\TextInput::make('yearly_day')->label('Tanggal (tahunan)')->numeric()->minValue(1)->maxValue(28)->default(1)
                        ->visible(fn (Forms\Get $get) => $get('frequency') === 'YEARLY'),
                    Forms\Components\Select::make('scope')->label('Cakupan backup')->required()->native(false)
                        ->options([
                            'DB_ALL' => 'Database FO + BO',
                            'DB_FO' => 'Database FO saja (jerp)',
                            'DB_BO' => 'Database BO saja (ipro_bo)',
                            'FULL' => 'Database FO + BO + Berkas (lengkap)',
                        ])->default('DB_ALL')
                        ->helperText('Pilih apa yang di-backup: database tertentu, file aplikasi, atau lengkap.'),
                ]),

            Forms\Components\Section::make('Lokasi Backup')->columns(2)->schema([
                Forms\Components\Select::make('disk')->label('Disk tujuan')->live()->required()->options(self::DISK)->default('backup_target'),
                Forms\Components\TextInput::make('local_path')->label('Path folder backup')
                    ->visible(fn (Forms\Get $get) => $get('disk') === 'backup_target')
                    ->default('/var/www/html/storage/app/backups')
                    ->helperText('Folder tujuan file .zip. Pastikan dapat ditulis aplikasi.'),
                Forms\Components\TextInput::make('retention_days')->label('Retensi (hari)')->numeric()->minValue(1)->required()->default(365)
                    ->helperText('Backup lebih lama dari ini dibersihkan otomatis.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nama')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('frequency')->label('Frekuensi')->badge()
                    ->formatStateUsing(fn (?string $state) => self::FREK[$state] ?? $state),
                Tables\Columns\TextColumn::make('jadwal')->label('Jadwal')
                    ->state(fn (BackupSchedule $r) => match ($r->frequency) {
                        'DAILY' => 'Tiap hari '.$r->time,
                        'WEEKLY' => (self::HARI[$r->weekly_day] ?? '?').' '.$r->time,
                        'MONTHLY' => 'Tgl '.$r->monthly_day.' '.$r->time,
                        'YEARLY' => $r->yearly_day.' '.(self::BULAN[$r->yearly_month] ?? '').' '.$r->time,
                        default => $r->time,
                    }),
                Tables\Columns\TextColumn::make('scope')->label('Cakupan')->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'DB_ALL' => 'DB FO+BO', 'DB_FO' => 'DB FO', 'DB_BO' => 'DB BO', 'APP' => 'APP', 'FULL' => 'Lengkap', default => $state ?? '—',
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'DB_FO' => 'success', 'DB_BO' => 'warning', 'APP' => 'gray', 'FULL' => 'info', default => 'primary',
                    }),
                Tables\Columns\TextColumn::make('disk')->label('Disk')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('retention_days')->label('Retensi')->suffix(' hari')->alignEnd(),
                Tables\Columns\IconColumn::make('enabled')->label('Aktif')->boolean(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()->label('Buat Jadwal')])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBackupSchedules::route('/')];
    }
}
