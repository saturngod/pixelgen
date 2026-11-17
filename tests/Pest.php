<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Point the app at a fake Pixelforge host with known secrets.
 */
function configurePixelforge(): void
{
    config([
        'services.pixelforge.base_url' => 'https://pixelforge.test/v1',
        'services.pixelforge.api_key' => 'test-api-key',
        'services.pixelforge.webhook_secret' => 'whsec_test',
        'services.pixelforge.markup_margin' => '25',
    ]);
}

/**
 * The Pixelforge pricing response.
 *
 * @return array<string, mixed>
 */
function pixelforgePricing(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
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
    ];
}

/**
 * Build a Pixelforge generation object.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'error' => null,
        'created_at' => '2026-11-16T10:00:00Z',
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

/**
 * Build a succeeded Pixelforge generation object for a Pixelforge id.
 *
 * Usage costs $0.0565725 at the default rates, which is $0.070715625 with a 25% markup.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function succeededGeneration(string $pixelforgeId, array $overrides = []): array
{
    return pixelforgeGeneration([
        'id' => $pixelforgeId,
        'status' => 'succeeded',
        'image_url' => "https://pixelforge.test/images/{$pixelforgeId}.png?expires=1&signature=abc",
        'image_url_expires_at' => '2026-11-17T10:00:30Z',
        'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1001, 'total_tokens' => 2113],
        'started_at' => '2026-11-16T10:00:02Z',
        'completed_at' => '2026-11-16T10:00:30Z',
        ...$overrides,
    ]);
}
