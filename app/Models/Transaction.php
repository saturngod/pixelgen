<?php

namespace App\Models;

use App\Exceptions\PixelforgeRequestException;
use App\Jobs\StoreGenerationImage;
use App\Services\PixelforgeService;
use App\Services\PricingService;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * One image generation requested by a user, with its estimated and actual cost.
 *
 * The estimated maximum price is held from the user's balance when the generation is saved.
 * When the generation reaches a final status, the hold is released and the actual cost is charged.
 *
 * @property string $id
 * @property int $user_id
 * @property string|null $pixelforge_id
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property array<int, string>|null $reference_images
 * @property string $status
 * @property string $estimated_min_price
 * @property string $estimated_max_price
 * @property string $held_amount
 * @property array<string, int>|null $usage
 * @property array<string, string>|null $rates
 * @property string|null $provider_cost
 * @property string|null $actual_cost
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $image_path
 * @property CarbonImmutable|null $image_stored_at
 * @property CarbonImmutable|null $image_failed_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['prompt', 'size', 'quality', 'reference_images'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public const string Queued = 'queued';

    public const string Processing = 'processing';

    public const string Succeeded = 'succeeded';

    public const string Failed = 'failed';

    /**
     * Error code for a generation we could not submit to Pixelforge.
     */
    public const string SubmissionFailed = 'submission_failed';

    /**
     * A row still waiting this long is checked against Pixelforge directly.
     */
    private const int StuckAfterSeconds = 120;

    /**
     * Pixelforge is asked about one row at most this often, to protect the shared rate limit.
     */
    private const int SyncIntervalSeconds = 30;

    /**
     * Rows older than this are not counted as active, so a lost generation cannot block a user forever.
     */
    private const int ActiveWindowMinutes = 10;

    /**
     * How long a signed image link works.
     */
    private const int ImageLinkMinutes = 30;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'estimated_min_price' => 'decimal:2',
            'estimated_max_price' => 'decimal:2',
            'held_amount' => 'decimal:9',
            'usage' => 'array',
            'rates' => 'array',
            'provider_cost' => 'decimal:9',
            'actual_cost' => 'decimal:9',
            'image_url_expires_at' => 'datetime',
            'image_stored_at' => 'datetime',
            'image_failed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
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
     * Limit to generations that are still queued or processing and were created recently.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [self::Queued, self::Processing])
            ->where('created_at', '>=', now()->subMinutes(self::ActiveWindowMinutes));
    }

    /**
     * Limit to generations whose webhook may have been missed.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeStuck(Builder $query): void
    {
        $query->whereIn('status', [self::Queued, self::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('created_at', '<=', now()->subSeconds(self::StuckAfterSeconds))
            ->where(function (Builder $query) {
                $query->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<=', now()->subSeconds(self::SyncIntervalSeconds));
            });
    }

    /**
     * Limit to generations that have no answer from Pixelforge yet, because the create call timed out or the request died.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeNeverSubmitted(Builder $query): void
    {
        $query->where('status', self::Queued)
            ->whereNull('pixelforge_id')
            ->where('created_at', '<=', now()->subSeconds(self::StuckAfterSeconds));
    }

    /**
     * Limit to succeeded generations whose image copy is missing while the Pixelforge URL may still work.
     *
     * A copy that already failed for good is left out, so it is not queued again every minute.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeMissingImage(Builder $query): void
    {
        $query->where('status', self::Succeeded)
            ->whereNull('image_path')
            ->whereNull('image_failed_at')
            ->where('completed_at', '<=', now()->subMinutes(5))
            ->where('completed_at', '>=', now()->subDay());
    }

    /**
     * Whether the status can no longer change.
     *
     * A local submission failure has no Pixelforge id, so a later Pixelforge event may still update it.
     */
    public function hasFinalStatus(): bool
    {
        return $this->status === self::Succeeded
            || ($this->status === self::Failed && $this->pixelforge_id !== null);
    }

    /**
     * Whether the user may delete this generation.
     *
     * A queued or processing generation still holds money from the balance, so it must finish first.
     */
    public function isDeletable(): bool
    {
        return in_array($this->status, [self::Succeeded, self::Failed], true);
    }

    /**
     * Hide the generation from the user and delete its image from S3.
     *
     * The row is soft deleted, so the billing history keeps the charge.
     * The S3 file is deleted inside the same database transaction, so a failed delete leaves the row as it was.
     * An image copy that finishes after this sees the deleted row and removes its own upload.
     *
     * Returns false, and changes nothing, when the generation is still queued or processing.
     */
    public function deleteWithImage(): bool
    {
        return DB::transaction(function (): bool {
            $transaction = self::query()->lockForUpdate()->findOrFail($this->id);

            if (! $transaction->isDeletable()) {
                return false;
            }

            $transaction->forceFill([
                'image_url' => null,
                'image_url_expires_at' => null,
                'image_path' => null,
                'image_stored_at' => null,
            ])->save();
            $transaction->delete();

            Storage::disk('s3')->delete($transaction->imageStoragePath());

            return true;
        });
    }

    /**
     * Whether this row should be checked against Pixelforge now.
     */
    public function isStuck(): bool
    {
        return in_array($this->status, [self::Queued, self::Processing], true)
            && $this->pixelforge_id !== null
            && $this->created_at->lte(now()->subSeconds(self::StuckAfterSeconds))
            && ($this->last_synced_at === null || $this->last_synced_at->lte(now()->subSeconds(self::SyncIntervalSeconds)));
    }

    /**
     * Mark the generation as failed before Pixelforge accepted it. It costs nothing.
     *
     * The row is locked and read again first. A webhook can arrive before the create call returns,
     * so a row Pixelforge has already answered for is left alone and its hold is not returned twice.
     *
     * Returns false, and changes nothing, when the row is no longer waiting to be submitted.
     */
    public function markSubmissionFailed(string $errorCode, string $errorMessage): bool
    {
        $isMarked = DB::transaction(function () use ($errorCode, $errorMessage): bool {
            $transaction = self::withTrashed()->lockForUpdate()->find($this->id);

            if ($transaction === null || $transaction->status !== self::Queued || $transaction->pixelforge_id !== null) {
                return false;
            }

            $transaction->status = self::Failed;
            $transaction->error_code = $errorCode;
            $transaction->error_message = $errorMessage;
            $transaction->provider_cost = '0';
            $transaction->actual_cost = '0';
            $transaction->completed_at = now();
            $transaction->settleBalance('0');
            $transaction->save();

            return true;
        });

        $this->refresh();

        return $isMarked;
    }

    /**
     * Apply a Pixelforge generation object to the row, inside a locked database transaction.
     *
     * When an event id is given, the event is recorded in the same transaction and a repeat is ignored.
     * The image copy is queued only when this call moved the row to succeeded.
     *
     * Returns false when the event was already recorded, so nothing was applied.
     *
     * @param  array<string, mixed>  $generation
     * @param  array{id: string, type: string}|null  $event
     */
    public static function recordGeneration(string $transactionId, array $generation, ?array $event = null): bool
    {
        $hasJustSucceeded = DB::transaction(function () use ($transactionId, $generation, $event): ?bool {
            // A deleted row still records its webhook events, so Pixelforge is not asked to retry them.
            $transaction = self::withTrashed()->lockForUpdate()->find($transactionId);

            if ($transaction === null) {
                return false;
            }

            if ($event !== null) {
                if (WebhookEvent::query()->where('event_id', $event['id'])->exists()) {
                    return null;
                }

                WebhookEvent::query()->create([
                    'event_id' => $event['id'],
                    'type' => $event['type'],
                    'transaction_id' => $transaction->id,
                ]);
            }

            return $transaction->applyGeneration($generation);
        });

        if ($hasJustSucceeded && self::query()->whereKey($transactionId)->exists()) {
            StoreGenerationImage::dispatch($transactionId);
        }

        return $hasJustSucceeded !== null;
    }

    /**
     * Submit a generation whose first submission has no known result, for example after a timeout or a crash.
     *
     * The transaction id is the idempotency key, so Pixelforge returns the generation it already has instead of making a second one.
     * A clear refusal fails the row and returns the hold. With no answer, the row is tried again until it is too old to count as active.
     */
    public function resubmit(PixelforgeService $pixelforge): void
    {
        try {
            $generation = $pixelforge->createGeneration($this);
        } catch (PixelforgeRequestException $exception) {
            $hasGivenUp = $this->created_at->lte(now()->subMinutes(self::ActiveWindowMinutes));

            if ($exception->status !== null || $hasGivenUp) {
                $this->markSubmissionFailed(
                    $exception->status === null ? self::SubmissionFailed : $exception->errorCode,
                    $exception->getMessage(),
                );
            }

            return;
        }

        self::recordGeneration($this->id, $generation);
    }

    /**
     * Move the row forward to match Pixelforge. Final statuses never change.
     *
     * Returns true when the row has just become succeeded.
     *
     * @param  array<string, mixed>  $generation
     */
    public function applyGeneration(array $generation): bool
    {
        if ($this->pixelforge_id !== null && $this->pixelforge_id !== $generation['id']) {
            Log::warning('Pixelforge generation id does not match the transaction.', [
                'transaction_id' => $this->id,
                'pixelforge_id' => $generation['id'],
            ]);

            return false;
        }

        if ($this->hasFinalStatus()) {
            return false;
        }

        $this->pixelforge_id = $generation['id'];

        match ($generation['status']) {
            self::Processing => $this->markProcessing($generation),
            self::Succeeded => $this->markSucceeded($generation),
            self::Failed => $this->markFailed($generation),
            default => null,
        };

        $this->save();

        return $this->status === self::Succeeded;
    }

    /**
     * Ask Pixelforge for the latest state of a stuck row and apply it.
     *
     * The row is claimed with one conditional update, so two callers never ask Pixelforge about it at the same time.
     */
    public function syncWithPixelforge(PixelforgeService $pixelforge): void
    {
        $isClaimed = self::query()->whereKey($this->id)->stuck()->update(['last_synced_at' => now()]) === 1;

        if (! $isClaimed) {
            return;
        }

        try {
            $generation = $pixelforge->getGeneration($this->pixelforge_id);
        } catch (PixelforgeRequestException $exception) {
            Log::warning('Could not sync a generation with Pixelforge.', [
                'transaction_id' => $this->id,
                'error_code' => $exception->errorCode,
            ]);

            return;
        }

        self::recordGeneration($this->id, $generation);

        $this->refresh();
    }

    /**
     * Get the shape the owner's browser receives. Never exposes the S3 key or the Pixelforge URL.
     *
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'status' => $this->status,
            'estimated_min_price' => $this->estimated_min_price,
            'estimated_max_price' => $this->estimated_max_price,
            'actual_cost' => $this->actual_cost,
            'image_url' => $this->signedImageUrl(),
            'image_pending' => $this->status === self::Succeeded && $this->image_path === null,
            'error' => $this->status === self::Failed ? $this->clientErrorMessage() : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Get a short-lived link to our private copy of the image.
     */
    public function signedImageUrl(): ?string
    {
        if ($this->image_path === null) {
            return null;
        }

        return Storage::disk('s3')->temporaryUrl($this->image_path, now()->addMinutes(self::ImageLinkMinutes));
    }

    /**
     * Get the S3 key for the stored image.
     */
    public function imageStoragePath(): string
    {
        return "generations/{$this->user_id}/{$this->id}.png";
    }

    /**
     * @param  array<string, mixed>  $generation
     */
    private function markProcessing(array $generation): void
    {
        $this->status = self::Processing;
        $this->started_at = $generation['started_at'] ?? now();
        $this->error_code = null;
        $this->error_message = null;
    }

    /**
     * @param  array<string, mixed>  $generation
     */
    private function markSucceeded(array $generation): void
    {
        $cost = app(PricingService::class)->actualCost($generation['usage'], $generation['rates']);

        $this->status = self::Succeeded;
        $this->usage = $generation['usage'];
        $this->rates = $generation['rates'];
        $this->provider_cost = $cost['provider_cost'];
        $this->actual_cost = $cost['actual_cost'];
        $this->settleBalance($cost['actual_cost']);
        $this->started_at ??= $generation['started_at'] ?? null;
        $this->completed_at = $generation['completed_at'] ?? now();
        $this->error_code = null;
        $this->error_message = null;

        $imageUrl = $generation['image_url'] ?? null;

        if (is_string($imageUrl) && PixelforgeService::isTrustedImageUrl($imageUrl)) {
            $this->image_url = $imageUrl;
            $this->image_url_expires_at = $generation['image_url_expires_at'] ?? null;
        } elseif ($imageUrl !== null) {
            Log::warning('Ignored a Pixelforge image URL from an unexpected host.', ['transaction_id' => $this->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $generation
     */
    private function markFailed(array $generation): void
    {
        $this->status = self::Failed;
        $this->provider_cost = '0';
        $this->actual_cost = '0';
        $this->settleBalance('0');
        $this->error_code = $generation['error']['code'] ?? 'generation_failed';
        $this->error_message = $generation['error']['message'] ?? null;
        $this->completed_at = $generation['completed_at'] ?? now();
    }

    /**
     * Give back what is still held and charge the given cost, on the user's locked row.
     * A charge is recorded in the user's billing history.
     *
     * The hold is cleared, so a later status change only charges the cost and never returns the hold twice.
     * Call this inside a database transaction. The caller saves this row.
     */
    private function settleBalance(string $charge): void
    {
        $user = User::query()->lockForUpdate()->findOrFail($this->user_id);
        $user->addToBalance(bcsub($this->held_amount, $charge, 9));

        if (bccomp($charge, '0', 9) > 0) {
            $user->balanceEntries()->create([
                'type' => BalanceEntry::Usage,
                'amount' => bcsub('0', $charge, 9),
                'transaction_id' => $this->id,
            ]);
        }

        $this->held_amount = '0';
    }

    /**
     * Turn an error code into a message we are happy to show users.
     */
    private function clientErrorMessage(): string
    {
        return match ($this->error_code) {
            'generation_failed' => 'The image could not be made from this prompt. You were not charged.',
            'content_policy_violation' => 'This prompt is not allowed. You were not charged.',
            self::SubmissionFailed, 'overloaded', 'rate_limit_exceeded', 'concurrency_limit_exceeded', 'daily_limit_exceeded' => 'We could not start this image. Please try again. You were not charged.',
            default => 'Something went wrong while making the image. You were not charged.',
        };
    }
}
