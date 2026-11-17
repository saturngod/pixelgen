<?php

use App\Http\Controllers\BillingController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ReferenceImageController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Home')->name('home');

Route::post('generations/estimate', [GenerationController::class, 'estimate'])
    ->middleware('throttle:60,1')
    ->name('generations.estimate');

Route::post('webhooks/pixelforge', [PixelforgeWebhookController::class, 'store'])
    ->middleware(['throttle:300,1', VerifyPixelforgeSignature::class])
    ->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');

    Route::post('billing/top-ups', [BillingController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('billing.store');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('generations.store');

    Route::post('generations/reference-images', [ReferenceImageController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('generations.reference-images.store');

    // Registered before generations/{transaction}, so "status" is not read as an id.
    Route::get('generations/status', [GenerationController::class, 'statuses'])
        ->middleware('throttle:60,1')
        ->name('generations.statuses');

    Route::get('generations/{transaction}', [GenerationController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('generations.show');

    Route::get('generations/{transaction}/download', [GenerationController::class, 'download'])
        ->middleware('throttle:60,1')
        ->name('generations.download');

    Route::delete('generations/{transaction}', [GenerationController::class, 'destroy'])
        ->middleware('throttle:60,1')
        ->name('generations.destroy');
});

require __DIR__.'/settings.php';
