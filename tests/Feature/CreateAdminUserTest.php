<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('creates a verified admin user with the given password', function () {
    $this->artisan('admin:create', ['email' => 'ops@example.com', '--name' => 'Ops'])
        ->expectsQuestion('Password', 'secret-password')
        ->expectsQuestion('Confirm password', 'secret-password')
        ->expectsOutput('Created admin ops@example.com.')
        ->assertSuccessful();

    $admin = User::query()->where('email', 'ops@example.com')->sole();

    expect($admin)
        ->name->toBe('Ops')
        ->is_admin->toBeTrue()
        ->email_verified_at->not->toBeNull()
        ->and(Hash::check('secret-password', $admin->password))->toBeTrue();
});

test('makes an existing user an admin and sets the new password', function () {
    $user = User::factory()->create(['name' => 'Kept Name']);

    $this->artisan('admin:create', ['email' => $user->email])
        ->expectsQuestion('Password', 'new-password')
        ->expectsQuestion('Confirm password', 'new-password')
        ->assertSuccessful();

    $user->refresh();

    expect($user)
        ->name->toBe('Kept Name')
        ->is_admin->toBeTrue()
        ->and(Hash::check('new-password', $user->password))->toBeTrue();
});

test('refuses passwords that do not match or an invalid email', function (string $email, string $confirmation, string $message) {
    $this->artisan('admin:create', ['email' => $email])
        ->expectsQuestion('Password', 'secret-password')
        ->expectsQuestion('Confirm password', $confirmation)
        ->expectsOutput($message)
        ->assertFailed();

    expect(User::query()->exists())->toBeFalse();
})->with([
    'mismatch' => ['ops@example.com', 'other-password', 'The password field confirmation does not match.'],
    'bad email' => ['not-an-email', 'secret-password', 'The email field must be a valid email address.'],
]);
