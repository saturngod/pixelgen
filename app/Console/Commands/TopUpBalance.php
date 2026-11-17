<?php

namespace App\Console\Commands;

use App\Models\BalanceEntry;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:top-up {email : The user\'s email address} {amount : Dollars to add, such as 5 or 2.50} {--note= : A note shown in the user\'s billing history}')]
#[Description('Add money to a user\'s balance and record it as a top-up')]
class TopUpBalance extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $amount = (string) $this->argument('amount');

        if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', $amount) !== 1 || bccomp($amount, '0', 2) <= 0) {
            $this->error('The amount must be a positive number of dollars with at most two decimals.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email address.');

            return self::FAILURE;
        }

        $user->addCredit(BalanceEntry::TopUp, $amount, $this->option('note'));

        $this->info("Added \${$amount}. The balance is now \${$user->balance}.");

        return self::SUCCESS;
    }
}
