<?php

use App\Http\Controllers\Api\UsageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// POST /usage — non-prefixed alias with identical middleware stack
Route::middleware(['auth.api_key', 'throttle:api-key'])
    ->post('/usage', [UsageController::class, 'store']);
