<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    configurePixelforge();
});

test('redirects the owner to a link for the stored image', function () {
    Storage::fake('s3');
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->withStoredImage()->create();

    $response = $this->actingAs($user)->get(route('generations.download', $transaction->id));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain($transaction->image_path);
});

test('returns not found when the image is not stored yet', function () {
    Storage::fake('s3');
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->succeeded()->create();

    $this->actingAs($user)->get(route('generations.download', $transaction->id))->assertNotFound();
});

test('a user cannot download another user\'s image', function () {
    Storage::fake('s3');
    $transaction = Transaction::factory()->withStoredImage()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('generations.download', $transaction->id))
        ->assertNotFound();
});

test('guests cannot download an image', function () {
    $transaction = Transaction::factory()->withStoredImage()->create();

    $this->get(route('generations.download', $transaction->id))->assertRedirect(route('login'));
});
