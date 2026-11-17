<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pixelforge_id' => 'gen_'.Str::upper(Str::random(26)),
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'status' => Transaction::Queued,
            'estimated_min_price' => '0.06',
            'estimated_max_price' => '0.09',
            'held_amount' => '0.09',
        ];
    }

    /**
     * A generation that has not been accepted by Pixelforge yet.
     */
    public function unsubmitted(): static
    {
        return $this->state(fn (): array => ['pixelforge_id' => null]);
    }

    /**
     * A generation Pixelforge is working on.
     */
    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => Transaction::Processing,
            'started_at' => now(),
        ]);
    }

    /**
     * A finished generation with its cost recorded.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => Transaction::Succeeded,
            'held_amount' => '0',
            'usage' => ['input_text_tokens' => 10, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2110],
            'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
            'provider_cost' => '0.056550000',
            'actual_cost' => '0.070687500',
            'image_url' => 'https://pixelforge.test/images/'.($attributes['pixelforge_id'] ?? 'gen_x').'.png?signature=abc',
            'image_url_expires_at' => now()->addDay(),
            'started_at' => now()->subSeconds(20),
            'completed_at' => now(),
        ]);
    }

    /**
     * A finished generation whose image is already copied to S3.
     */
    public function withStoredImage(): static
    {
        return $this->succeeded()->state(fn (): array => [
            'image_path' => 'generations/stored/'.Str::lower(Str::ulid()).'.png',
            'image_stored_at' => now(),
        ]);
    }

    /**
     * A generation Pixelforge reported as failed.
     */
    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => Transaction::Failed,
            'held_amount' => '0',
            'provider_cost' => '0',
            'actual_cost' => '0',
            'error_code' => 'generation_failed',
            'error_message' => 'The model could not produce an image.',
            'completed_at' => now(),
        ]);
    }
}
