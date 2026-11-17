<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('admin:create {email : The admin\'s email address} {--name=Admin : The admin\'s name, used for a new account}')]
#[Description('Create an admin user, or make an existing user an admin and set a new password')]
class CreateAdminUser extends Command
{
    /**
     * Execute the console command.
     *
     * The password is asked for, so it never appears in the shell history.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) $this->option('name');

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'confirmed', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $isNewUser = ! $user->exists;

        if ($isNewUser) {
            $user->name = $name;
        }

        $user->password = $password;
        $user->is_admin = true;
        $user->email_verified_at ??= now();
        $user->save();

        $this->info($isNewUser ? "Created admin {$email}." : "{$email} is now an admin with the new password.");

        return self::SUCCESS;
    }
}
