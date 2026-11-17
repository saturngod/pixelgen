<?php

namespace App\Services;

use App\Exceptions\PixelforgeRequestException;
use App\Models\Transaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Wraps the Pixelforge generations API.
 */
class PixelforgeService
{
    /**
     * Submit a generation. The transaction id is the idempotency key, so a retry never creates a second paid generation.
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeRequestException
     */
    public function createGeneration(Transaction $transaction): array
    {
        $payload = [
            'prompt' => $transaction->prompt,
            'size' => $transaction->size,
            'quality' => $transaction->quality,
            'metadata' => ['transaction_id' => $transaction->id],
        ];

        if (! empty($transaction->reference_images)) {
            $payload['reference_images'] = array_values($transaction->reference_images);
        }

        $response = $this->send(fn (PendingRequest $request) => $request
            ->withHeaders(['Idempotency-Key' => $transaction->id])
            ->post('generations', $payload));

        return $this->generationFrom($response);
    }

    /**
     * Get the latest state of a generation, including a fresh image URL.
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeRequestException
     */
    public function getGeneration(string $pixelforgeId): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get('generations/'.rawurlencode($pixelforgeId)));

        return $this->generationFrom($response);
    }

    /**
     * Validation rules for a Pixelforge generation object, used for API responses and webhook payloads.
     *
     * @return array<string, array<int, string>>
     */
    public static function generationRules(string $prefix = ''): array
    {
        $field = fn (string $name): string => $prefix === '' ? $name : "{$prefix}.{$name}";
        $statuses = implode(',', [Transaction::Queued, Transaction::Processing, Transaction::Succeeded, Transaction::Failed]);
        $rules = [
            $field('id') => ['required', 'string', 'starts_with:gen_', 'max:64'],
            $field('status') => ['required', 'string', "in:{$statuses}"],
            $field('metadata') => ['nullable', 'array'],
            $field('metadata.transaction_id') => ['nullable', 'string', 'ulid'],
            $field('usage') => ['nullable', 'array', 'required_if:'.$field('status').','.Transaction::Succeeded],
            $field('rates') => ['nullable', 'array', 'required_if:'.$field('status').','.Transaction::Succeeded],
            $field('image_url') => ['nullable', 'string', 'max:4096'],
            $field('image_url_expires_at') => ['nullable', 'date'],
            $field('error') => ['nullable', 'array'],
            $field('error.code') => ['nullable', 'string', 'max:100'],
            $field('error.message') => ['nullable', 'string', 'max:1000'],
            $field('started_at') => ['nullable', 'date'],
            $field('completed_at') => ['nullable', 'date'],
        ];

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $tokenType) {
            $rules[$field("usage.{$tokenType}")] = ['required_with:'.$field('usage'), 'integer', 'min:0'];
            $rules[$field("rates.{$tokenType}")] = ['required_with:'.$field('rates'), 'string', 'regex:/^\d{1,6}(\.\d{1,12})?$/'];
        }

        return $rules;
    }

    /**
     * Whether an image URL points at the Pixelforge host over https. We never download from any other host.
     */
    public static function isTrustedImageUrl(string $url): bool
    {
        $image = parse_url($url);
        $pixelforgeHost = parse_url((string) config('services.pixelforge.base_url'), PHP_URL_HOST);

        return is_array($image)
            && ($image['scheme'] ?? null) === 'https'
            && is_string($pixelforgeHost)
            && ($image['host'] ?? null) === $pixelforgeHost
            && ! isset($image['user'])
            && ! isset($image['pass'])
            && ! isset($image['port']);
    }

    /**
     * Send a request, retrying connection errors and temporary overloads.
     *
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws PixelforgeRequestException
     */
    private function send(callable $request): Response
    {
        $client = Http::baseUrl((string) config('services.pixelforge.base_url'))
            ->withToken((string) config('services.pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(
                2,
                1000,
                fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->status() === 503),
                throw: false,
            );

        try {
            $response = $request($client);
        } catch (ConnectionException $exception) {
            throw new PixelforgeRequestException('Pixelforge connection failed.', 'connection_failed', previous: $exception);
        }

        if ($response->failed()) {
            $retryAfter = $response->header('Retry-After');

            throw new PixelforgeRequestException(
                "Pixelforge returned HTTP {$response->status()}.",
                (string) ($response->json('error.code') ?? "http_{$response->status()}"),
                $response->status(),
                is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeRequestException
     */
    private function generationFrom(Response $response): array
    {
        $generation = $response->json();

        $validator = Validator::make(is_array($generation) ? $generation : [], self::generationRules());

        if ($validator->fails()) {
            throw new PixelforgeRequestException(
                'Pixelforge generation response has an unexpected shape: '.implode(' ', $validator->errors()->keys()),
                'invalid_response',
            );
        }

        return $generation;
    }
}
