<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    configurePixelforge();
});

test('guests cannot see the history page', function () {
    $this->get(route('generations.index'))->assertRedirect(route('login'));
});

test('the history page lists only the user\'s own generations', function () {
    $user = User::factory()->create();
    $own = Transaction::factory()->for($user)->failed()->create();
    Transaction::factory()->failed()->create();

    $response = $this->actingAs($user)->get(route('generations.index'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('generations/Index')
        ->has('generations.data', 1)
        ->where('generations.data.0.id', $own->id)
        ->where('generations.data.0.actual_cost', '0.000000000')
        ->where('generations.data.0.error', 'The image could not be made from this prompt. You were not charged.'));
});

test('shows a generation to its owner without the storage key', function () {
    Storage::fake('s3');
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->withStoredImage()->create();

    $response = $this->actingAs($user)->getJson(route('generations.show', $transaction->id));

    $response->assertOk()
        ->assertJsonPath('id', $transaction->id)
        ->assertJsonPath('status', Transaction::Succeeded)
        ->assertJsonPath('actual_cost', '0.070687500')
        ->assertJsonPath('image_pending', false)
        ->assertJsonMissingPath('image_path');
    expect($response->json('image_url'))->toStartWith(url($transaction->image_path))->toContain('expiration=');
});

test('a generation still being copied has no image link yet', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->succeeded()->create();

    $this->actingAs($user)->getJson(route('generations.show', $transaction->id))
        ->assertOk()
        ->assertJsonPath('image_url', null)
        ->assertJsonPath('image_pending', true);
});

test('another user\'s generation is not found', function () {
    $transaction = Transaction::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson(route('generations.show', $transaction->id))
        ->assertNotFound();
});

test('a stuck generation is checked against Pixelforge', function () {
    Queue::fake([StoreGenerationImage::class]);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->processing()->create(['created_at' => now()->subMinutes(3)]);
    Http::fake([
        "pixelforge.test/v1/generations/{$transaction->pixelforge_id}" => Http::response(succeededGeneration($transaction->pixelforge_id)),
    ]);

    $response = $this->actingAs($user)->getJson(route('generations.show', $transaction->id));

    $response->assertOk()->assertJsonPath('status', Transaction::Succeeded);
    expect($transaction->fresh())
        ->actual_cost->toBe('0.070715625')
        ->last_synced_at->not->toBeNull();
    Queue::assertPushed(StoreGenerationImage::class);
});

test('a generation checked less than 30 seconds ago is not checked again', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->processing()->create([
        'created_at' => now()->subMinutes(3),
        'last_synced_at' => now()->subSeconds(10),
    ]);

    $this->actingAs($user)->getJson(route('generations.show', $transaction->id))
        ->assertOk()
        ->assertJsonPath('status', Transaction::Processing);

    Http::assertNothingSent();
});

test('returns several generations in one request and leaves out other users\' ids', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $queued = Transaction::factory()->for($user)->create();
    $processing = Transaction::factory()->for($user)->processing()->create();
    $someoneElses = Transaction::factory()->processing()->create();

    $response = $this->actingAs($user)->getJson(route('generations.statuses', [
        'ids' => [$queued->id, $processing->id, $someoneElses->id],
    ]));

    $response->assertOk()->assertJsonCount(2, 'generations');
    expect(collect($response->json('generations'))->pluck('status', 'id')->all())->toEqual([
        $queued->id => Transaction::Queued,
        $processing->id => Transaction::Processing,
    ]);
    Http::assertNothingSent();
});

test('the status endpoint checks stuck generations against Pixelforge', function () {
    Queue::fake([StoreGenerationImage::class]);
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->processing()->create(['created_at' => now()->subMinutes(3)]);
    Http::fake([
        "pixelforge.test/v1/generations/{$transaction->pixelforge_id}" => Http::response(succeededGeneration($transaction->pixelforge_id)),
    ]);

    $response = $this->actingAs($user)->getJson(route('generations.statuses', ['ids' => [$transaction->id]]));

    $response->assertOk()->assertJsonPath('generations.0.status', Transaction::Succeeded);
});

test('the status endpoint needs a short list of generation ids', function (array $query, string $field, string $message) {
    $response = $this->actingAs(User::factory()->create())->getJson(route('generations.statuses', $query));

    $response->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
})->with([
    'no ids' => [[], 'ids', 'The ids field is required.'],
    'not an id' => [['ids' => ['1 OR 1=1']], 'ids.0', 'The ids.0 field must be a valid ULID.'],
    'too many ids' => [['ids' => array_map(fn () => (string) Str::ulid(), range(1, 25))], 'ids', 'The ids field must not have more than 24 items.'],
]);

test('guests cannot use the status endpoint', function () {
    $this->getJson(route('generations.statuses', ['ids' => [(string) Str::ulid()]]))->assertUnauthorized();
});

test('a young generation is read from the database only', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->processing()->create();

    $this->actingAs($user)->getJson(route('generations.show', $transaction->id))->assertOk();

    Http::assertNothingSent();
});
