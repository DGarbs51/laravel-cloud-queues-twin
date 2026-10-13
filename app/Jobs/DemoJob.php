<?php

namespace App\Jobs;

use App\Telemetry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use RuntimeException;

class DemoJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public array $backoff;

    public int $timeout;

    public ?string $uuid = null;

    public float $queuedAt;

    public function __construct(public string $kind)
    {
        $this->tries = match ($kind) {
            'flaky' => 3, 'failing', 'timeout' => 2, default => 1
        };
        $this->backoff = match ($kind) {
            'flaky' => [2], 'failing' => [1], default => [0]
        };
        $this->timeout = $kind === 'timeout' ? 3 : 60;
        $this->queuedAt = microtime(true);
    }

    public function displayName(): string
    {
        return 'demo.'.match ($this->kind) {
            'delayed', 'burst' => 'quick', default => $this->kind
        };
    }

    public function handle(Telemetry $telemetry): void
    {
        $telemetry->tracked($this, function (): void {
            match ($this->kind) {
                'quick', 'async', 'delayed', 'burst' => Sleep::usleep(random_int(50_000, 300_000)),
                'slow' => Sleep::sleep(3),
                'timeout' => Sleep::sleep(10),
                'flaky' => $this->attempts() === 1 ? throw new RuntimeException('flaky job fails on its first attempt') : null,
                'failing' => throw new RuntimeException('this job always fails'),
                default => throw new RuntimeException('unknown demo job'),
            };
        });
    }
}
