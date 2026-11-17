<?php

use App\Models\User;
use App\Models\WebhookDelivery;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->travelTo('2026-11-16 12:00:00');
});

test('guests and users who are not admins cannot see webhook requests', function () {
    $this->get(route('admin.webhooks.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('admin.webhooks.index'))
        ->assertForbidden();
});

test('webhook requests are listed newest first with a count for each outcome', function () {
    $older = WebhookDelivery::factory()->create(['created_at' => '2026-11-14 08:00:00']);
    $rejected = WebhookDelivery::factory()->invalidSignature()->create(['created_at' => '2026-11-16 09:00:00']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.webhooks.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Webhooks')
            ->where('startDate', null)
            ->where('endDate', null)
            ->where('lastReceivedAt', '2026-11-16T09:00:00+00:00')
            ->where('outcomeCounts.processed', 1)
            ->where('outcomeCounts.invalid_signature', 1)
            ->where('outcomeCounts.ignored', 0)
            ->has('deliveries.data', 2)
            ->where('deliveries.data.0.id', $rejected->id)
            ->where('deliveries.data.0.reason', 'signature mismatch')
            ->where('deliveries.data.0.payload', null)
            ->where('deliveries.data.1.id', $older->id)
            ->where('deliveries.data.1.event_id', $older->event_id));
});

test('webhook requests can be filtered by UTC date range and outcome', function () {
    WebhookDelivery::factory()->create(['created_at' => '2026-11-13 23:59:59']);
    $inRange = WebhookDelivery::factory()->create(['created_at' => '2026-11-14 00:00:00']);
    $lastSecond = WebhookDelivery::factory()->create(['created_at' => '2026-11-15 23:59:59']);
    WebhookDelivery::factory()->invalidSignature()->create(['created_at' => '2026-11-15 10:00:00']);
    WebhookDelivery::factory()->create(['created_at' => '2026-11-16 00:00:00']);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.webhooks.index', ['start_date' => '2026-11-14', 'end_date' => '2026-11-15', 'outcome' => 'processed']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('startDate', '2026-11-14')
            ->where('endDate', '2026-11-15')
            ->where('outcome', 'processed')
            ->where('outcomeCounts.processed', 2)
            ->where('outcomeCounts.invalid_signature', 1)
            ->has('deliveries.data', 2)
            ->where('deliveries.data.0.id', $lastSecond->id)
            ->where('deliveries.data.1.id', $inRange->id));
});

test('the webhook filter is validated', function (array $query, string $field) {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.webhooks.index', $query))
        ->assertSessionHasErrors($field);
})->with([
    'bad date format' => [['start_date' => '16/11/2026'], 'start_date'],
    'end before start' => [['start_date' => '2026-11-10', 'end_date' => '2026-11-09'], 'end_date'],
    'unknown outcome' => [['outcome' => 'delivered'], 'outcome'],
]);
