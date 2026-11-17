<?php

namespace App\Http\Controllers;

use App\Exceptions\PixelforgeRequestException;
use App\Http\Requests\AdminDashboardIndexRequest;
use App\Models\Transaction;
use App\Services\PixelforgeService;
use Carbon\CarbonPeriodImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operators' report: what users were charged, what Pixelforge charged us, and the profit.
 *
 * A generation counts on the UTC day it completed, the same day Pixelforge bills it.
 */
class AdminDashboardController extends Controller
{
    /**
     * Show credit spend, provider cost and profit for each day and each user, checked against Pixelforge's usage report.
     */
    public function index(AdminDashboardIndexRequest $request, PixelforgeService $pixelforge): Response
    {
        $startDate = $request->startDate();
        $endDate = $request->endDate();

        // Deleted generations were still charged, so they stay in the report.
        $transactions = Transaction::withTrashed()
            ->with('user:id,name,email')
            ->where('status', Transaction::Succeeded)
            ->where('completed_at', '>=', $startDate)
            ->where('completed_at', '<', $endDate->addDay())
            ->get(['id', 'user_id', 'provider_cost', 'actual_cost', 'completed_at']);

        $pixelforgeDays = null;
        $pixelforgeTotal = null;
        $pixelforgeError = null;

        try {
            $usage = $pixelforge->usage($startDate->toDateString(), $endDate->toDateString());
            $pixelforgeDays = collect($usage['data'])->keyBy('date');
            $pixelforgeTotal = $usage['total'];
        } catch (PixelforgeRequestException $exception) {
            Log::warning('Could not load the Pixelforge usage report.', ['error_code' => $exception->errorCode]);
            $pixelforgeError = 'Pixelforge usage could not be loaded, so provider costs are not checked against it.';
        }

        $transactionsByDay = $transactions->groupBy(fn (Transaction $transaction): string => $transaction->completed_at->utc()->toDateString());

        $days = collect(CarbonPeriodImmutable::create($startDate, $endDate))
            ->map(function ($day) use ($transactionsByDay, $pixelforgeDays): array {
                $date = $day->toDateString();
                $dayTransactions = $transactionsByDay->get($date, collect());
                $pixelforgeDay = $pixelforgeDays?->get($date);

                return [
                    'date' => $date,
                    ...$this->summarize($dayTransactions),
                    ...$this->reconcile($dayTransactions, $pixelforgeDay),
                    'users' => $this->summarizeByUser($dayTransactions),
                ];
            })
            ->reverse()
            ->values();

        return Inertia::render('admin/Dashboard', [
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'maxDays' => AdminDashboardIndexRequest::MaxDays,
            'totals' => [
                ...$this->summarize($transactions),
                ...$this->reconcile($transactions, $pixelforgeTotal),
            ],
            'days' => $days,
            'users' => $this->summarizeByUser($transactions),
            'pixelforgeError' => $pixelforgeError,
        ]);
    }

    /**
     * Add up what users paid and what the generations cost us.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array{generations: int, credit_spend: string, provider_cost: string, profit: string}
     */
    private function summarize(Collection $transactions): array
    {
        $creditSpend = $transactions->reduce(fn (string $total, Transaction $transaction): string => bcadd($total, $transaction->actual_cost ?? '0', 9), '0');
        $providerCost = $transactions->reduce(fn (string $total, Transaction $transaction): string => bcadd($total, $transaction->provider_cost ?? '0', 9), '0');

        return [
            'generations' => $transactions->count(),
            'credit_spend' => $creditSpend,
            'provider_cost' => $providerCost,
            'profit' => bcsub($creditSpend, $providerCost, 9),
        ];
    }

    /**
     * Compare our recorded provider cost with what Pixelforge billed. Null values mean Pixelforge could not be asked.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  array{generations: int, cost_usd: string}|null  $pixelforgeUsage
     * @return array{pixelforge_generations: int|null, pixelforge_cost: string|null, cost_difference: string|null}
     */
    private function reconcile(Collection $transactions, ?array $pixelforgeUsage): array
    {
        if ($pixelforgeUsage === null) {
            return ['pixelforge_generations' => null, 'pixelforge_cost' => null, 'cost_difference' => null];
        }

        $providerCost = $this->summarize($transactions)['provider_cost'];

        return [
            'pixelforge_generations' => $pixelforgeUsage['generations'],
            'pixelforge_cost' => bcadd($pixelforgeUsage['cost_usd'], '0', 9),
            'cost_difference' => bcsub($providerCost, $pixelforgeUsage['cost_usd'], 9),
        ];
    }

    /**
     * Summarize each user's generations, biggest spender first.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array{user_id: int, name: string|null, email: string|null, generations: int, credit_spend: string, provider_cost: string, profit: string}>
     */
    private function summarizeByUser(Collection $transactions): array
    {
        return $transactions
            ->groupBy('user_id')
            ->map(fn (Collection $userTransactions, int $userId): array => [
                'user_id' => $userId,
                'name' => $userTransactions->first()->user?->name,
                'email' => $userTransactions->first()->user?->email,
                ...$this->summarize($userTransactions),
            ])
            ->sort(fn (array $first, array $second): int => bccomp($second['credit_spend'], $first['credit_spend'], 9) ?: $first['user_id'] <=> $second['user_id'])
            ->values()
            ->all();
    }
}
