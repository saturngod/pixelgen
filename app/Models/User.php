<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $balance
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<BalanceEntry, $this>
     */
    public function balanceEntries(): HasMany
    {
        return $this->hasMany(BalanceEntry::class);
    }

    /**
     * Add money to the balance and record it in the billing history.
     */
    public function addCredit(string $type, string $amount, ?string $description = null): BalanceEntry
    {
        return DB::transaction(function () use ($type, $amount, $description): BalanceEntry {
            $lockedUser = self::query()->lockForUpdate()->findOrFail($this->id);
            $lockedUser->addToBalance($amount);

            $this->balance = $lockedUser->balance;

            return $lockedUser->balanceEntries()->create([
                'type' => $type,
                'amount' => $amount,
                'description' => $description,
            ]);
        });
    }

    /**
     * Get the total still held for generations in progress.
     */
    public function heldAmount(): string
    {
        return $this->transactions()
            ->where('held_amount', '>', 0)
            ->pluck('held_amount')
            ->reduce(fn (string $total, string $held): string => bcadd($total, $held, 9), '0.000000000');
    }

    /**
     * Whether the balance covers the given amount.
     */
    public function canAfford(string $amount): bool
    {
        return bccomp($this->balance, $amount, 9) >= 0;
    }

    /**
     * Add an amount to the balance, or take it away when the amount is negative.
     *
     * Call this inside a database transaction on a row locked for update.
     */
    public function addToBalance(string $amount): void
    {
        $this->balance = bcadd($this->balance, $amount, 9);
        $this->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance' => 'decimal:9',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
