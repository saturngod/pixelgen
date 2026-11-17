<?php

use App\Models\BalanceEntry;
use App\Models\User;

test('guests cannot buy credits', function () {
    $this->post(route('billing.store'), ['amount' => '10'])->assertRedirect(route('login'));

    expect(BalanceEntry::query()->exists())->toBeFalse();
});

test('buying a credit pack adds it to the balance and records a top-up', function () {
    $user = User::factory()->withBalance('0.25')->create();

    $response = $this->actingAs($user)
        ->from(route('billing.index'))
        ->post(route('billing.store'), ['amount' => '10']);

    $response->assertRedirect(route('billing.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Added $10 in credits.']);
    expect($user->fresh()->balance)->toBe('10.250000000');
    expect($user->balanceEntries()->sole())
        ->type->toBe(BalanceEntry::TopUp)
        ->amount->toBe('10.000000000')
        ->description->toBe('Credit pack (test purchase, no payment taken)');
});

test('rejects an amount that is not a credit pack', function () {
    $user = User::factory()->withBalance('0.25')->create();

    $response = $this->actingAs($user)->post(route('billing.store'), ['amount' => '1000']);

    $response->assertSessionHasErrors(['amount' => 'The selected amount is invalid.']);
    expect($user->fresh()->balance)->toBe('0.250000000');
    expect($user->balanceEntries()->exists())->toBeFalse();
});

test('requires an amount', function () {
    $response = $this->actingAs(User::factory()->create())->post(route('billing.store'), []);

    $response->assertSessionHasErrors(['amount' => 'The amount field is required.']);
});
