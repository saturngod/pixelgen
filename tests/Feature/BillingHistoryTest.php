<?php

use App\Models\BalanceEntry;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('guests cannot see the billing page', function () {
    $this->get(route('billing.index'))->assertRedirect(route('login'));
});

test('the billing page lists only the user\'s own entries, newest first', function () {
    $user = User::factory()->withBalance('10.43')->create();
    $signup = BalanceEntry::factory()->for($user)->signupCredit()->create(['created_at' => now()->subDays(2)]);
    $usage = BalanceEntry::factory()->for($user)->usage()->create(['created_at' => now()->subDay()]);
    $topUp = BalanceEntry::factory()->for($user)->create(['description' => 'Paid by bank transfer', 'created_at' => now()]);
    BalanceEntry::factory()->create();

    $response = $this->actingAs($user)->get(route('billing.index'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('billing/Index')
        ->where('balance', '10.430000000')
        ->where('heldAmount', '0.000000000')
        ->where('type', null)
        ->has('entries.data', 3)
        ->where('entries.data.0.id', $topUp->id)
        ->where('entries.data.0.title', 'Top-up')
        ->where('entries.data.0.details', 'Paid by bank transfer')
        ->where('entries.data.0.amount', '10.000000000')
        ->where('entries.data.1.id', $usage->id)
        ->where('entries.data.1.title', 'Image generation')
        ->where('entries.data.1.details', "{$usage->transaction->prompt} · 1024x1024 · Medium")
        ->where('entries.data.1.amount', '-0.070687500')
        ->where('entries.data.1.generation_id', $usage->transaction_id)
        ->where('entries.data.2.id', $signup->id)
        ->where('entries.data.2.title', 'Free signup credit'));
});

test('the history can be filtered to top-ups or usage', function (string $type, string $expectedType) {
    $user = User::factory()->create();
    BalanceEntry::factory()->for($user)->signupCredit()->create();
    BalanceEntry::factory()->for($user)->usage()->create();

    $response = $this->actingAs($user)->get(route('billing.index', ['type' => $type]));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('type', $type)
        ->has('entries.data', 1)
        ->where('entries.data.0.type', $expectedType));
})->with([
    'top-ups' => ['credits', BalanceEntry::SignupCredit],
    'usage' => ['usage', BalanceEntry::Usage],
]);

test('rejects an unknown history filter', function () {
    $response = $this->actingAs(User::factory()->create())->get(route('billing.index', ['type' => 'refunds']));

    $response->assertSessionHasErrors(['type' => 'The selected type is invalid.']);
});

test('shows the amount held for generations in progress', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->processing()->create(['held_amount' => '0.09']);
    Transaction::factory()->for($user)->succeeded()->create();

    $response = $this->actingAs($user)->get(route('billing.index'));

    $response->assertInertia(fn (AssertableInertia $page) => $page->where('heldAmount', '0.090000000'));
});
