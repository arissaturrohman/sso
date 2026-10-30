<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sitemap di-regenerate harian agar <lastmod> selalu segar.
// Butuh cron server:  * * * * * cd /path/ke/app && php artisan schedule:run
Schedule::command('sitemap:generate')->dailyAt('01:30');
