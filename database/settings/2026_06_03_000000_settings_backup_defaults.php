<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('backup.enabled', false);
        $this->migrator->add('backup.frequency', 'WEEKLY');     // WEEKLY|MONTHLY|YEARLY
        $this->migrator->add('backup.time', '02:00');
        $this->migrator->add('backup.weekly_day', 1);           // 1 = Senin
        $this->migrator->add('backup.monthly_day', 1);          // tgl 1
        $this->migrator->add('backup.yearly_month', 1);         // Januari
        $this->migrator->add('backup.yearly_day', 1);
        $this->migrator->add('backup.only_db', true);
        $this->migrator->add('backup.disk', 'backup_target');
        $this->migrator->add('backup.local_path', storage_path('app/backups'));
        $this->migrator->add('backup.retention_days', 365);
    }
};
