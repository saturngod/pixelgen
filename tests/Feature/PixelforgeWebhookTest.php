<?php

use App\Jobs\StoreGenerationImage;
use App\Models\BalanceEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

beforeEach(function () {
    configurePixelforge();
});

/**
 * @param  array<string, mixed>  $generation
 * @return array<string, mixed>
 */
function webhookEvent(string $type, array $generation, string $eventId = 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A'): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-16T10:00:30Z',
        'data' => ['object' => $generation],
    ];
}

/**
 * Send a raw webhook body with a Pixelforge signature header.
 */
function sendWebhook(TestCase $test, string $body, ?string $signatureHeader): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signatureHeader !== null) {
        $server['HTTP_PIXELFORGE_SIGNATURE'] = $signatureHeader;
    }

    return $test->call('POST', route('webhooks.pixelforge'), server: $server, content: $body);
}

/**
 * Send an event signed the way Pixelforge signs it.
 *
 * @param  array<string, mixed>  $event
 */
function sendSignedWebhook(TestCase $test, array $event, ?int $timestamp = null, string $secret = 'whsec_test'): TestResponse
{
    $body = json_encode($event);
    $timestamp ??= now()->getTimestamp();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return sendWebhook($test, $body, "t={$timestamp},v1={$signature}");
}

test('a processing event moves a queued generation to processing', function () {
    $transaction = Transaction::factory()->create();

    $response = sendSignedWebhook($this, webhookEvent('generation.processing', pixelforgeGeneration([
        'id' => $transaction->pixelforge_id,
        'status' => 'processing',
        'started_at' => '2026-11-16T10:00:02Z',
    ])));

    $response->assertNoContent();
    expect($transaction->fresh())
        ->status->toBe(Transaction::Processing)
        ->started_at->toIso8601ZuluString()->toBe('2026-11-16T10:00:02Z');
    expect(WebhookEvent::query()->where('event_id', 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A')->exists())->toBeTrue();
    expect(WebhookDelivery::query()->sole())
        ->outcome->toBe(WebhookDelivery::Processed)
        ->transaction_id->toBe($transaction->id)
        ->payload->toMatchArray(['id' => 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A', 'type' => 'generation.processing']);
});

test('a succeeded event records the usage, the exact cost and the image url', function () {
    Queue::fake([StoreGenerationImage::class]);
    $transaction = Transaction::factory()->processing()->create();

    $response = sendSignedWebhook($this, webhookEvent('generation.succeeded', succeededGeneration($transaction->pixelforge_id)));

    $response->assertNoContent();
    expect($transaction->fresh())
        ->status->toBe(Transaction::Succeeded)
        ->provider_cost->toBe('0.056572500')
        ->actual_cost->toBe('0.070715625')
        ->usage->toMatchArray(['output_tokens' => 1100, 'reasoning_tokens' => 1001])
        ->image_url->toBe("https://pixelforge.test/images/{$transaction->pixelforge_id}.png?expires=1&signature=abc")
        ->completed_at->toIso8601ZuluString()->toBe('2026-11-16T10:00:30Z');
    Queue::assertPushed(StoreGenerationImage::class, fn (StoreGenerationImage $job) => $job->transactionId === $transaction->id);
});

test('the user price is rounded up at the ninth decimal place', function () {
    Queue::fake([StoreGenerationImage::class]);
    $transaction = Transaction::factory()->processing()->create();

    sendSignedWebhook($this, webhookEvent('generation.succeeded', succeededGeneration($transaction->pixelforge_id, [
        'usage' => ['input_text_tokens' => 0, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 1, 'total_tokens' => 1],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.51'],
    ])))->assertNoContent();

    // $0.00001251 x 1.25 = $0.0000156375, which rounds up to $0.000015638.
    expect($transaction->fresh())
        ->provider_cost->toBe('0.000012510')
        ->actual_cost->toBe('0.000015638');
});

test('a failed event costs nothing', function () {
    $transaction = Transaction::factory()->processing()->create();

    sendSignedWebhook($this, webhookEvent('generation.failed', pixelforgeGeneration([
        'id' => $transaction->pixelforge_id,
        'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        'completed_at' => '2026-11-16T10:00:30Z',
    ])))->assertNoContent();

    expect($transaction->fresh())
        ->status->toBe(Transaction::Failed)
        ->actual_cost->toBe('0.000000000')
        ->error_code->toBe('generation_failed');
});

test('a succeeded event returns the hold and charges the actual cost', function () {
    Queue::fake([StoreGenerationImage::class]);
    $user = User::factory()->withBalance('0.41')->create();
    $transaction = Transaction::factory()->for($user)->processing()->create(['held_amount' => '0.09']);

    sendSignedWebhook($this, webhookEvent('generation.succeeded', succeededGeneration($transaction->pixelforge_id)))
        ->assertNoContent();

    // $0.41 + $0.09 hold - $0.070715625 cost.
    expect($user->fresh()->balance)->toBe('0.429284375');
    expect($transaction->fresh()->held_amount)->toBe('0.000000000');
    expect($user->balanceEntries()->sole())
        ->type->toBe(BalanceEntry::Usage)
        ->amount->toBe('-0.070715625')
        ->transaction_id->toBe($transaction->id);
});

test('a failed event returns the whole hold', function () {
    $user = User::factory()->withBalance('0.41')->create();
    $transaction = Transaction::factory()->for($user)->processing()->create(['held_amount' => '0.09']);

    sendSignedWebhook($this, webhookEvent('generation.failed', pixelforgeGeneration([
        'id' => $transaction->pixelforge_id,
        'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
    ])))->assertNoContent();

    expect($user->fresh()->balance)->toBe('0.500000000');
    expect($user->balanceEntries()->exists())->toBeFalse();
});

test('a success after a local submission failure charges the cost without returning the hold again', function () {
    Queue::fake([StoreGenerationImage::class]);
    $user = User::factory()->withBalance('0.50')->create();
    $transaction = Transaction::factory()->for($user)->unsubmitted()->failed()->create(['error_code' => Transaction::SubmissionFailed]);

    sendSignedWebhook($this, webhookEvent('generation.succeeded', succeededGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', [
        'metadata' => ['transaction_id' => $transaction->id],
    ])))->assertNoContent();

    expect($transaction->fresh()->status)->toBe(Transaction::Succeeded);
    expect($user->fresh()->balance)->toBe('0.429284375');
});

test('an event delivered twice is recorded once', function () {
    Queue::fake([StoreGenerationImage::class]);
    $user = User::factory()->withBalance('0.41')->create();
    $transaction = Transaction::factory()->for($user)->processing()->create(['held_amount' => '0.09']);
    $event = webhookEvent('generation.succeeded', succeededGeneration($transaction->pixelforge_id));

    sendSignedWebhook($this, $event)->assertNoContent();
    sendSignedWebhook($this, $event)->assertNoContent();

    expect(WebhookEvent::query()->count())->toBe(1);
    expect(WebhookDelivery::query()->orderBy('id')->pluck('outcome')->all())->toBe([WebhookDelivery::Processed, WebhookDelivery::Duplicate]);
    expect($transaction->fresh()->actual_cost)->toBe('0.070715625');
    expect($user->fresh()->balance)->toBe('0.429284375');
    expect($user->balanceEntries()->count())->toBe(1);
    Queue::assertPushedTimes(StoreGenerationImage::class, 1);
});

test('a late event never moves a succeeded generation', function (string $type, array $generation) {
    $transaction = Transaction::factory()->succeeded()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    sendSignedWebhook($this, webhookEvent($type, $generation, 'evt_late'))->assertNoContent();

    expect($transaction->fresh())
        ->status->toBe(Transaction::Succeeded)
        ->actual_cost->toBe('0.070687500');
})->with([
    'processing retried after success' => ['generation.processing', pixelforgeGeneration(['status' => 'processing'])],
    'failed after success' => ['generation.failed', pixelforgeGeneration(['status' => 'failed', 'error' => ['code' => 'internal_error', 'message' => 'Oops']])],
]);

test('a webhook that arrives before the create call returns finds the row by metadata', function () {
    $transaction = Transaction::factory()->unsubmitted()->create();

    sendSignedWebhook($this, webhookEvent('generation.processing', pixelforgeGeneration([
        'status' => 'processing',
        'metadata' => ['transaction_id' => $transaction->id],
    ])))->assertNoContent();

    expect($transaction->fresh())
        ->pixelforge_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->status->toBe(Transaction::Processing);
});

test('a webhook for an unknown generation asks Pixelforge to retry', function () {
    $response = sendSignedWebhook($this, webhookEvent('generation.processing', pixelforgeGeneration(['status' => 'processing'])));

    $response->assertServiceUnavailable();
    expect(WebhookEvent::query()->exists())->toBeFalse();
    expect(WebhookDelivery::query()->sole())
        ->outcome->toBe(WebhookDelivery::UnknownGeneration)
        ->event_id->toBe('evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A')
        ->type->toBe('generation.processing')
        ->reason->toBe('No transaction for generation gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.')
        ->payload->not->toBeNull();
});

test('a webhook whose generation id does not match the row is ignored', function () {
    $transaction = Transaction::factory()->create(['pixelforge_id' => 'gen_ORIGINAL']);

    sendSignedWebhook($this, webhookEvent('generation.processing', pixelforgeGeneration([
        'id' => 'gen_OTHER',
        'status' => 'processing',
        'metadata' => ['transaction_id' => $transaction->id],
    ])))->assertNoContent();

    expect($transaction->fresh())
        ->status->toBe(Transaction::Queued)
        ->pixelforge_id->toBe('gen_ORIGINAL');
});

test('an image url from another host is not saved', function () {
    Queue::fake([StoreGenerationImage::class]);
    $transaction = Transaction::factory()->processing()->create();

    sendSignedWebhook($this, webhookEvent('generation.succeeded', succeededGeneration($transaction->pixelforge_id, [
        'image_url' => 'https://evil.test/image.png',
    ])))->assertNoContent();

    expect($transaction->fresh())
        ->status->toBe(Transaction::Succeeded)
        ->image_url->toBeNull();
});

test('a payload with an unexpected shape is acknowledged and ignored', function (array $generationOverrides) {
    $transaction = Transaction::factory()->processing()->create();

    $response = sendSignedWebhook($this, webhookEvent(
        'generation.succeeded',
        succeededGeneration($transaction->pixelforge_id, $generationOverrides),
    ));

    $response->assertNoContent();
    expect($transaction->fresh()->status)->toBe(Transaction::Processing);
    expect(WebhookEvent::query()->exists())->toBeFalse();
    expect(WebhookDelivery::query()->sole())
        ->outcome->toBe(WebhookDelivery::InvalidPayload)
        ->event_id->toBe('evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A')
        ->reason->toStartWith('Unexpected fields: data.object.')
        ->payload->not->toBeNull();
})->with([
    'rate is not a decimal' => [['rates' => ['input_text_tokens' => '5e3', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50']]],
    'token count is negative' => [['usage' => ['input_text_tokens' => -1, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000]]],
    'usage is missing' => [['usage' => null]],
    'unknown status' => [['status' => 'done']],
]);

test('a ping is acknowledged and recorded as ignored', function () {
    sendSignedWebhook($this, ['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'data' => []])
        ->assertNoContent();

    expect(WebhookDelivery::query()->sole())
        ->outcome->toBe(WebhookDelivery::Ignored)
        ->event_id->toBe('evt_ping')
        ->type->toBe('ping');
});

test('a signature from any of several v1 values is accepted', function () {
    $transaction = Transaction::factory()->create();
    $body = json_encode(webhookEvent('generation.processing', pixelforgeGeneration(['id' => $transaction->pixelforge_id, 'status' => 'processing'])));
    $timestamp = now()->getTimestamp();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test');

    sendWebhook($this, $body, "t={$timestamp},v1=".str_repeat('0', 64).",v1={$signature}")->assertNoContent();

    expect($transaction->fresh()->status)->toBe(Transaction::Processing);
});

test('rejects a webhook that is not signed correctly', function (Closure $sendRequest, string $reason) {
    $transaction = Transaction::factory()->create();
    $event = webhookEvent('generation.processing', pixelforgeGeneration(['id' => $transaction->pixelforge_id, 'status' => 'processing']));

    $sendRequest($this, $event)->assertUnauthorized();

    expect($transaction->fresh()->status)->toBe(Transaction::Queued);
    expect(WebhookEvent::query()->exists())->toBeFalse();
    expect(WebhookDelivery::query()->sole())
        ->outcome->toBe(WebhookDelivery::InvalidSignature)
        ->reason->toBe($reason)
        ->ip->toBe('127.0.0.1')
        ->event_id->toBeNull()
        ->payload->toBeNull();
})->with([
    'wrong secret' => [fn (TestCase $test, array $event) => sendSignedWebhook($test, $event, secret: 'whsec_wrong'), 'signature mismatch'],
    'timestamp older than five minutes' => [fn (TestCase $test, array $event) => sendSignedWebhook($test, $event, now()->subSeconds(301)->getTimestamp()), 'timestamp outside tolerance'],
    'timestamp more than five minutes ahead' => [fn (TestCase $test, array $event) => sendSignedWebhook($test, $event, now()->addSeconds(301)->getTimestamp()), 'timestamp outside tolerance'],
    'missing header' => [fn (TestCase $test, array $event) => sendWebhook($test, json_encode($event), null), 'missing or malformed signature header'],
    'malformed header' => [fn (TestCase $test, array $event) => sendWebhook($test, json_encode($event), 'not-a-signature'), 'missing or malformed signature header'],
    'tampered body' => [function (TestCase $test, array $event) {
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.".json_encode($event), 'whsec_test');
        $event['data']['object']['status'] = 'failed';

        return sendWebhook($test, json_encode($event), "t={$timestamp},v1={$signature}");
    }, 'signature mismatch'],
]);

test('refuses every webhook when the secret is not configured', function () {
    config(['services.pixelforge.webhook_secret' => '']);
    $transaction = Transaction::factory()->create();

    $response = sendSignedWebhook($this, webhookEvent('generation.processing', pixelforgeGeneration(['id' => $transaction->pixelforge_id, 'status' => 'processing'])), secret: '');

    $response->assertInternalServerError();
    expect($transaction->fresh()->status)->toBe(Transaction::Queued);
});

test('a webhook for a deleted generation is acknowledged without queuing an image copy', function () {
    Queue::fake([StoreGenerationImage::class]);
    $transaction = Transaction::factory()->unsubmitted()->failed()->create(['error_code' => Transaction::SubmissionFailed]);
    $transaction->delete();

    sendSignedWebhook($this, webhookEvent('generation.succeeded', succeededGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', [
        'metadata' => ['transaction_id' => $transaction->id],
    ])))->assertNoContent();

    expect(Transaction::withTrashed()->find($transaction->id))
        ->status->toBe(Transaction::Succeeded)
        ->actual_cost->toBe('0.070715625');
    Queue::assertNotPushed(StoreGenerationImage::class);
});
