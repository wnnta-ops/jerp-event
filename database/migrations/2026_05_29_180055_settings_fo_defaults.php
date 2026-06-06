<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('fo.default_booking_fee', 5_000_000);
        $this->migrator->add('fo.default_tenor_bulan', 12);
        $this->migrator->add('fo.default_dp_persen', 20.0);
        $this->migrator->add('fo.reservasi_expiry_days', 7);
        $this->migrator->add('fo.tts_auto_confirm_minutes', 0);          // 0 = no auto
        $this->migrator->add('fo.recaptcha_enabled', false);
        $this->migrator->add('fo.recaptcha_min_score', 0.5);
        $this->migrator->add('fo.komisi_pph_default_persen', 2.5);
        $this->migrator->add('fo.notification_email', 'finance@jerp.test');
    }
};
