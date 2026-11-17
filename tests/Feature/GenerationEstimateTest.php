<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.markup_margin' => '25',
    ]);
});

function fakePixelforgePricing(): void
{
    Http::preventStrayRequests();

    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response([
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ]),
    ]);
}

test('estimates the price range with the markup applied', function (string $prompt) {
    fakePixelforgePricing();

    $response = $this->postJson(route('generations.estimate'), [
        'prompt' => $prompt,
        'size' => '1024x1024',
        'quality' => 'high',
    ]);

    // Provider cost is $0.184605 to $0.272145, plus 25% and rounded up to the cent.
    $response->assertOk()->assertExactJson([
        'currency' => 'usd',
        'min_price' => '0.24',
        'max_price' => '0.35',
    ]);
})->with([
    'ascii prompt' => [str_repeat('a', 100)],
    'multibyte prompt counted by characters' => [str_repeat('က', 100)],
]);

test('charges for each filled reference image', function () {
    fakePixelforgePricing();

    $response = $this->postJson(route('generations.estimate'), [
        'prompt' => str_repeat('a', 8),
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => ['https://example.com/one.png', '', 'https://example.com/two.png'],
    ]);

    $response->assertOk()->assertJson([
        'min_price' => '0.08',
        'max_price' => '0.11',
    ]);
});

test('uses the configured markup margin', function () {
    fakePixelforgePricing();
    config(['services.pixelforge.markup_margin' => '0']);

    $response = $this->postJson(route('generations.estimate'), [
        'prompt' => str_repeat('a', 100),
        'size' => '1024x1024',
        'quality' => 'high',
    ]);

    $response->assertOk()->assertJson([
        'min_price' => '0.19',
        'max_price' => '0.28',
    ]);
});

test('caches the provider pricing between estimates', function () {
    fakePixelforgePricing();

    $payload = ['prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'low'];

    $this->postJson(route('generations.estimate'), $payload)->assertOk();
    $this->postJson(route('generations.estimate'), $payload)->assertOk();

    Http::assertSentCount(1);
});

test('requires a prompt, size and quality', function () {
    $response = $this->postJson(route('generations.estimate'), []);

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'prompt' => 'The prompt field is required.',
        'size' => 'The size field is required.',
        'quality' => 'The quality field is required.',
    ]);
});

test('rejects invalid generation options', function (array $overrides, string $field, string $message) {
    $payload = [
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        ...$overrides,
    ];

    $response = $this->postJson(route('generations.estimate'), $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors([$field => $message]);
})->with([
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt', 'The prompt field must not be greater than 4000 characters.'],
    'unsupported size' => [['size' => '512x512'], 'size', 'The selected size is invalid.'],
    'unsupported quality' => [['quality' => 'ultra'], 'quality', 'The selected quality is invalid.'],
    'too many reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images', 'The reference images field must not have more than 4 items.'],
    'reference image is not a url' => [['reference_images' => ['not a url']], 'reference_images.0', 'The reference_images.0 field must be a valid URL.'],
    'reference image is not http' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0', 'The reference_images.0 field must be a valid URL.'],
]);

test('returns service unavailable when pricing cannot be fetched', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(status: 500),
    ]);

    $response = $this->postJson(route('generations.estimate'), [
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
    ]);

    $response->assertServiceUnavailable()->assertExactJson([
        'message' => 'Pricing is unavailable right now. Please try again shortly.',
    ]);
});

test('returns service unavailable when the connection to pricing fails', function () {
    Http::preventStrayRequests();
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $response = $this->postJson(route('generations.estimate'), [
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
    ]);

    $response->assertServiceUnavailable();
});

test('returns service unavailable when the pricing response is missing a token count', function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response([
            'currency' => 'usd',
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => ['low' => [], 'medium' => [], 'high' => []],
                'reasoning' => ['low' => ['min' => 0, 'max' => 500], 'medium' => ['min' => 200, 'max' => 2000], 'high' => ['min' => 1000, 'max' => 8000]],
            ],
        ]),
    ]);

    $response = $this->postJson(route('generations.estimate'), [
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
    ]);

    $response->assertServiceUnavailable();
});

test('does not cache an invalid pricing response', function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(['unexpected' => true]),
    ]);

    $this->postJson(route('generations.estimate'), [
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
    ])->assertServiceUnavailable();

    expect(Cache::has('pixelforge.pricing'))->toBeFalse();
});
