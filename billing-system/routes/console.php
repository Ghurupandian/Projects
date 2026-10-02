<?php

use App\Jobs\AggregateDailyUsageJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $today = now()->toDateString();

    AggregateDailyUsageJob::dispatch($today);
    AggregateDailyUsageJob::dispatch(now()->subDay()->toDateString());
})->everyFiveMinutes()->name('aggregate-daily-usage')->withoutOverlapping();
