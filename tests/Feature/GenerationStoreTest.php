<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    configurePixelforge();
});

/**
 * @return array<string, mixed>
 */
function generationPayload(array $overrides = []): array
{
    return [
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => ['https://example.com/style.png', ''],
        ...$overrides,
    ];
}

test('guests are sent to the login page', function () {
    Http::preventStrayRequests();

    $this->post(route('generations.store'), generationPayload())
        ->assertRedirect(route('login'));

    expect(Transaction::query()->exists())->toBeFalse();
});

test('saves the generation with its estimate and submits it to Pixelforge', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgePricing()),
        'pixelforge.test/v1/generations' => Http::response(pixelforgeGeneration(), 202),
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $response->assertRedirect(route('generations.index'));
    $transaction = $user->transactions()->sole();
    expect($transaction)
        ->status->toBe(Transaction::Queued)
        ->pixelforge_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->reference_images->toBe(['https://example.com/style.png'])
        ->estimated_min_price->toBe('0.07')
        ->estimated_max_price->toBe('0.10')
        ->actual_cost->toBeNull();
    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Idempotency-Key', $transaction->id)
        && $request->hasHeader('Authorization', 'Bearer test-api-key')
        && $request['metadata'] === ['transaction_id' => $transaction->id]
        && $request['reference_images'] === ['https://example.com/style.png']);
});

test('holds the estimated maximum price from the balance', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgePricing()),
        'pixelforge.test/v1/generations' => Http::response(pixelforgeGeneration(), 202),
    ]);
    // Exactly the $0.10 estimated maximum, so the boundary is allowed.
    $user = User::factory()->withBalance('0.10')->create();

    $this->actingAs($user)->post(route('generations.store'), generationPayload())
        ->assertRedirect(route('generations.index'));

    expect($user->transactions()->sole()->held_amount)->toBe('0.100000000');
    expect($user->fresh()->balance)->toBe('0.000000000');
});

test('refuses a generation the balance cannot cover', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgePricing()),
    ]);
    $user = User::factory()->withBalance('0.09')->create();

    $response = $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $response->assertSessionHasErrors(['generation' => 'Your balance is too low for this image. Try a smaller size or lower quality.']);
    expect($user->transactions()->exists())->toBeFalse();
    expect($user->fresh()->balance)->toBe('0.090000000');
    Http::assertNotSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/generations');
});

test('requires a prompt, size and quality', function () {
    Http::preventStrayRequests();

    $response = $this->actingAs(User::factory()->create())->post(route('generations.store'), []);

    $response->assertSessionHasErrors([
        'prompt' => 'The prompt field is required.',
        'size' => 'The size field is required.',
        'quality' => 'The quality field is required.',
    ]);
});

test('rejects a reference image that is not a web url', function () {
    Http::preventStrayRequests();

    $response = $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), generationPayload(['reference_images' => ['ftp://example.com/a.png']]));

    $response->assertSessionHasErrors(['reference_images.0' => 'The reference_images.0 field must be a valid URL.']);
});

test('refuses a second generation while one is still active', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    Transaction::factory()->for($user)->processing()->create();

    $response = $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $response->assertSessionHasErrors(['generation' => 'Please wait for your current image to finish.']);
    expect($user->transactions()->count())->toBe(1);
});

test('an old unfinished generation does not block a new one', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgePricing()),
        'pixelforge.test/v1/generations' => Http::response(pixelforgeGeneration(), 202),
    ]);
    $user = User::factory()->create();
    Transaction::factory()->for($user)->processing()->create(['created_at' => now()->subMinutes(11)]);

    $this->actingAs($user)->post(route('generations.store'), generationPayload())
        ->assertRedirect(route('generations.index'));

    expect($user->transactions()->count())->toBe(2);
});

test('a generation Pixelforge refuses is saved as failed and costs nothing', function (int $status, string $errorCode, string $message) {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgePricing()),
        'pixelforge.test/v1/generations' => Http::response(['error' => ['type' => 'error', 'code' => $errorCode, 'message' => 'Raw provider message']], $status),
    ]);
    $user = User::factory()->withBalance('0.50')->create();

    $response = $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $response->assertSessionHasErrors(['generation' => $message]);
    expect($user->transactions()->sole())
        ->status->toBe(Transaction::Failed)
        ->error_code->toBe($errorCode)
        ->actual_cost->toBe('0.000000000')
        ->held_amount->toBe('0.000000000');
    expect($user->fresh()->balance)->toBe('0.500000000');
})->with([
    'prompt blocked' => [400, 'content_policy_violation', 'This prompt is not allowed. Please change it and try again.'],
    'account is busy' => [429, 'concurrency_limit_exceeded', 'We are very busy right now. Please try again in a moment.'],
    'daily limit reached' => [429, 'daily_limit_exceeded', 'We have reached today\'s image limit. Please try again tomorrow.'],
]);

test('retries an overloaded Pixelforge and then gives up cleanly', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgePricing()),
        'pixelforge.test/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2']),
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $response->assertSessionHasErrors(['generation' => 'Image generation is unavailable right now. Please try again shortly.']);
    // One pricing call, then two create attempts.
    Http::assertSentCount(3);
    expect($user->transactions()->sole()->status)->toBe(Transaction::Failed);
});

test('a connection failure is saved as a submission failure', function () {
    Sleep::fake();
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgePricing()),
        'pixelforge.test/v1/generations' => fn () => throw new ConnectionException('Connection timed out'),
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $response->assertSessionHasErrors(['generation' => 'Image generation is unavailable right now. Please try again shortly.']);
    expect($user->transactions()->sole())
        ->status->toBe(Transaction::Failed)
        ->error_code->toBe(Transaction::SubmissionFailed)
        ->pixelforge_id->toBeNull();
});

test('does not save a generation when pricing is unavailable', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(status: 500),
    ]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('generations.store'), generationPayload());

    $response->assertSessionHasErrors(['generation' => 'Pricing is unavailable right now. Please try again shortly.']);
    expect($user->transactions()->exists())->toBeFalse();
});

test('a user cannot set system fields such as the status or cost', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgePricing()),
        'pixelforge.test/v1/generations' => Http::response(pixelforgeGeneration(), 202),
    ]);
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('generations.store'), generationPayload([
        'status' => 'succeeded',
        'actual_cost' => '0',
        'user_id' => User::factory()->create()->id,
    ]));

    expect($user->transactions()->sole())
        ->status->toBe(Transaction::Queued)
        ->actual_cost->toBeNull();
});
