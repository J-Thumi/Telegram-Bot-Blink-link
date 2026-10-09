<?php

use App\Http\Controllers\BitikaWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramWebhookController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');




// Public webhook endpoints (no auth middleware needed, but implement signature checks)
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle'])
    ->name('telegram.webhook');

Route::post('/bitika/webhook', [BitikaWebhookController::class, 'handle'])
    ->name('bitika.webhook');