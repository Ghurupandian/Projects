<?php

use App\Jobs\AggregateDailyUsageJob;
use App\Jobs\PrepareCycleInvoicesJob;
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

Schedule::call(function (): void {
    $previousMonth = now()->subMonthNoOverflow();

    PrepareCycleInvoicesJob::dispatch(
        $previousMonth->copy()->startOfMonth()->toDateString(),
        $previousMonth->copy()->endOfMonth()->toDateString(),
    );
})->monthlyOn(1, '00:10')->name('prepare-cycle-invoices')->withoutOverlapping();
