<?php

namespace Database\Factories;

use App\Models\BalanceEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BalanceEntry>
 */
class BalanceEntryFactory extends Factory
{
    /**
     * Define the model's default state: a top-up.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => BalanceEntry::TopUp,
            'amount' => '10.000000000',
            'description' => null,
        ];
    }

    /**
     * The free credit a new user gets.
     */
    public function signupCredit(): static
    {
        return $this->state(fn (): array => [
            'type' => BalanceEntry::SignupCredit,
            'amount' => '0.500000000',
        ]);
    }

    /**
     * The final charge for a succeeded generation, owned by the same user.
     */
    public function usage(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => BalanceEntry::Usage,
            'amount' => '-0.070687500',
            'transaction_id' => Transaction::factory()->succeeded()->state(['user_id' => $attributes['user_id']]),
        ]);
    }
}
