<?php

namespace App\Http\Middleware;

use App\Models\WebhookDelivery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject any webhook that was not signed by Pixelforge with our webhook secret.
 */
class VerifyPixelforgeSignature
{
    /**
     * Maximum age, in either direction, of the signature timestamp.
     */
    private const int ToleranceSeconds = 300;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '') {
            Log::critical('Pixelforge webhook secret is not configured. Set WEBHOOK_SECRET.');

            return response()->json(['message' => 'Webhook is not configured.'], 500);
        }

        [$timestamp, $signatures] = $this->parseSignatureHeader((string) $request->header('Pixelforge-Signature', ''));

        if ($timestamp === null || $signatures === []) {
            return $this->rejected($request, 'missing or malformed signature header');
        }

        if (abs(now()->getTimestamp() - $timestamp) > self::ToleranceSeconds) {
            return $this->rejected($request, 'timestamp outside tolerance');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return $next($request);
            }
        }

        return $this->rejected($request, 'signature mismatch');
    }

    /**
     * Read `t=<unix time>,v1=<hex>`. Several v1 values are allowed while a secret is being rotated.
     *
     * @return array{0: int|null, 1: array<int, string>}
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '' && ctype_xdigit($value)) {
                $signatures[] = strtolower($value);
            }
        }

        return [$timestamp, $signatures];
    }

    /**
     * Record why a webhook was rejected. The body is untrusted here, so none of it is kept.
     */
    private function rejected(Request $request, string $reason): Response
    {
        WebhookDelivery::query()->create([
            'outcome' => WebhookDelivery::InvalidSignature,
            'reason' => $reason,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Invalid signature.'], 401);
    }
}
