<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Automatic backup — checks settings from DB (used on Linux with cron)
Schedule::call(function () {
    $settings = \App\Models\BackupSetting::first();
    if ($settings && $settings->auto_backup_enabled) {
        [$hour, $min] = explode(':', $settings->auto_backup_time ?? '03:00');
        if (now()->hour === (int)$hour && now()->minute === (int)$min) {
            Artisan::call('backup:run');
        }
    }
})->everyMinute();
