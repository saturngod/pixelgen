<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BalanceEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One settled change to a user's balance: money added, or the final cost of a generation.
 *
 * Temporary holds for generations in progress live on the transaction and are not entries.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $transaction_id
 * @property string $type
 * @property string $amount
 * @property string|null $description
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['type', 'amount', 'description', 'transaction_id'])]
class BalanceEntry extends Model
{
    /** @use HasFactory<BalanceEntryFactory> */
    use HasFactory;

    public const string SignupCredit = 'signup_credit';

    public const string TopUp = 'top_up';

    public const string Usage = 'usage';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:9',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Includes deleted generations, so a charge keeps its description.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withTrashed();
    }

    /**
     * Limit to money added to the balance.
     *
     * @param  Builder<BalanceEntry>  $query
     */
    public function scopeCredits(Builder $query): void
    {
        $query->whereIn('type', [self::SignupCredit, self::TopUp]);
    }

    /**
     * Limit to generation charges.
     *
     * @param  Builder<BalanceEntry>  $query
     */
    public function scopeUsage(Builder $query): void
    {
        $query->where('type', self::Usage);
    }

    /**
     * Get the shape the owner's browser receives.
     *
     * @return array{id: int, type: string, title: string, details: string|null, amount: string, generation_id: string|null, created_at: string|null}
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => match ($this->type) {
                self::SignupCredit => 'Free signup credit',
                self::TopUp => 'Top-up',
                default => 'Image generation',
            },
            'details' => $this->clientDetails(),
            'amount' => $this->amount,
            'generation_id' => $this->transaction_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Describe the entry: the note for a top-up, or the prompt and options for a generation.
     */
    private function clientDetails(): ?string
    {
        if ($this->type !== self::Usage) {
            return $this->description;
        }

        if ($this->transaction === null) {
            return null;
        }

        return Str::limit($this->transaction->prompt, 80).' · '.$this->transaction->size.' · '.ucfirst($this->transaction->quality);
    }
}
