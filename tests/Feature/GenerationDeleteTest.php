<?php

use App\Models\BalanceEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    configurePixelforge();
});

test('deletes the image from S3 and hides the generation', function () {
    Storage::fake('s3');
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->withStoredImage()->create();
    Storage::disk('s3')->put($transaction->imageStoragePath(), 'image');

    $response = $this->actingAs($user)
        ->from(route('generations.index'))
        ->delete(route('generations.destroy', $transaction->id));

    $response->assertRedirect(route('generations.index'))
        ->assertInertiaFlash('toast.message', 'Image deleted.');
    Storage::disk('s3')->assertMissing($transaction->imageStoragePath());
    expect(Transaction::withTrashed()->find($transaction->id))
        ->trashed()->toBeTrue()
        ->image_path->toBeNull()
        ->image_url->toBeNull();
    $this->actingAs($user)->get(route('generations.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('generations.data', 0));
});

test('a failed generation can be deleted', function () {
    Storage::fake('s3');
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->failed()->create();

    $this->actingAs($user)->delete(route('generations.destroy', $transaction->id))
        ->assertInertiaFlash('toast.type', 'success');

    expect($transaction->fresh()->trashed())->toBeTrue();
});

test('the charge for a deleted generation stays in the billing history', function () {
    Storage::fake('s3');
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->succeeded()->create(['prompt' => 'A red fox']);
    $user->balanceEntries()->create([
        'type' => BalanceEntry::Usage,
        'amount' => '-0.070687500',
        'transaction_id' => $transaction->id,
    ]);

    $this->actingAs($user)->delete(route('generations.destroy', $transaction->id));

    $this->actingAs($user)->get(route('billing.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.data.0.amount', '-0.070687500')
            ->where('entries.data.0.details', 'A red fox · 1024x1024 · Medium'));
});

test('a generation in progress cannot be deleted', function (string $state) {
    Storage::fake('s3');
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->{$state}()->create();

    $response = $this->actingAs($user)->delete(route('generations.destroy', $transaction->id));

    $response->assertInertiaFlash('toast.message', 'You can delete this image once it has finished.');
    expect($transaction->fresh())
        ->trashed()->toBeFalse()
        ->held_amount->toBe('0.090000000');
})->with([
    'queued' => ['unsubmitted'],
    'processing' => ['processing'],
]);

test('a user cannot delete another user\'s generation', function () {
    Storage::fake('s3');
    $transaction = Transaction::factory()->withStoredImage()->create();
    Storage::disk('s3')->put($transaction->imageStoragePath(), 'image');

    $this->actingAs(User::factory()->create())
        ->delete(route('generations.destroy', $transaction->id))
        ->assertNotFound();

    Storage::disk('s3')->assertExists($transaction->imageStoragePath());
    expect($transaction->fresh()->trashed())->toBeFalse();
});

test('guests cannot delete a generation', function () {
    $transaction = Transaction::factory()->failed()->create();

    $this->delete(route('generations.destroy', $transaction->id))->assertRedirect(route('login'));

    expect($transaction->fresh()->trashed())->toBeFalse();
});
