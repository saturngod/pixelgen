<?php

namespace App\Services;

use App\Exceptions\PricingUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use RoundingMode;

class PricingService
{
    /**
     * Decimal places kept during intermediate money calculations.
     */
    private const int Scale = 12;

    /**
     * Estimate the customer price range, including markup, for a generation.
     *
     * @return array{currency: string, min_price: string, max_price: string}
     *
     * @throws PricingUnavailableException
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount): array
    {
        $pricing = $this->pricing();
        $tokens = $pricing['tokens'];

        $textTokens = (int) ceil(mb_strlen($prompt) / $tokens['characters_per_text_token']);
        $imageTokens = $referenceImageCount * $tokens['per_reference_image'];
        $outputTokens = $tokens['output'][$quality][$size];
        $reasoning = $tokens['reasoning'][$quality];
        $variance = $tokens['text_token_variance'];

        $minimumCost = $this->cost($pricing['rates'], (int) floor($textTokens * (1 - $variance)), $imageTokens, $outputTokens, $reasoning['min']);
        $maximumCost = $this->cost($pricing['rates'], (int) ceil($textTokens * (1 + $variance)), $imageTokens, $outputTokens, $reasoning['max']);

        return [
            'currency' => $pricing['currency'],
            'min_price' => $this->withMarkup($minimumCost),
            'max_price' => $this->withMarkup($maximumCost),
        ];
    }

    /**
     * Calculate what a finished generation cost us and what the user pays.
     *
     * Both amounts keep 9 decimal places. The user price is rounded up at the 9th place, so it never falls below cost plus markup.
     *
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int}  $usage
     * @param  array{input_text_tokens: numeric-string, input_image_tokens: numeric-string, output_tokens: numeric-string, reasoning_tokens: numeric-string}  $rates
     * @return array{provider_cost: numeric-string, actual_cost: numeric-string}
     */
    public function actualCost(array $usage, array $rates): array
    {
        $providerCost = $this->cost(
            $rates,
            $usage['input_text_tokens'],
            $usage['input_image_tokens'],
            $usage['output_tokens'],
            $usage['reasoning_tokens'],
        );

        return [
            'provider_cost' => bcround($providerCost, 9, RoundingMode::PositiveInfinity),
            'actual_cost' => bcround(bcmul($providerCost, $this->markupMultiplier(), self::Scale), 9, RoundingMode::PositiveInfinity),
        ];
    }

    /**
     * Get the Pixelforge rates and token constants, cached for an hour.
     *
     * Only a response with the expected shape is cached, so a bad response is retried on the next request.
     *
     * @return array<string, mixed>
     *
     * @throws PricingUnavailableException
     */
    private function pricing(): array
    {
        if (Cache::has('pixelforge.pricing')) {
            return Cache::get('pixelforge.pricing');
        }

        $pricing = $this->fetchPricing();

        Cache::put('pixelforge.pricing', $pricing, now()->addHour());

        return $pricing;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PricingUnavailableException
     */
    private function fetchPricing(): array
    {
        try {
            $response = Http::baseUrl(config('services.pixelforge.base_url'))
                ->acceptJson()
                ->timeout(5)
                ->retry(2, 200, throw: false)
                ->get('pricing');
        } catch (ConnectionException $exception) {
            throw new PricingUnavailableException('Pixelforge connection failed.', previous: $exception);
        }

        if ($response->failed()) {
            throw new PricingUnavailableException("Pixelforge returned HTTP {$response->status()}.");
        }

        $pricing = $response->json();

        $validator = Validator::make(is_array($pricing) ? $pricing : [], [
            'currency' => ['required', 'string'],
            'rates' => ['required', 'array'],
            'rates.input_text_tokens' => ['required', 'numeric'],
            'rates.input_image_tokens' => ['required', 'numeric'],
            'rates.output_tokens' => ['required', 'numeric'],
            'rates.reasoning_tokens' => ['required', 'numeric'],
            'tokens' => ['required', 'array'],
            'tokens.characters_per_text_token' => ['required', 'integer', 'min:1'],
            'tokens.text_token_variance' => ['required', 'numeric', 'between:0,1'],
            'tokens.per_reference_image' => ['required', 'integer', 'min:0'],
            'tokens.output' => ['required', 'array'],
            'tokens.reasoning' => ['required', 'array'],
            ...$this->tokenTableRules(),
        ]);

        if ($validator->fails()) {
            throw new PricingUnavailableException('Pixelforge pricing response has an unexpected shape: '.implode(' ', $validator->errors()->keys()));
        }

        return $pricing;
    }

    /**
     * Every quality and size the generation form can send must have a token count.
     *
     * @return array<string, array<int, string>>
     */
    private function tokenTableRules(): array
    {
        $rules = [];

        foreach (['low', 'medium', 'high'] as $quality) {
            $rules["tokens.reasoning.{$quality}.min"] = ['required', 'integer', 'min:0'];
            $rules["tokens.reasoning.{$quality}.max"] = ['required', 'integer', 'min:0'];

            foreach (['1024x1024', '1536x1024', '1024x1536'] as $size) {
                $rules["tokens.output.{$quality}.{$size}"] = ['required', 'integer', 'min:0'];
            }
        }

        return $rules;
    }

    /**
     * Calculate the provider cost in dollars from token counts and per-1M-token rates.
     *
     * @param  array<string, numeric-string>  $rates
     * @return numeric-string
     */
    private function cost(array $rates, int $textTokens, int $imageTokens, int $outputTokens, int $reasoningTokens): string
    {
        $total = bcmul((string) $textTokens, $rates['input_text_tokens'], self::Scale);
        $total = bcadd($total, bcmul((string) $imageTokens, $rates['input_image_tokens'], self::Scale), self::Scale);
        $total = bcadd($total, bcmul((string) $outputTokens, $rates['output_tokens'], self::Scale), self::Scale);
        $total = bcadd($total, bcmul((string) $reasoningTokens, $rates['reasoning_tokens'], self::Scale), self::Scale);

        return bcdiv($total, '1000000', self::Scale);
    }

    /**
     * Apply the markup margin (a percentage) and round up to the next cent.
     *
     * @param  numeric-string  $cost
     */
    private function withMarkup(string $cost): string
    {
        $cents = bcceil(bcmul(bcmul($cost, $this->markupMultiplier(), self::Scale), '100', self::Scale));

        return bcdiv($cents, '100', 2);
    }

    /**
     * Turn the markup margin percentage into a multiplier, for example 25 becomes 1.25.
     *
     * @return numeric-string
     */
    private function markupMultiplier(): string
    {
        return bcadd('1', bcdiv((string) config('services.pixelforge.markup_margin'), '100', self::Scale), self::Scale);
    }
}
