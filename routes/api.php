<?php

use App\Modules\FO\Launching\Controllers\XenditWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Xendit invoice webhook (UTJ NUP) — token-verified, no auth/session/CSRF.
Route::post('/webhooks/xendit', XenditWebhookController::class)->name('webhooks.xendit');
