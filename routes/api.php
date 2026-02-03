<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ScoreController;
use App\Http\Controllers\Api\ReportController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Public API routes (Rate Limited: 60 req/min)
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/search', [ScoreController::class, 'search']);
    Route::get('/reports/distribution', [ReportController::class, 'getDistribution']);
    Route::get('/reports/top-group-a', [ReportController::class, 'getTopGroupA']);
});
