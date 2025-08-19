<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule the room usage cleanup command
Schedule::command('room-usage:cleanup')
    ->weekly()
    ->sundays()
    ->at('02:00')
    ->description('Clean up old room usage records older than 3 months');

// Schedule the conversion of old public posts to private
Schedule::command('posts:convert-old-public')
    ->hourly()
    ->description('Convert public posts older than 24 hours to private visibility');

// Schedule cleanup of stale online members
Schedule::command('online-members:cleanup')
    ->everyFiveMinutes()
    ->description('Clean up stale online members (older than 8 minutes)');
