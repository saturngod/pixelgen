<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\PixelforgeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Copy a finished image from its temporary Pixelforge URL into our private S3 bucket.
 */
class StoreGenerationImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Largest image we accept.
     */
    private const int MaxBytes = 20 * 1024 * 1024;

    /**
     * The first bytes of every PNG file.
     */
    private const string PngSignature = "\x89PNG\r\n\x1a\n";

    public int $tries = 5;

    /**
     * Keep the unique lock while retries are waiting, so the sync command cannot queue a second copy.
     */
    public int $uniqueFor = 3600;

    public function __construct(public string $transactionId) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 120, 600, 1800];
    }

    public function uniqueId(): string
    {
        return $this->transactionId;
    }

    /**
     * Execute the job.
     */
    public function handle(PixelforgeService $pixelforge): void
    {
        $transaction = Transaction::query()->find($this->transactionId);

        if ($transaction === null || $transaction->status !== Transaction::Succeeded || $transaction->image_path !== null) {
            return;
        }

        $response = $this->download($transaction);

        if ($response === null || in_array($response->status(), [403, 404, 410], true)) {
            $this->refreshImageUrl($transaction, $pixelforge);
            $response = $this->download($transaction);
        }

        if ($response === null || $response->status() !== 200) {
            throw new RuntimeException('Could not download the Pixelforge image (HTTP '.($response?->status() ?? 'none').').');
        }

        $image = $response->body();

        if (! $this->isPng($response, $image)) {
            $this->fail(new RuntimeException('Pixelforge returned something that is not a PNG under 20 MB.'));

            return;
        }

        $path = $transaction->imageStoragePath();

        // The s3 disk throws on failure, so the real S3 error reaches the log and the job retries.
        Storage::disk('s3')->put($path, $image);

        // The query skips deleted rows. If the user deleted the generation during the upload, remove the file again.
        $isSaved = Transaction::query()->whereKey($transaction->id)->update([
            'image_path' => $path,
            'image_stored_at' => now(),
        ]) === 1;

        if (! $isSaved) {
            Storage::disk('s3')->delete($path);
        }
    }

    /**
     * Log a copy that failed for good. The generation keeps its status and cost.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Could not store a generation image.', [
            'transaction_id' => $this->transactionId,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Download the image, or return null when there is no usable URL.
     */
    private function download(Transaction $transaction): ?Response
    {
        $url = $transaction->image_url;

        if ($url === null || ! PixelforgeService::isTrustedImageUrl($url)) {
            return null;
        }

        if ($transaction->image_url_expires_at !== null && $transaction->image_url_expires_at->isPast()) {
            return null;
        }

        return Http::withOptions(['allow_redirects' => false])->timeout(30)->get($url);
    }

    /**
     * Ask Pixelforge for a fresh signed URL.
     */
    private function refreshImageUrl(Transaction $transaction, PixelforgeService $pixelforge): void
    {
        $generation = $pixelforge->getGeneration((string) $transaction->pixelforge_id);
        $url = $generation['image_url'] ?? null;

        if (! is_string($url) || ! PixelforgeService::isTrustedImageUrl($url)) {
            return;
        }

        $transaction->forceFill([
            'image_url' => $url,
            'image_url_expires_at' => $generation['image_url_expires_at'] ?? null,
        ])->save();
    }

    private function isPng(Response $response, string $image): bool
    {
        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return $contentType === 'image/png'
            && strlen($image) <= self::MaxBytes
            && str_starts_with($image, self::PngSignature);
    }
}
