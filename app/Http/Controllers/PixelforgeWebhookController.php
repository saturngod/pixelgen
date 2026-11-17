<?php

namespace App\Http\Controllers;

use App\Http\Requests\PixelforgeWebhookRequest;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use Illuminate\Http\Response;

class PixelforgeWebhookController extends Controller
{
    /**
     * Apply a Pixelforge webhook event to its transaction.
     *
     * Duplicate, late and out-of-order events are acknowledged without changing anything.
     */
    public function store(PixelforgeWebhookRequest $request): Response
    {
        $event = $request->validated();

        if (! in_array($event['type'], PixelforgeWebhookRequest::GenerationEvents, true)) {
            WebhookDelivery::recordSigned($request, WebhookDelivery::Ignored);

            return response()->noContent();
        }

        $generation = $event['data']['object'];
        $transaction = $this->findTransaction($generation);

        if ($transaction === null) {
            // The row is saved before the create call, so this is rare. Ask Pixelforge to retry later.
            WebhookDelivery::recordSigned($request, WebhookDelivery::UnknownGeneration, [
                'reason' => "No transaction for generation {$generation['id']}.",
            ]);

            return response('Unknown generation.', 503);
        }

        $isNewEvent = Transaction::recordGeneration($transaction->id, $generation, ['id' => $event['id'], 'type' => $event['type']]);

        WebhookDelivery::recordSigned(
            $request,
            $isNewEvent ? WebhookDelivery::Processed : WebhookDelivery::Duplicate,
            ['transaction_id' => $transaction->id],
        );

        return response()->noContent();
    }

    /**
     * Find the transaction by the id we sent in metadata, then by the Pixelforge id.
     *
     * @param  array<string, mixed>  $generation
     */
    private function findTransaction(array $generation): ?Transaction
    {
        $transactionId = $generation['metadata']['transaction_id'] ?? null;

        if (is_string($transactionId)) {
            $transaction = Transaction::withTrashed()->find($transactionId);

            if ($transaction !== null) {
                return $transaction;
            }
        }

        return Transaction::withTrashed()->where('pixelforge_id', $generation['id'])->first();
    }
}
