# laravel-cloud-queues-twin

A Laravel 13 baseline for [python-cloud-queues](https://github.com/DGarbs51/python-cloud-queues)
on [Laravel Cloud](https://cloud.laravel.com). Open the page, press **Run queue checks**,
and read each result. Linear: SE-484; Python parity work: SE-486.

| Case | Workload |
|---|---|
| quick | Sleep 50–300 ms, one attempt |
| async | Another ordinary PHP job, sleep 50–300 ms |
| delayed | A quick job scheduled at least five seconds ahead |
| flaky | Three tries, backoff `[2]`; attempt one throws, attempt two succeeds |
| failing | Two tries, backoff `[1]`; always throws `this job always fails` |
| timeout | Two tries, three-second timeout, ten-second sleep; worker exits and restarts |
| burst | Twenty quick jobs, each processed exactly once |
| throughput | 1–10,000 no-op jobs; default 1,000, measured from run start |

## Run locally

Requires Herd PHP 8.5, Composer, and Herd Valkey at `127.0.0.1:6379`.

```sh
composer install
cp .env.example .env
php artisan key:generate
# Set DEMO_KEY_PREFIX=lcq-twin-local: in .env to isolate local telemetry.
php artisan serve
# Second terminal: restart supervision is needed for the timeout case.
while true; do php artisan queue:work redis --queue=laravel-twin --sleep=1 --tries=1; done
```

No database or frontend build is needed. Sessions use `array`; cache uses Redis.
Local failed jobs use the null provider. On Cloud, Laravel's native managed-queue
failed-job provider owns storage, dashboard display and retry.

```sh
php artisan test
vendor/bin/pint --format agent
```

The tests cover routes, input validation, job sleep/tries/backoff/timeout, all queue
verdicts, throughput math, and real Valkey dispatch/UUID histories/cooldown/deduplication/
frozen results. The Redis integration test uses a random namespace and cleans only its own keys.

## Deploy to Laravel Cloud

Create public repo `DGarbs51/laravel-cloud-queues-twin`, branch `main`, and a separate
Cloud application `laravel-cloud-queues-twin` in Laravel GTM, `us-east-2`.

- Environment `main`, PHP 8.5, Laravel 13.35 or newer.
- App `pro.g-2vcpu-4gb`; custom autoscaling 1–6, CPU 60%, memory 70%; scale-to-zero off.
- Managed queue `default`: Flex `mq.flex.512mb`, 0–100 workers, Cloud's default Laravel worker command.
- Attach Laravel Valkey with sleep off. Cloud injects `REDIS_URL` and `REDIS_*`.
- Set `QUEUE_CONNECTION=cloud`, `SESSION_DRIVER=array`, `CACHE_STORE=redis`,
  `QUEUE_FAILED_DRIVER=null`, `REDIS_PREFIX=` and `DEMO_KEY_PREFIX=lcq-demo:`.
- Build: `composer install --no-dev --prefer-dist --optimize-autoloader && php artisan optimize`.
  Deploy command empty. Do not run migrations.

Laravel consumes `LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG` directly. The AWS SDK is required
by the framework's underlying SQS transport. Boost is a development dependency required
by the scaffold's agent instructions; it is excluded from production installs.
Never store Cloud API credentials in the repository.

The app has no login. Protect it at the network layer. JSON POSTs force a CORS preflight;
the app grants no cross-origin access.

## HTTP contract

| Route | Result |
|---|---|
| `GET /` | Queue check button and stats readout |
| `GET /api/ping` | `{ok, deploy, pod, pid}` |
| `POST /api/check` | 200 `{ok: true}`; 409 during the 240-second cooldown |
| `GET /api/stats` | `{framework, python, version, mode, queue, depth, counts, events, check}` |
| `POST /api/reset` | 200 `{ok: true}`; deletes aggregate events, counts and check only |
| `POST /api/throughput` | 202 `{run}`; `{count: N}`, default 1,000; 409 if a run is active |
| `GET /api/throughput/{run}` | `{state, count, processed, jobs_per_s, wait_ms: {p50, p95}, lost, duplicates, verdict}`; unknown run 404 |

POSTs require `Content-Type: application/json` and an object (empty body becomes `{}`).
Incorrect content type is 415; malformed or non-object JSON and bodies over 64 KiB are 400.
Invalid counts (including booleans, floats, strings and null) are 400. Service failures
return 503 `{error: "service unavailable"}`. Valid `X-Request-ID` values are preserved.

Redis uses `DEMO_KEY_PREFIX`, default `lcq-demo:`, without Laravel's automatic Redis
prefix: `events`, `stats`, `check`, `check-lock`, `job:<uuid>`, `throughput:active` and
`throughput:<run>`. Histories live 86,400 seconds; recent events keep 200 and stats
returns the newest 100 sorted by epoch timestamp. Events match Python: `event`, `at`,
`job`, `uuid`, `delay`; deliveries add `attempt`, `worker`, `python`, then `ms` and `error`
where applicable. Timeout kills never report processed. Reset deliberately leaves
cooldowns, per-job histories and throughput runs intact.

Throughput hashes contain `count`, `started_at`, `d:<uuid>`, `w:<uuid>` (seconds),
`f:<uuid>` (epoch seconds), optional `error`, and frozen `result`. `HSETNX` prevents
redelivery overwrites; `d:` counts deliveries. Deadline is `min(300, 30 + count * 0.05)`
seconds; TTL is 3,600 seconds. Lost or duplicated jobs fail. Otherwise below 50 jobs/s
or p95 above 15,000 ms warns. Results freeze atomically and lock release checks its owner.

## Differences from Python

- `framework` is `Laravel`, `version` is Laravel's version, and **`python` contains
  the PHP version** in web and worker records. Worker detail strings retain the word
  `Python` to match the original evaluator exactly.
- `demo.async` is an ordinary PHP job.
- Native delay scheduling uses whole seconds. The five-second absolute deadline
  rounds upward to prevent early delivery (up to one extra second). Logical delay stays five.
- A managed `throughput.dispatch` launcher replaces Python's background dispatch thread.
  The launcher is excluded from demo telemetry and tick count; its wait and dispatch
  time are included in run duration. POST remains fast.
- Native payload UUIDs include hyphens; run IDs remain 32 lowercase hexadecimal characters.
- Stats follows `Telemetry.snapshot()` without ASGI server/loop-lag fields. Web, Logging,
  Runtime and Services groups are outside scope. `cloud_suite.run_env` requires those
  groups, so reuse its existing `call` helper for queue/throughput verification instead.

## Local verification

Laravel 13.35.0, PHP 8.5.10: 14 tests, 440 assertions, Pint clean. Herd Valkey smoke
with `php artisan queue:work redis` and restart supervision: all seven cases pass.
A 1,000-job run passes at 306.1 jobs/s, p95 838.9 ms, zero lost or duplicated jobs.
The unchanged Python HTTP helper works against the twin, and the Python check and
throughput evaluators produce identical results from its Redis records.
