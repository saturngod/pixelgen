<?php

namespace App\Models;

use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * One request to the Pixelforge webhook endpoint and what we did with it, kept for the admin panel.
 *
 * The payload is only kept once the signature is verified, because an unsigned body is untrusted.
 *
 * @property int $id
 * @property string $outcome
 * @property string|null $reason
 * @property string|null $event_id
 * @property string|null $type
 * @property string|null $transaction_id
 * @property string|null $ip
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['outcome', 'reason', 'event_id', 'type', 'transaction_id', 'ip', 'payload'])]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    /**
     * The event was applied to its transaction.
     */
    public const string Processed = 'processed';

    /**
     * The event type is not one we act on.
     */
    public const string Ignored = 'ignored';

    /**
     * The signature was missing, stale or wrong.
     */
    public const string InvalidSignature = 'invalid_signature';

    /**
     * The signed body did not have the shape we expect.
     */
    public const string InvalidPayload = 'invalid_payload';

    /**
     * No transaction matched the generation, so Pixelforge was asked to retry.
     */
    public const string UnknownGeneration = 'unknown_generation';

    /**
     * Every outcome a delivery can have.
     *
     * @var array<int, string>
     */
    public const array Outcomes = [
        self::Processed,
        self::Ignored,
        self::InvalidSignature,
        self::InvalidPayload,
        self::UnknownGeneration,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * Save a delivery for a signed request, with its event id, type and payload.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function recordSigned(Request $request, string $outcome, array $attributes = []): self
    {
        $payload = $request->json()->all();

        return self::query()->create([
            'outcome' => $outcome,
            'event_id' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
            'type' => is_string($payload['type'] ?? null) ? $payload['type'] : null,
            'ip' => $request->ip(),
            'payload' => $payload,
            ...$attributes,
        ]);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
