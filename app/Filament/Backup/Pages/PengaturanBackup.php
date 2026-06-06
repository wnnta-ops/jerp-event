<?php

namespace App\Filament\Backup\Pages;

use App\Settings\BackupSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class PengaturanBackup extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = 'Jadwal & Lokasi Backup';

    protected static ?string $title = 'Pengaturan Backup';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.backup.pages.pengaturan-backup';

    public ?array $data = [];

    public function mount(): void
    {
        $s = app(BackupSettings::class);
        $this->form->fill([
            'enabled' => $s->enabled,
            'frequency' => $s->frequency,
            'time' => $s->time,
            'weekly_day' => $s->weekly_day,
            'monthly_day' => $s->monthly_day,
            'yearly_month' => $s->yearly_month,
            'yearly_day' => $s->yearly_day,
            'only_db' => $s->only_db,
            'disk' => $s->disk,
            'local_path' => $s->local_path,
            'retention_days' => $s->retention_days,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Jadwal Backup Otomatis')
                ->description('Backup database dijalankan otomatis & dikompres menjadi .zip sesuai jadwal di bawah.')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('enabled')->label('Aktifkan backup terjadwal')->inline(false),
                    Forms\Components\Select::make('frequency')->label('Frekuensi')->live()->required()
                        ->options([
                            'WEEKLY' => 'Mingguan',
                            'MONTHLY' => 'Bulanan',
                            'YEARLY' => 'Tahunan',
                        ]),
                    Forms\Components\TimePicker::make('time')->label('Jam jalan')->seconds(false)->required()
                        ->helperText('Zona waktu '.config('app.timezone')),
                    Forms\Components\Select::make('weekly_day')->label('Hari (mingguan)')
                        ->visible(fn (Forms\Get $get) => $get('frequency') === 'WEEKLY')
                        ->options([
                            0 => 'Minggu', 1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu',
                            4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu',
                        ]),
                    Forms\Components\TextInput::make('monthly_day')->label('Tanggal (bulanan)')->numeric()->minValue(1)->maxValue(28)
                        ->visible(fn (Forms\Get $get) => $get('frequency') === 'MONTHLY'),
                    Forms\Components\Select::make('yearly_month')->label('Bulan (tahunan)')
                        ->visible(fn (Forms\Get $get) => $get('frequency') === 'YEARLY')
                        ->options([
                            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                        ]),
                    Forms\Components\TextInput::make('yearly_day')->label('Tanggal (tahunan)')->numeric()->minValue(1)->maxValue(28)
                        ->visible(fn (Forms\Get $get) => $get('frequency') === 'YEARLY'),
                    Forms\Components\Toggle::make('only_db')->label('Hanya database (tanpa file storage)')->inline(false),
                ]),

            Forms\Components\Section::make('Lokasi Backup')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('disk')->label('Disk tujuan')->live()->required()
                        ->options([
                            'backup_target' => 'Folder lokal (atur path manual)',
                            'local' => 'Disk "local" (storage/app/private)',
                            's3' => 'Amazon S3 (sesuai .env)',
                        ]),
                    Forms\Components\TextInput::make('local_path')->label('Path folder backup')
                        ->visible(fn (Forms\Get $get) => $get('disk') === 'backup_target')
                        ->placeholder(storage_path('app/backups'))
                        ->helperText('Folder tujuan file .zip backup. Pastikan dapat ditulis oleh aplikasi.'),
                    Forms\Components\TextInput::make('retention_days')->label('Retensi (hari)')->numeric()->minValue(1)->required()
                        ->helperText('Backup lebih lama dari ini akan dibersihkan otomatis.'),
                ]),
        ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Simpan')->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $s = app(BackupSettings::class);

        $s->enabled = (bool) $data['enabled'];
        $s->frequency = $data['frequency'];
        $s->time = $data['time'];
        $s->weekly_day = (int) $data['weekly_day'];
        $s->monthly_day = (int) $data['monthly_day'];
        $s->yearly_month = (int) $data['yearly_month'];
        $s->yearly_day = (int) $data['yearly_day'];
        $s->only_db = (bool) $data['only_db'];
        $s->disk = $data['disk'];
        $s->local_path = (string) ($data['local_path'] ?? '');
        $s->retention_days = (int) $data['retention_days'];
        $s->save();

        Notification::make()->title('Pengaturan backup disimpan')->success()->send();
    }
}
