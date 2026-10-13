<?php

use App\Http\Controllers\QueueController;
use App\Http\Middleware\ApiContract;
use Illuminate\Support\Facades\Route;

Route::middleware(ApiContract::class)->group(function (): void {
    Route::view('/', 'welcome');
    Route::get('/api/ping', [QueueController::class, 'ping']);
    Route::get('/api/stats', [QueueController::class, 'stats']);
    Route::post('/api/check', [QueueController::class, 'check']);
    Route::post('/api/reset', [QueueController::class, 'reset']);
    Route::post('/api/throughput', [QueueController::class, 'startThroughput']);
    Route::post('/api/long', [QueueController::class, 'long']);
    Route::get('/api/throughput/{run}', [QueueController::class, 'throughput'])->where('run', '.*');
    Route::fallback(fn () => response()->json(['error' => 'not found'], 404));
});
