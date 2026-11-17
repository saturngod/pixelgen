<?php

namespace App\Http\Controllers;

use App\Exceptions\PixelforgeRequestException;
use App\Exceptions\PricingUnavailableException;
use App\Http\Requests\GenerationEstimateRequest;
use App\Http\Requests\GenerationStatusesRequest;
use App\Http\Requests\GenerationStoreRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PixelforgeService;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * Show the user's generation history, newest first.
     */
    public function index(Request $request): Response
    {
        $generations = $request->user()->transactions()
            ->latest()
            ->orderByDesc('id')
            ->simplePaginate(12)
            ->through(fn (Transaction $transaction): array => $transaction->toClientArray());

        return Inertia::render('generations/Index', [
            'generations' => $generations,
        ]);
    }

    /**
     * Estimate what a generation will cost before it is submitted.
     */
    public function estimate(GenerationEstimateRequest $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validated();

        try {
            $estimate = $pricing->estimate(
                prompt: $data['prompt'],
                size: $data['size'],
                quality: $data['quality'],
                referenceImageCount: count(array_filter($data['reference_images'] ?? [])),
            );
        } catch (PricingUnavailableException $exception) {
            report($exception);

            return response()->json([
                'message' => 'Pricing is unavailable right now. Please try again shortly.',
            ], 503);
        }

        return response()->json($estimate);
    }

    /**
     * Save the generation with its estimate, hold the maximum price from the balance, then submit it to Pixelforge.
     *
     * Each user may have one active generation, so one user cannot use all of the shared Pixelforge slots.
     */
    public function store(GenerationStoreRequest $request, PricingService $pricing, PixelforgeService $pixelforge): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $referenceImages = array_values(array_filter($data['reference_images'] ?? []));

        $lock = Cache::lock("generations:user:{$user->id}", 10);

        if (! $lock->get()) {
            return back()->withErrors(['generation' => 'Please wait for your current image to finish.']);
        }

        try {
            if ($user->transactions()->active()->exists()) {
                return back()->withErrors(['generation' => 'Please wait for your current image to finish.']);
            }

            try {
                $estimate = $pricing->estimate(
                    prompt: $data['prompt'],
                    size: $data['size'],
                    quality: $data['quality'],
                    referenceImageCount: count($referenceImages),
                );
            } catch (PricingUnavailableException $exception) {
                report($exception);

                return back()->withErrors(['generation' => 'Pricing is unavailable right now. Please try again shortly.']);
            }

            $transaction = DB::transaction(function () use ($user, $data, $referenceImages, $estimate): ?Transaction {
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

                if (! $lockedUser->canAfford($estimate['max_price'])) {
                    return null;
                }

                $transaction = $lockedUser->transactions()->make([
                    'prompt' => $data['prompt'],
                    'size' => $data['size'],
                    'quality' => $data['quality'],
                    'reference_images' => $referenceImages,
                ]);
                $transaction->status = Transaction::Queued;
                $transaction->estimated_min_price = $estimate['min_price'];
                $transaction->estimated_max_price = $estimate['max_price'];
                $transaction->held_amount = $estimate['max_price'];
                $transaction->save();

                $lockedUser->addToBalance(bcsub('0', $estimate['max_price'], 9));

                return $transaction;
            });

            if ($transaction === null) {
                return back()->withErrors(['generation' => 'Your balance is too low for this image. Try a smaller size or lower quality.']);
            }
        } finally {
            $lock->release();
        }

        try {
            $generation = $pixelforge->createGeneration($transaction);
        } catch (PixelforgeRequestException $exception) {
            Log::warning('Pixelforge did not accept a generation.', [
                'transaction_id' => $transaction->id,
                'error_code' => $exception->errorCode,
                'status' => $exception->status,
            ]);

            $transaction->markSubmissionFailed(
                $exception->status === null ? Transaction::SubmissionFailed : $exception->errorCode,
                $exception->getMessage(),
            );

            return back()->withErrors(['generation' => $this->submissionErrorMessage($exception)]);
        }

        Transaction::recordGeneration($transaction->id, $generation);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your image is on its way.']);

        return to_route('generations.index');
    }

    /**
     * Get one of the user's generations, for live status updates.
     *
     * A row whose webhook may have been missed is checked against Pixelforge first.
     */
    public function show(Request $request, string $transaction, PixelforgeService $pixelforge): JsonResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($transaction);

        if ($transaction->isStuck()) {
            $transaction->syncWithPixelforge($pixelforge);
        }

        return response()->json($transaction->toClientArray());
    }

    /**
     * Get several of the user's generations in one request, so a page polls once for all its unfinished cards.
     *
     * Ids that belong to someone else are left out.
     */
    public function statuses(GenerationStatusesRequest $request, PixelforgeService $pixelforge): JsonResponse
    {
        $transactions = $request->user()->transactions()
            ->whereKey($request->validated('ids'))
            ->get();

        foreach ($transactions as $transaction) {
            if ($transaction->isStuck()) {
                $transaction->syncWithPixelforge($pixelforge);
            }
        }

        return response()->json([
            'generations' => $transactions->map(fn (Transaction $transaction): array => $transaction->toClientArray())->values(),
        ]);
    }

    /**
     * Send the user to a short-lived S3 link that saves the image as a file instead of opening it.
     */
    public function download(Request $request, string $transaction): RedirectResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($transaction);

        abort_if($transaction->image_path === null, 404);

        return redirect()->away(Storage::disk('s3')->temporaryUrl(
            $transaction->image_path,
            now()->addMinutes(5),
            ['ResponseContentDisposition' => "attachment; filename=\"pixelgen-{$transaction->id}.png\""],
        ));
    }

    /**
     * Delete one of the user's finished generations and its image in S3. The charge stays in the billing history.
     */
    public function destroy(Request $request, string $transaction): RedirectResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($transaction);

        if (! $transaction->deleteWithImage()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'You can delete this image once it has finished.']);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Image deleted.']);

        return back();
    }

    /**
     * Turn a Pixelforge error into a message for the user. The raw error is only logged.
     */
    private function submissionErrorMessage(PixelforgeRequestException $exception): string
    {
        return match ($exception->errorCode) {
            'content_policy_violation' => 'This prompt is not allowed. Please change it and try again.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded' => 'We are very busy right now. Please try again in a moment.',
            'daily_limit_exceeded' => 'We have reached today\'s image limit. Please try again tomorrow.',
            default => 'Image generation is unavailable right now. Please try again shortly.',
        };
    }
}
