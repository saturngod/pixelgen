<?php

namespace Database\Factories;

use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    /**
     * Define the model's default state: a processed event.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventId = 'evt_'.fake()->regexify('[0-9A-Z]{26}');

        return [
            'outcome' => WebhookDelivery::Processed,
            'reason' => null,
            'event_id' => $eventId,
            'type' => 'generation.processing',
            'transaction_id' => null,
            'ip' => fake()->ipv4(),
            'payload' => ['id' => $eventId, 'object' => 'event', 'type' => 'generation.processing', 'data' => []],
        ];
    }

    /**
     * A request rejected before its body could be trusted.
     */
    public function invalidSignature(): static
    {
        return $this->state(fn (): array => [
            'outcome' => WebhookDelivery::InvalidSignature,
            'reason' => 'signature mismatch',
            'event_id' => null,
            'type' => null,
            'payload' => null,
        ]);
    }
}
