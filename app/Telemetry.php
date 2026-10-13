<?php

namespace App;

use App\Jobs\DemoJob;
use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

class Telemetry
{
    public const CHECK_KINDS = ['quick', 'async', 'delayed', 'flaky', 'failing', 'timeout', 'burst'];

    public const CHECK_DEADLINE = 240;

    public function store(): Connection
    {
        return Redis::connection();
    }

    public function key(string $suffix): string
    {
        return config('demo.key_prefix').$suffix;
    }

    public function record(string $event, array $fields = []): void
    {
        $entry = json_encode(['event' => $event, 'at' => microtime(true), ...$fields], JSON_THROW_ON_ERROR);
        $this->store()->pipeline(function ($pipe) use ($event, $fields, $entry): void {
            $pipe->lpush($this->key('events'), $entry);
            $pipe->ltrim($this->key('events'), 0, 199);
            $pipe->hincrby($this->key('stats'), $event, 1);
            if (isset($fields['uuid'])) {
                $pipe->rpush($this->key('job:'.$fields['uuid']), $entry);
                $pipe->expire($this->key('job:'.$fields['uuid']), 86400);
            }
        });
    }

    public function queued(JobQueued $event): void
    {
        if (! $event->job instanceof DemoJob) {
            return;
        }
        $job = $event->job;
        $job->uuid = $event->payload()['uuid'];
        $this->record('queued', ['at' => $job->queuedAt, 'job' => $job->displayName(), 'uuid' => $job->uuid, 'delay' => $job->kind === 'delayed' ? 5 : 0]);
    }

    public function tracked(DemoJob $job, Closure $handle): void
    {
        $base = ['job' => $job->displayName(), 'uuid' => $job->job->uuid(), 'attempt' => $job->attempts(),
            'worker' => (config('demo.worker_label') ?: gethostname()).':'.getmypid(), 'python' => PHP_VERSION];
        $this->record('started', $base);
        $start = hrtime(true);
        try {
            $handle();
        } catch (Throwable $exception) {
            $this->record($job->attempts() >= $job->tries ? 'failed' : 'released', [...$base,
                'ms' => (int) round((hrtime(true) - $start) / 1e6), 'error' => mb_substr($exception->getMessage(), 0, 200)]);
            throw $exception;
        }
        $this->record('processed', [...$base, 'ms' => (int) round((hrtime(true) - $start) / 1e6)]);
    }

    public function runCheck(): bool
    {
        if (! $this->store()->set($this->key('check-lock'), '1', 'EX', self::CHECK_DEADLINE, 'NX')) {
            return false;
        }
        $cases = [];
        foreach (self::CHECK_KINDS as $kind) {
            $cases[$kind] = [];
            for ($i = 0; $i < ($kind === 'burst' ? 20 : 1); $i++) {
                $job = new DemoJob($kind);
                if ($kind === 'delayed') {
                    Queue::later(now()->setTimestamp((int) ceil($job->queuedAt + 5)), $job);
                } else {
                    Queue::push($job);
                }
                $cases[$kind][] = $job->uuid;
            }
        }
        $this->store()->set($this->key('check'), json_encode(['at' => microtime(true), 'cases' => $cases], JSON_THROW_ON_ERROR));

        return true;
    }

    public function snapshot(): array
    {
        [$counts, $rawEvents] = $this->store()->pipeline(function ($pipe): void {
            $pipe->hgetall($this->key('stats'));
            $pipe->lrange($this->key('events'), 0, 99);
        });
        $events = array_map(fn ($e) => json_decode($e, true, flags: JSON_THROW_ON_ERROR), $rawEvents);
        usort($events, fn ($a, $b) => $b['at'] <=> $a['at']);

        return ['framework' => 'Laravel', 'python' => PHP_VERSION, 'version' => Application::VERSION,
            'mode' => 'managed', 'queue' => null, 'depth' => null, 'counts' => (object) array_map('intval', $counts),
            'events' => $events, 'check' => $this->evaluate()];
    }

    public function reset(): void
    {
        $this->store()->del($this->key('events'), $this->key('stats'), $this->key('check'));
    }

    public function evaluate(): ?array
    {
        $raw = $this->store()->get($this->key('check'));
        if (! $raw) {
            return null;
        }
        $run = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $histories = $this->store()->pipeline(function ($pipe) use ($run): void {
            foreach ($run['cases'] as $uuids) {
                foreach ($uuids as $uuid) {
                    $pipe->lrange($this->key('job:'.$uuid), 0, -1);
                }
            }
        });

        return CheckEvaluator::evaluate($run, array_map(fn ($h) => array_map(fn ($e) => json_decode($e, true, flags: JSON_THROW_ON_ERROR), $h), $histories));
    }
}
