<?php

use App\Models\BalanceEntry;
use App\Models\User;

test('adds the amount to the balance and records a top-up', function () {
    $user = User::factory()->withBalance('0.50')->create();

    $this->artisan('billing:top-up', ['email' => $user->email, 'amount' => '5', '--note' => 'Paid by bank transfer'])
        ->expectsOutput('Added $5. The balance is now $5.500000000.')
        ->assertSuccessful();

    expect($user->fresh()->balance)->toBe('5.500000000');
    expect($user->balanceEntries()->sole())
        ->type->toBe(BalanceEntry::TopUp)
        ->amount->toBe('5.000000000')
        ->description->toBe('Paid by bank transfer');
});

test('refuses an amount that is not a positive dollar value', function (string $amount) {
    $user = User::factory()->withBalance('0.50')->create();

    $this->artisan('billing:top-up', ['email' => $user->email, 'amount' => $amount])
        ->expectsOutput('The amount must be a positive number of dollars with at most two decimals.')
        ->assertFailed();

    expect($user->fresh()->balance)->toBe('0.500000000');
    expect($user->balanceEntries()->exists())->toBeFalse();
})->with([
    'zero' => ['0'],
    'negative' => ['-5'],
    'fraction of a cent' => ['1.234'],
    'not a number' => ['five'],
]);

test('refuses an email that no user has', function () {
    $this->artisan('billing:top-up', ['email' => 'nobody@example.com', 'amount' => '5'])
        ->expectsOutput('No user has that email address.')
        ->assertFailed();

    expect(BalanceEntry::query()->exists())->toBeFalse();
});
