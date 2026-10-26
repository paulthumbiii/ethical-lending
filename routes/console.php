<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('loans:accrue-interest')
    ->dailyAt('00:05')
    ->timezone('Africa/Nairobi')
    ->withoutOverlapping();

Schedule::command('loans:detect-overdue')
    ->dailyAt('00:20')
    ->timezone('Africa/Nairobi')
    ->withoutOverlapping();

Schedule::command('loans:review-promises')
    ->dailyAt('00:30')
    ->timezone('Africa/Nairobi')
    ->withoutOverlapping();