<?php

namespace Tests\Feature\Backup;

use App\Providers\AppServiceProvider;
use App\Settings\BackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_settings_have_sensible_defaults(): void
    {
        $s = app(BackupSettings::class);

        $this->assertFalse($s->enabled);
        $this->assertSame('WEEKLY', $s->frequency);
        $this->assertSame('backup_target', $s->disk);
        $this->assertSame(365, $s->retention_days);
        $this->assertTrue($s->only_db);
    }

    public function test_backup_settings_persist(): void
    {
        $s = app(BackupSettings::class);
        $s->enabled = true;
        $s->frequency = 'MONTHLY';
        $s->monthly_day = 15;
        $s->local_path = '/data/backups';
        $s->retention_days = 90;
        $s->save();

        app()->forgetInstance(BackupSettings::class);
        $again = app(BackupSettings::class);

        $this->assertTrue($again->enabled);
        $this->assertSame('MONTHLY', $again->frequency);
        $this->assertSame(15, $again->monthly_day);
        $this->assertSame('/data/backups', $again->local_path);
        $this->assertSame(90, $again->retention_days);
    }

    public function test_runtime_config_override_points_backup_to_configured_location(): void
    {
        $s = app(BackupSettings::class);
        $s->disk = 'backup_target';
        $s->local_path = '/data/backups';
        $s->retention_days = 120;
        $s->save();

        // Re-jalankan boot hook (AppServiceProvider::applyBackupSettings) via re-register.
        (new AppServiceProvider(app()))->boot();

        $this->assertSame(['backup_target'], config('backup.backup.destination.disks'));
        $this->assertSame('/data/backups', config('filesystems.disks.backup_target.root'));
        $this->assertSame(120, config('backup.cleanup.default_strategy.keep_all_backups_for_all_days'));
    }
}
