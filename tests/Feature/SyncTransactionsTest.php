<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    configurePixelforge();
});

test('updates a stuck generation from Pixelforge', function () {
    Queue::fake([StoreGenerationImage::class]);
    Http::preventStrayRequests();
    $transaction = Transaction::factory()->processing()->create(['created_at' => now()->subMinutes(3)]);
    Http::fake([
        "pixelforge.test/v1/generations/{$transaction->pixelforge_id}" => Http::response(pixelforgeGeneration([
            'id' => $transaction->pixelforge_id,
            'status' => 'failed',
            'error' => ['code' => 'internal_error', 'message' => 'Something went wrong.'],
        ])),
    ]);

    $this->artisan('transactions:sync')->assertSuccessful();

    expect($transaction->fresh())
        ->status->toBe(Transaction::Failed)
        ->actual_cost->toBe('0.000000000');
});

test('skips final, young and recently checked generations', function () {
    Queue::fake([StoreGenerationImage::class]);
    Http::preventStrayRequests();
    Transaction::factory()->failed()->create(['created_at' => now()->subMinutes(3)]);
    Transaction::factory()->processing()->create();
    Transaction::factory()->processing()->create(['created_at' => now()->subMinutes(3), 'last_synced_at' => now()->subSeconds(10)]);

    $this->artisan('transactions:sync')->assertSuccessful();

    Http::assertNothingSent();
});

test('queues an image copy for a succeeded generation without one', function () {
    Queue::fake([StoreGenerationImage::class]);
    Http::preventStrayRequests();
    $missing = Transaction::factory()->succeeded()->create(['completed_at' => now()->subMinutes(10)]);
    Transaction::factory()->withStoredImage()->create(['completed_at' => now()->subMinutes(10)]);
    Transaction::factory()->succeeded()->create(['completed_at' => now()->subDays(2)]);

    $this->artisan('transactions:sync')->assertSuccessful();

    Queue::assertPushedTimes(StoreGenerationImage::class, 1);
    Queue::assertPushed(StoreGenerationImage::class, fn (StoreGenerationImage $job) => $job->transactionId === $missing->id);
});
