<?php

use App\Models\BalanceEntry;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('generations.index', absolute: false));
});

test('new users get the free signup credit', function () {
    config(['services.pixelforge.signup_credit' => '0.50']);

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'test@example.com')->sole();
    expect($user->balance)->toBe('0.500000000');
    expect($user->balanceEntries()->sole())
        ->type->toBe(BalanceEntry::SignupCredit)
        ->amount->toBe('0.500000000');
});
