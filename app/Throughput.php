<?php

namespace App;

use App\Jobs\DispatchThroughput;
use Illuminate\Support\Facades\Queue;
use Throwable;

class Throughput
{
    public const TTL = 3600;

    public const RELEASE = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0";

    public function __construct(private Telemetry $telemetry) {}

    public function key(string $run): string
    {
        return $this->telemetry->key('throughput:'.$run);
    }

    public static function deadline(int $count): float
    {
        return min(300, 30 + $count * 0.05);
    }

    public function start(array $data): array
    {
        $count = array_key_exists('count', $data) ? $data['count'] : 1000;
        if (! is_int($count) || $count < 1 || $count > 10000) {
            return [400, ['error' => 'count must be an integer from 1 to 10000']];
        }
        $store = $this->telemetry->store();
        $run = bin2hex(random_bytes(16));
        if (! $store->set($this->key('active'), $run, 'EX', (int) self::deadline($count) + 60, 'NX')) {
            return [409, ['error' => 'a throughput run is already active', 'run' => $store->get($this->key('active'))]];
        }
        $store->pipeline(function ($pipe) use ($run, $count): void {
            $pipe->hmset($this->key($run), ['count' => $count, 'started_at' => microtime(true)]);
            $pipe->expire($this->key($run), self::TTL);
        });
        try {
            Queue::push(new DispatchThroughput($run, $count));
        } catch (Throwable $exception) {
            $this->fail($run, $exception);
        }

        return [202, ['run' => $run]];
    }

    public function fail(string $run, Throwable $exception): void
    {
        $this->telemetry->store()->pipeline(function ($pipe) use ($run, $exception): void {
            $pipe->hset($this->key($run), 'error', mb_substr($exception->getMessage(), 0, 200));
            $pipe->expire($this->key($run), self::TTL, 'NX');
        });
    }

    public function tick(string $run, string $uuid, float $queuedAt): void
    {
        $started = microtime(true);
        $this->telemetry->store()->pipeline(function ($pipe) use ($run, $uuid, $queuedAt, $started): void {
            $pipe->hincrby($this->key($run), 'd:'.$uuid, 1);
            $pipe->hsetnx($this->key($run), 'w:'.$uuid, $started - $queuedAt);
            $pipe->hsetnx($this->key($run), 'f:'.$uuid, microtime(true));
            $pipe->expire($this->key($run), self::TTL, 'NX');
        });
    }

    public function status(string $run): array
    {
        $store = $this->telemetry->store();
        $raw = $store->hgetall($this->key($run));
        [$code, $body] = self::calculate($raw);
        if ($code !== 200 || isset($raw['result']) || $body['state'] === 'running') {
            return [$code, $body];
        }
        [$saved] = $store->pipeline(function ($pipe) use ($run, $body): void {
            $pipe->hsetnx($this->key($run), 'result', json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $pipe->expire($this->key($run), self::TTL, 'NX');
        });
        if (! $saved) {
            return [200, json_decode($store->hget($this->key($run), 'result'), true, flags: JSON_THROW_ON_ERROR)];
        }
        $store->eval(self::RELEASE, 1, $this->key('active'), $run);

        return [200, $body];
    }

    public static function calculate(array $raw, ?float $now = null): array
    {
        if (isset($raw['result'])) {
            return [200, json_decode($raw['result'], true, flags: JSON_THROW_ON_ERROR)];
        }
        if (! isset($raw['count'])) {
            return [404, ['error' => 'unknown run']];
        }
        $count = (int) $raw['count'];
        $started = (float) $raw['started_at'];
        $waits = $finishes = [];
        $duplicates = 0;
        foreach ($raw as $key => $value) {
            if (str_starts_with($key, 'w:')) {
                $waits[] = (float) $value;
            } elseif (str_starts_with($key, 'f:')) {
                $finishes[] = (float) $value;
            } elseif (str_starts_with($key, 'd:')) {
                $duplicates += (int) $value - 1;
            }
        }
        sort($waits, SORT_NUMERIC);
        $processed = count($finishes);
        $span = $finishes ? max($finishes) - $started : 0;
        $rate = $span > 0 ? round($processed / $span, 1, PHP_ROUND_HALF_EVEN) : null;
        $percentile = fn ($p) => $waits ? round($waits[max(0, (int) ceil($p / 100 * count($waits)) - 1)] * 1000, 1, PHP_ROUND_HALF_EVEN) : null;
        $wait = ['p50' => $percentile(50), 'p95' => $percentile(95)];
        $failed = array_key_exists('error', $raw);
        $done = $failed || $processed >= $count || ($now ?? microtime(true)) - $started > self::deadline($count);
        $verdict = null;
        if ($done) {
            $verdict = $failed || $processed < $count || $duplicates ? 'fail'
                : (($rate ?? 0) < 50 || ($wait['p95'] ?? 0) > 15000 ? 'warn' : 'pass');
        }
        $body = ['state' => $done ? ($failed ? 'failed' : 'done') : 'running', 'count' => $count,
            'processed' => $processed, 'jobs_per_s' => $rate, 'wait_ms' => $wait,
            'lost' => $done ? $count - $processed : null, 'duplicates' => $duplicates, 'verdict' => $verdict];
        if ($failed) {
            $body['error'] = $raw['error'];
        }

        return [200, $body];
    }
}
