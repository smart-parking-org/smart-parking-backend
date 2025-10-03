<?php

use App\Http\Controllers\SampleController;
use Illuminate\Support\Facades\Route;

Route::prefix('payment')->group(function () {
    Route::get('ping', [SampleController::class, 'ping']);
});


