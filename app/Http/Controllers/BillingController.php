<?php

namespace App\Http\Controllers;

use App\Http\Requests\BillingIndexRequest;
use App\Models\BalanceEntry;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    /**
     * Show the user's balance with their top-ups and generation charges, newest first.
     */
    public function index(BillingIndexRequest $request): Response
    {
        $user = $request->user();
        $type = $request->validated('type');

        $entries = $user->balanceEntries()
            ->with('transaction')
            ->when($type === 'credits', fn ($query) => $query->credits())
            ->when($type === 'usage', fn ($query) => $query->usage())
            ->latest()
            ->orderByDesc('id')
            ->simplePaginate(20)
            ->withQueryString()
            ->through(fn (BalanceEntry $entry): array => $entry->toClientArray());

        return Inertia::render('billing/Index', [
            'balance' => $user->balance,
            'heldAmount' => $user->heldAmount(),
            'type' => $type,
            'entries' => $entries,
        ]);
    }
}
