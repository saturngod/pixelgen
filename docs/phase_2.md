# Phase 2: Generate, history, webhooks and cost

## Goal

A logged-in user can generate an image. We save every generation in the `transactions` table with the estimated cost and, when it finishes, the actual cost. Pixelforge tells us the result by webhook. The user can wait on the page, or close it and come back later.

## Facts from the Pixelforge docs

- Base URL: `https://pixelforge.promptedit.dev/v1`. Auth is a Bearer API key (`PIXELFORGE_API`).
- `POST /generations` returns `202` with status `queued`. It accepts an `Idempotency-Key` header and a `metadata` object (string values).
- Status flow: `queued` -> `processing` -> `succeeded` or `failed`. The last two are final.
- There is no progress or percentage field.
- The API returns token counts, never a price. Cost = `tokens x rate / 1,000,000`. Each generation carries the `rates` it was created with.
- Failed generations are not billed.
- Image URLs expire 24 hours after completion.
- Limits are per account, shared by all our users: 60 requests/min, 3 active generations, 500 per day. Errors `429` and `503` send `Retry-After`.
- Webhooks are at least once, can be duplicated, and can arrive out of order. A webhook can arrive before the create call returns. Delivery is not guaranteed.

## Webhook route

- Route: `POST /webhooks/pixelforge` (named `webhooks.pixelforge`). Pixelforge calls it.
- Public: no login. Exclude only this exact path from CSRF.
- Throttle with a high limit (for example 300/min). Behind the tunnel every request comes from the same IP, so a low limit would block Pixelforge retries. The signature is the real protection.
- Secret: `WEBHOOK_SECRET` in `.env`, read with `config('services.pixelforge.webhook_secret')`. Never call `env()` outside `config/`.

### Checks, in this order

1. If the secret is empty, return `500` and log it. Never accept unsigned events.
2. The `Pixelforge-Signature` header must look like `t=<unix time>,v1=<hex>`. If not, return `401`.
3. The difference between `t` and now must be 300 seconds or less, in either direction (old and future timestamps are both rejected). If not, return `401`.
4. Compute `HMAC-SHA256("<t>.<raw body>", secret)` on the raw body (`$request->getContent()`), before JSON decoding. Compare with `hash_equals`. If the header has more than one `v1=` value (secret rotation), accept if any one matches. If none match, return `401`.
5. Only now read the JSON.
6. Validate the payload shape before using it: `id` starts with `evt_`, `type` is known, `data.object.id` starts with `gen_`, `status` is a known value, token counts are integers >= 0, rates are numeric strings. If the shape is wrong, log it and return `200` (a retry will not fix it).

### Handling an event

- `ping`: return `200`.
- Find the row by `data.object.metadata.transaction_id`, then by the Pixelforge id. If there is no row yet, return `503` so Pixelforge retries.
- If the row already has a `pixelforge_id` and it is not `data.object.id`, ignore the event, log it, and return `200`.
- Do all of the following inside one `DB::transaction()`, so the event is marked as seen only if the change is saved:
  1. Lock the row (`lockForUpdate`).
  2. Insert the `evt_` id into `webhook_events` (unique). If it already exists, stop and return `200`.
  3. Never move a row out of `succeeded` or `failed`. A late `processing` event is ignored.
  4. `generation.processing`: set status `processing`.
  5. `generation.succeeded`: save the usage, rates, image URL and expiry. Calculate and save the actual cost once. After the database transaction commits, dispatch `StoreGenerationImage` (see "Image storage"). Do not download inside the webhook: Pixelforge needs a `2xx` within 10 seconds.
  6. `generation.failed`: set status `failed`, save the error, set actual cost to `0`.
- Return `200` for anything handled or ignored.
- Never log the secret, the signature header or the raw body.

## Database

### `transactions` (one row per generation)

| Column | Notes |
|---|---|
| `id` | ULID, so ids cannot be guessed. Also used as the `Idempotency-Key`. |
| `user_id` | Owner. Index `user_id`. |
| `pixelforge_id` | `gen_...`. Nullable until create returns. Unique. |
| `prompt`, `size`, `quality`, `reference_images` (json) | What was asked. |
| `status` | `queued`, `processing`, `succeeded`, `failed`. |
| `estimated_min_price`, `estimated_max_price` | Decimal strings, from `PricingService`. |
| `usage` (json), `rates` (json) | Saved on success. |
| `provider_cost` | Pixelforge cost, 9 decimal places. |
| `actual_cost` | What the user pays (provider cost plus markup), 9 decimal places. `0` for failed. |
| `image_url`, `image_url_expires_at` | Temporary Pixelforge URL. Used only to download the image. |
| `image_path` | Our S3 key, for example `generations/{user_id}/{transaction_ulid}.png`. Null until stored. |
| `image_stored_at` | When the copy to S3 finished. |
| `error_code`, `error_message` | Failed only. `error_message` is for logs and admins, not shown to users. |
| `last_synced_at` | Last time we asked Pixelforge for this row. Limits polling calls. |
| `completed_at`, `timestamps` | |

Model rule: `status`, `actual_cost`, `provider_cost`, `user_id` and the other system fields are never filled from request input. Set them in code.

### `webhook_events`

`event_id` (unique), `type`, `transaction_id` (nullable), `created_at`.

## Image storage (S3)

Pixelforge image URLs expire 24 hours after completion, so we copy each image to our own S3 bucket. S3 is already set up in `.env` (`AWS_*` keys) and `league/flysystem-aws-s3-v3` is installed.

- **Bucket is private.** Never make objects public. Users see the image through a short-lived signed link: `Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(30))`, made by the controller only for the row's owner.
- **Queued job `StoreGenerationImage`** (in `app/Jobs`, new folder approved; create it with `php artisan make:job`), dispatched after a row becomes `succeeded` (by webhook or by sync). It:
  1. Stops if `image_path` is already set (safe to run twice).
  2. Checks the URL starts with `https://pixelforge.promptedit.dev/`. Never download any other host (no SSRF).
  3. Downloads with `Http::timeout(30)`, no redirects followed.
  4. Checks the response: status `200`, `Content-Type: image/png`, the body starts with the PNG signature, and the size is under 20 MB.
  5. Uploads to `generations/{user_id}/{transaction_ulid}.png` with `Storage::disk('s3')->put(...)`. The S3 disk has `throw => false`, so check the return value and throw if it is `false`, so the job retries.
  6. Saves `image_path` and `image_stored_at`.
- **Retries:** 5 tries with backoff (for example 10s, 30s, 2m, 10m, 30m). If the Pixelforge URL has expired (`403` or past `image_url_expires_at`), call `GET /generations/{id}` once for a fresh URL. After the last try, log it and leave `image_path` null. The row stays `succeeded` and keeps its cost.
- **Safety net:** `transactions:sync` also dispatches the job for `succeeded` rows with no `image_path` that are less than 24 hours old.
- **Queue worker:** `QUEUE_CONNECTION` is the database queue, so `php artisan queue:work` (or `composer run dev`) must be running, or images are never copied.
- The image file is not money and not state, so a failed copy never changes `status` or `actual_cost`.

## Cost

- Money is a decimal string. Use `bcmath` only, never floats.
- `provider_cost = (text x rate + image x rate + output x rate + reasoning x rate) / 1,000,000`, using the `rates` saved with the generation.
- `actual_cost = provider_cost x (1 + MARKUP_MARGIN / 100)`, kept at 9 decimal places. The UI shows about 4 decimals.
- The estimate keeps the current rule (round up to the next cent).
- The cost maths is `PricingService::actualCost()` (it already wraps Pixelforge pricing).
- The generations API (create, fetch) is wrapped by `PixelforgeService`.

## Backend steps

1. Migrations, `Transaction` model (ULID), factory. Add a `WebhookEvent` model for the dedupe table.
2. `GenerationController@store`, with a `GenerationStoreRequest`. Route `generations.store`, login required.
   - Per-user throttle (for example 10/min).
   - Each user may have only 1 active generation (`queued` or `processing`). Otherwise return `429` with a clear message. This stops one user from using all 3 account slots.
   - Save the row first with status `queued`.
   - Call Pixelforge with `Idempotency-Key = transaction ULID` and `metadata.transaction_id`.
   - Save `pixelforge_id`. On `400`, `429` or `503`, mark the row `failed` with cost `0` and return our own clear message. Log the Pixelforge error, do not show it raw.
3. `GenerationController@index` (History page) and `show` (JSON for polling). Routes `generations.index` and `generations.show`.
   - Always load rows through the logged-in user (`$request->user()->transactions()`) or a policy. Someone else's row returns `404`.
   - Throttle `show` (for example 60/min per user).
4. `PixelforgeWebhookController@store`, `PixelforgeWebhookRequest` for the payload shape, and the `VerifyPixelforgeSignature` middleware.
5. `PixelforgeService` for create and fetch calls. `PricingService::actualCost()` for the cost.
6. Safety net: scheduled command `transactions:sync`, every minute. For rows still `queued` or `processing` after 2 minutes, call `GET /generations/{id}` and apply the same state rules and the same database transaction as the webhook. `generations.show` does the same for an open page when the row is stuck.
   - Call Pixelforge for a row at most once every 30 seconds (use `last_synced_at`). Normal polls only read our database. This protects the shared 60/min limit.
7. Save `image_url` only if it starts with `https://pixelforge.promptedit.dev/`. Otherwise do not save it, and log it.
8. `StoreGenerationImage` job, as described in "Image storage". `show` and `index` return a signed S3 link (`temporaryUrl`, 30 minutes) when `image_path` is set, and never the raw S3 key or bucket name.

## Frontend steps

- Generate button: if the user is not logged in, go to login.
- Disable the button while a submit is running, so a double click cannot create two paid generations.
- After submit (`useForm`), go to the History page.
- The History page polls once for all unfinished cards with `generations.statuses` (`GET /generations/status?ids[]=...`, up to 24 ids, throttled 60/min). Cards make no polling requests themselves.
  - Every 3 seconds for the first 30 seconds, then every 10 seconds.
  - Polling pauses while the tab is hidden and catches up at once when the tab is visible again.
  - Finished cards are never polled.
- `generations.show` is used only to get a fresh signed image link when an image fails to load.
- Progress bar is an estimate from status and time, capped at 95% until success, because the API has no real progress. Jump to 100% on success.
- History page (`resources/js/pages`): prompt, status, estimated cost, actual cost, image.
- Image states: `succeeded` with no `image_path` yet shows "Saving image...". Polling continues until `image_path` is set, or 2 minutes pass. If the signed link fails to load (it lasts 30 minutes), reload the row to get a fresh link. If the copy failed for good, show "Image unavailable".
- Show prompts and errors with normal `{{ }}` text binding. Never use `v-html`.
- Use route functions from `@/routes/...`. Do not edit generated Wayfinder files.

## Security summary

| Risk | Fix |
|---|---|
| Fake webhook | HMAC-SHA256 on the raw body, `hash_equals`, empty secret refused |
| Replayed webhook | 300 second window in both directions, plus event id dedupe |
| Event marked seen but change lost | Dedupe insert and row update in one `DB::transaction()` with a row lock |
| Duplicate cost | Unique `event_id`, final states never change, cost saved once |
| Bad payload | Validate shape before bcmath and before saving |
| Wrong generation | Check `data.object.id` matches the saved `pixelforge_id` |
| One user blocks everyone | 1 active generation per user, per-user throttle, button disabled while submitting |
| Polling uses up the Pixelforge limit | At most one Pixelforge call per row every 30 seconds |
| Guessing other users' rows | ULID ids and queries scoped to the logged-in user |
| Unsafe image URL | Only accept `https://pixelforge.promptedit.dev/` URLs |
| Downloading from a bad host (SSRF) | Job downloads only from the Pixelforge host, no redirects |
| Bad or huge file in S3 | Check `200`, `image/png`, PNG signature, under 20 MB |
| Other users' images | Private bucket, signed links (30 minutes) made only for the owner |
| XSS | No `v-html`, normal text binding |
| Mass assignment | System fields never come from request input |
| Leaking details | Own error messages to users, no secret or raw body in logs |
| Local app exposed on `pixelgen.saturngod.net` | Ingress passes only `/webhooks/pixelforge`, everything else returns `404` |

Not a risk for us: reference image URLs are fetched by Pixelforge, not by our server, so there is no SSRF on our side.

## Tests (Pest feature tests)

Use `Http::preventStrayRequests()` with exact endpoints.

- Create: success, validation errors, guest is redirected, Pixelforge `429` and `503` give a clean failure, a second active generation for the same user is refused.
- Webhook signature: good signature, wrong secret, tampered body, old timestamp, future timestamp, missing header, empty secret, `ping`, multiple `v1` values with one valid.
- Webhook payload: bad shape is ignored with `200`, mismatched `pixelforge_id` is ignored, image URL from another host is not saved.
- State rules: duplicate event is recorded once, `processing` after `succeeded` is ignored, success never overwritten by failure, early webhook before `pixelforge_id` is saved returns `503`.
- Cost: exact bcmath result for a known token set, failed generation costs `0`, actual cost saved only once.
- Sync command: updates a stuck row, skips final rows, skips rows synced less than 30 seconds ago, dispatches the image job for `succeeded` rows with no `image_path`.
- Image job (use `Storage::fake('s3')` and `Http::fake`): stores the PNG at the right path, does nothing if `image_path` is set, refuses another host, refuses a non-PNG or too-large body, gets a fresh URL when the old one returns `403`.
- Webhook success dispatches the image job once (use `Queue::fake()`), and a duplicate event does not dispatch it again.
- Show endpoint returns a signed link only when `image_path` is set.
- Show and index: owner only, another user's row returns `404`.

## Order of work

1. Migrations, models, factory.
2. Webhook route, signature check, payload checks, state rules, and their tests.
3. Cost maths and its tests.
4. Create, show and index endpoints, with limits and ownership, and their tests.
5. Image job (S3 copy) and its tests.
6. Sync command and test.
7. Frontend: generate flow, progress bar, History page, image states.
8. Run `vendor/bin/pint --format agent --dirty`, `php artisan test --compact`, `npm run build` and `npx vue-tsc --noEmit`.

## Cloudflare tunnel

Public address: `https://pixelgen.saturngod.net` (a named tunnel, so the address does not change on restart).

Webhook URL for the Pixelforge dashboard: `https://pixelgen.saturngod.net/webhooks/pixelforge`. Set it once.

**Decision: webhook only.** The tunnel passes only `/webhooks/pixelforge` to the app and returns `404` for everything else. This matters because a fixed domain is easy to find, and with `APP_DEBUG=true` error pages can show config values.

- We keep using `http://pixelgen.test` locally.
- No app changes for the tunnel: `APP_URL` stays local, no trusted proxies, no secure-cookie change.
- Check after setup: `https://pixelgen.saturngod.net/` returns `404`, and `https://pixelgen.saturngod.net/webhooks/pixelforge` reaches the app (an unsigned `POST` returns `401`).

If we later make the whole app public, we must set `APP_URL=https://pixelgen.saturngod.net`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, and trust the tunnel as a proxy in `bootstrap/app.php`.

Ingress (`~/.cloudflared/config.yml`):

```yaml
ingress:
  - hostname: pixelgen.saturngod.net
    path: ^/webhooks/pixelforge$
    service: http://pixelgen.test
    originRequest:
      httpHostHeader: pixelgen.test
  - service: http_status:404
```

`httpHostHeader` is needed because Herd picks the site by the host name.

### Manual check

1. Start the tunnel (`cloudflared tunnel run <tunnel-name>`) and the queue worker (`php artisan queue:work`).
2. Set `https://pixelgen.saturngod.net/webhooks/pixelforge` in the Pixelforge dashboard.
3. Send `ping` from the dashboard. Expect `200`.
4. Generate with these prompt keywords: `[succeed]` for a normal run, `[fail]` for a failure (cost `0`), `[duplicate]` to test duplicate events, `[slow]` to test closing and reopening the page.

## Deleting an image

- `DELETE /generations/{transaction}` (`generations.destroy`) deletes one of the user's own finished generations. Another user's id gets a 404.
- Only `succeeded` and `failed` rows can be deleted. A queued or processing row still holds money from the balance, so it must finish first.
- The row is soft deleted (`deleted_at`), so the billing history keeps the charge and its description. The image fields are cleared.
- The S3 file is deleted inside the same database transaction. If S3 fails, nothing changes and the user can try again.
- If the image copy job is still running, it saves with a query that skips deleted rows. When nothing is saved, the job deletes the file it just uploaded.
- Webhooks still find deleted rows, so Pixelforge is not asked to retry them. A deleted row never queues an image copy.
