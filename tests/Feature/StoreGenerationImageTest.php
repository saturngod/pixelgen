<?php

use App\Jobs\StoreGenerationImage;
use App\Models\Transaction;
use App\Services\PixelforgeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    configurePixelforge();
});

function pngImage(): string
{
    return "\x89PNG\r\n\x1a\n".'fake image data';
}

test('copies the image to the private bucket', function () {
    Storage::fake('s3');
    Http::preventStrayRequests();
    $transaction = Transaction::factory()->succeeded()->create();
    Http::fake([
        $transaction->image_url => Http::response(pngImage(), 200, ['Content-Type' => 'image/png']),
    ]);

    (new StoreGenerationImage($transaction->id))->handle(app(PixelforgeService::class));

    $path = "generations/{$transaction->user_id}/{$transaction->id}.png";
    Storage::disk('s3')->assertExists($path);
    expect(Storage::disk('s3')->get($path))->toBe(pngImage());
    expect($transaction->fresh())
        ->image_path->toBe($path)
        ->image_stored_at->not->toBeNull();
});

test('does nothing when the image is already stored', function () {
    Storage::fake('s3');
    Http::preventStrayRequests();
    $transaction = Transaction::factory()->withStoredImage()->create();

    (new StoreGenerationImage($transaction->id))->handle(app(PixelforgeService::class));

    Http::assertNothingSent();
});

test('gets a fresh url when the old one has expired', function () {
    Storage::fake('s3');
    Http::preventStrayRequests();
    $transaction = Transaction::factory()->succeeded()->create();
    $freshUrl = "https://pixelforge.test/images/{$transaction->pixelforge_id}.png?expires=2&signature=new";
    Http::fake([
        $transaction->image_url => Http::response('', 403),
        "pixelforge.test/v1/generations/{$transaction->pixelforge_id}" => Http::response(succeededGeneration($transaction->pixelforge_id, ['image_url' => $freshUrl])),
        $freshUrl => Http::response(pngImage(), 200, ['Content-Type' => 'image/png']),
    ]);

    (new StoreGenerationImage($transaction->id))->handle(app(PixelforgeService::class));

    expect($transaction->fresh())
        ->image_url->toBe($freshUrl)
        ->image_path->not->toBeNull();
});

test('never downloads from another host', function () {
    Storage::fake('s3');
    Http::preventStrayRequests();
    $transaction = Transaction::factory()->succeeded()->create(['image_url' => 'https://evil.test/image.png']);
    Http::fake([
        "pixelforge.test/v1/generations/{$transaction->pixelforge_id}" => Http::response(succeededGeneration($transaction->pixelforge_id, ['image_url' => 'https://evil.test/other.png'])),
    ]);

    expect(fn () => (new StoreGenerationImage($transaction->id))->handle(app(PixelforgeService::class)))
        ->toThrow(RuntimeException::class, 'Could not download the Pixelforge image (HTTP none).');

    Http::assertSentCount(1);
    expect($transaction->fresh()->image_path)->toBeNull();
});

test('refuses a file that is not a png under 20 MB', function (string $body, string $contentType) {
    Storage::fake('s3');
    Http::preventStrayRequests();
    $transaction = Transaction::factory()->succeeded()->create();
    Http::fake([
        $transaction->image_url => Http::response($body, 200, ['Content-Type' => $contentType]),
    ]);
    $job = (new StoreGenerationImage($transaction->id))->withFakeQueueInteractions();

    $job->handle(app(PixelforgeService::class));

    $job->assertFailed();
    expect(Storage::disk('s3')->allFiles())->toBe([]);
    expect($transaction->fresh()->image_path)->toBeNull();
})->with([
    'html page' => ['<html>not an image</html>', 'text/html'],
    'png content type with other bytes' => ['GIF89a', 'image/png'],
    'larger than 20 MB' => [pngImage().str_repeat('a', 20 * 1024 * 1024), 'image/png'],
]);

test('removes its upload when the generation was deleted during the copy', function () {
    Storage::fake('s3');
    Http::preventStrayRequests();
    $transaction = Transaction::factory()->succeeded()->create();
    Http::fake([
        $transaction->image_url => function () use ($transaction) {
            // The user deletes the generation while the image is downloading.
            $transaction->deleteWithImage();

            return Http::response(pngImage(), 200, ['Content-Type' => 'image/png']);
        },
    ]);

    (new StoreGenerationImage($transaction->id))->handle(app(PixelforgeService::class));

    Storage::disk('s3')->assertMissing($transaction->imageStoragePath());
    expect(Transaction::withTrashed()->find($transaction->id)->image_path)->toBeNull();
});

test('a copy that failed for good is marked on the generation', function () {
    $transaction = Transaction::factory()->succeeded()->create();

    (new StoreGenerationImage($transaction->id))->failed(new RuntimeException('Could not download the Pixelforge image.'));

    expect($transaction->fresh())
        ->image_failed_at->not->toBeNull()
        ->status->toBe(Transaction::Succeeded);
});
