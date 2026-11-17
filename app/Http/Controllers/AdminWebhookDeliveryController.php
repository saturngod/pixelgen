<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminWebhookDeliveryIndexRequest;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets operators check which Pixelforge webhook requests reached us and what we did with each one.
 */
class AdminWebhookDeliveryController extends Controller
{
    /**
     * Show webhook requests newest first, filtered by UTC date range and outcome, with a count per outcome.
     */
    public function index(AdminWebhookDeliveryIndexRequest $request): Response
    {
        $startDate = $request->startDate();
        $endDate = $request->endDate();
        $outcome = $request->validated('outcome');

        $inRange = fn (): Builder => WebhookDelivery::query()
            ->when($startDate, fn (Builder $query) => $query->where('created_at', '>=', $startDate))
            ->when($endDate, fn (Builder $query) => $query->where('created_at', '<', $endDate->addDay()));

        $outcomeCounts = $inRange()
            ->selectRaw('outcome, count(*) as total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $deliveries = $inRange()
            ->when($outcome, fn (Builder $query) => $query->where('outcome', $outcome))
            ->latest()
            ->orderByDesc('id')
            ->simplePaginate(25)
            ->withQueryString()
            ->through(fn (WebhookDelivery $delivery): array => [
                'id' => $delivery->id,
                'outcome' => $delivery->outcome,
                'reason' => $delivery->reason,
                'event_id' => $delivery->event_id,
                'type' => $delivery->type,
                'transaction_id' => $delivery->transaction_id,
                'ip' => $delivery->ip,
                'payload' => $delivery->payload,
                'created_at' => $delivery->created_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/Webhooks', [
            'startDate' => $startDate?->toDateString(),
            'endDate' => $endDate?->toDateString(),
            'outcome' => $outcome,
            'outcomes' => WebhookDelivery::Outcomes,
            'outcomeCounts' => collect(WebhookDelivery::Outcomes)
                ->mapWithKeys(fn (string $name): array => [$name => (int) $outcomeCounts->get($name, 0)]),
            'lastReceivedAt' => WebhookDelivery::query()->latest()->orderByDesc('id')->first(['created_at'])?->created_at?->toIso8601String(),
            'deliveries' => $deliveries,
        ]);
    }
}
