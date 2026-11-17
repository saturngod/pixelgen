<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

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

test('submits a generation with no answer again, using its id as the idempotency key', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(pixelforgeGeneration(['id' => 'gen_01JC2Z9B7K2M4P6R8T0V3X5Z7A']), 202),
    ]);
    $waiting = Transaction::factory()->unsubmitted()->create(['created_at' => now()->subMinutes(3)]);
    $young = Transaction::factory()->unsubmitted()->create();

    $this->artisan('transactions:sync')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $waiting->id));
    expect($waiting->fresh())
        ->status->toBe(Transaction::Queued)
        ->pixelforge_id->toBe('gen_01JC2Z9B7K2M4P6R8T0V3X5Z7A')
        ->held_amount->toBe('0.090000000');
    expect($young->fresh()->pixelforge_id)->toBeNull();
});

test('a resubmitted generation that Pixelforge refuses fails and returns the hold', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(['error' => ['code' => 'content_policy_violation']], 400),
    ]);
    $user = User::factory()->withBalance('0.91')->create();
    $transaction = Transaction::factory()->unsubmitted()->for($user)->create(['created_at' => now()->subMinutes(3)]);

    $this->artisan('transactions:sync')->assertSuccessful();

    expect($transaction->fresh())
        ->status->toBe(Transaction::Failed)
        ->error_code->toBe('content_policy_violation');
    expect($user->fresh()->balance)->toBe('1.000000000');
});

test('a generation that still has no answer is tried again, then failed once it is too old', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/generations' => fn () => throw new ConnectionException('Connection timed out'),
    ]);
    $user = User::factory()->withBalance('0.82')->create();
    $recent = Transaction::factory()->unsubmitted()->for($user)->create(['created_at' => now()->subMinutes(3)]);
    $old = Transaction::factory()->unsubmitted()->for($user)->create(['created_at' => now()->subMinutes(11)]);

    $this->artisan('transactions:sync')->assertSuccessful();

    expect($recent->fresh()->status)->toBe(Transaction::Queued);
    expect($old->fresh())
        ->status->toBe(Transaction::Failed)
        ->error_code->toBe(Transaction::SubmissionFailed);
    expect($user->fresh()->balance)->toBe('0.910000000');
});

test('does not queue an image copy that already failed for good', function () {
    Queue::fake([StoreGenerationImage::class]);
    Http::preventStrayRequests();
    Transaction::factory()->succeeded()->create(['completed_at' => now()->subMinutes(10), 'image_failed_at' => now()->subMinute()]);

    $this->artisan('transactions:sync')->assertSuccessful();

    Queue::assertNothingPushed();
});
