<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    configurePixelforge();
    Http::preventStrayRequests();
    $this->travelTo('2026-11-16 12:00:00');
});

/**
 * A Pixelforge usage report with the given cost for each day.
 *
 * @param  array<string, array{0: int, 1: string}>  $days
 * @return array<string, mixed>
 */
function pixelforgeUsage(array $days): array
{
    $data = collect($days)->map(fn (array $day, string $date): array => [
        'date' => $date,
        'generations' => $day[0],
        'input_text_tokens' => 0,
        'input_image_tokens' => 0,
        'output_tokens' => 0,
        'reasoning_tokens' => 0,
        'cost_usd' => $day[1],
    ])->values();

    return [
        'object' => 'usage',
        'data' => $data->all(),
        'total' => [
            'generations' => $data->sum('generations'),
            'cost_usd' => $data->reduce(fn (string $total, array $day): string => bcadd($total, $day['cost_usd'], 9), '0.000000000'),
        ],
    ];
}

test('guests and users who are not admins cannot see the report', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('the report shows credit spend, provider cost and profit by day and user, checked against Pixelforge', function () {
    Http::fake([
        'pixelforge.test/v1/usage?start_date=2026-11-15&end_date=2026-11-16' => Http::response(pixelforgeUsage([
            '2026-11-15' => [2, '0.113100000'],
            '2026-11-16' => [2, '0.113100000'],
        ])),
    ]);
    $admin = User::factory()->admin()->create();
    $alice = User::factory()->create(['name' => 'Alice']);
    $bob = User::factory()->create(['name' => 'Bob']);
    Transaction::factory()->for($alice)->succeeded()->create(['completed_at' => '2026-11-15 23:59:59']);
    Transaction::factory()->for($alice)->succeeded()->create(['completed_at' => '2026-11-16 00:00:00']);
    Transaction::factory()->for($bob)->succeeded()->create(['completed_at' => '2026-11-15 08:00:00', 'deleted_at' => now()]);
    Transaction::factory()->for($bob)->failed()->create(['completed_at' => '2026-11-16 08:00:00']);
    Transaction::factory()->for($bob)->succeeded()->create(['completed_at' => '2026-11-14 23:59:59']);

    $response = $this->actingAs($admin)->get(route('admin.dashboard', ['start_date' => '2026-11-15', 'end_date' => '2026-11-16']));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('admin/Dashboard')
        ->where('pixelforgeError', null)
        ->where('totals.generations', 3)
        ->where('totals.credit_spend', '0.212062500')
        ->where('totals.provider_cost', '0.169650000')
        ->where('totals.profit', '0.042412500')
        ->where('totals.pixelforge_generations', 4)
        ->where('totals.pixelforge_cost', '0.226200000')
        ->where('totals.cost_difference', '-0.056550000')
        ->has('days', 2)
        ->where('days.0.date', '2026-11-16')
        ->where('days.0.generations', 1)
        ->where('days.0.cost_difference', '-0.056550000')
        ->where('days.1.date', '2026-11-15')
        ->where('days.1.generations', 2)
        ->where('days.1.credit_spend', '0.141375000')
        ->where('days.1.provider_cost', '0.113100000')
        ->where('days.1.profit', '0.028275000')
        ->where('days.1.cost_difference', '0.000000000')
        ->has('days.1.users', 2)
        ->has('users', 2)
        ->where('users.0.name', 'Alice')
        ->where('users.0.generations', 2)
        ->where('users.0.credit_spend', '0.141375000')
        ->where('users.1.name', 'Bob')
        ->where('users.1.generations', 1));
});

test('the report defaults to the last 30 days', function () {
    Http::fake([
        'pixelforge.test/v1/usage?start_date=2026-10-18&end_date=2026-11-16' => Http::response(pixelforgeUsage([])),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('startDate', '2026-10-18')
            ->where('endDate', '2026-11-16')
            ->has('days', 30));
});

test('the report still loads when Pixelforge usage is unavailable', function () {
    Http::fake([
        'pixelforge.test/v1/usage?start_date=2026-11-16&end_date=2026-11-16' => Http::response(['error' => ['code' => 'internal_error']], 500),
    ]);
    Transaction::factory()->succeeded()->create(['completed_at' => '2026-11-16 08:00:00']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard', ['start_date' => '2026-11-16', 'end_date' => '2026-11-16']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pixelforgeError', 'Pixelforge usage could not be loaded, so provider costs are not checked against it.')
            ->where('totals.provider_cost', '0.056550000')
            ->where('totals.pixelforge_cost', null)
            ->where('days.0.cost_difference', null));
});

test('the date range is validated', function (array $query, string $field) {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard', $query))
        ->assertSessionHasErrors($field);

    Http::assertNothingSent();
})->with([
    'bad format' => [['start_date' => '16/11/2026', 'end_date' => '2026-11-16'], 'start_date'],
    'end before start' => [['start_date' => '2026-11-10', 'end_date' => '2026-11-09'], 'end_date'],
    'end in the future' => [['start_date' => '2026-11-10', 'end_date' => '2026-11-17'], 'end_date'],
    'only one end' => [['start_date' => '2026-11-10'], 'end_date'],
    'more than 92 days' => [['start_date' => '2026-08-16', 'end_date' => '2026-11-16'], 'start_date'],
]);
