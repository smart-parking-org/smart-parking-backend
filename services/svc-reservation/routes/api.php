<?php

use App\Http\Controllers\SampleController;
use Illuminate\Support\Facades\Route;

Route::prefix('reservation')->group(function () {
    Route::get('ping', [SampleController::class, 'ping']);
});


