<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    /** @var \Illuminate\Console\Command $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Scheduled jobs (Asia/Jakarta) ────────────────────────────────────────────
Schedule::command('hold:expire')->dailyAt('00:30')->onOneServer();
Schedule::command('booking:expire')->dailyAt('00:40')->onOneServer();
Schedule::command('billing:jatuh-tempo')->dailyAt('01:00')->onOneServer();
Schedule::command('pjt:generate')->dailyAt('02:00')->onOneServer();
Schedule::command('tunggakan:update')->dailyAt('02:30')->onOneServer();
