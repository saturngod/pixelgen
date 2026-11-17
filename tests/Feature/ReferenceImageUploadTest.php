<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('guests cannot upload a reference image', function () {
    Storage::fake('s3');

    $this->postJson(route('generations.reference-images.store'), [
        'image' => UploadedFile::fake()->image('style.png'),
    ])->assertUnauthorized();
});

test('stores the image in the private bucket and returns a temporary link', function () {
    Storage::fake('s3');
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('generations.reference-images.store'), [
        'image' => UploadedFile::fake()->image('style.png'),
    ]);

    $response->assertCreated()->assertJsonStructure(['url']);
    expect(Storage::disk('s3')->allFiles("references/{$user->id}"))->toHaveCount(1);
});

test('rejects a file that is not an image or is too large', function (UploadedFile $file) {
    Storage::fake('s3');

    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.reference-images.store'), ['image' => $file])
        ->assertJsonValidationErrors('image');

    expect(Storage::disk('s3')->allFiles())->toBeEmpty();
})->with([
    'a pdf' => fn () => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
    'an svg' => fn () => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
    'over 5 MB' => fn () => UploadedFile::fake()->image('big.png')->size(5121),
]);

test('requires an image', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('generations.reference-images.store'))
        ->assertJsonValidationErrors('image');
});
