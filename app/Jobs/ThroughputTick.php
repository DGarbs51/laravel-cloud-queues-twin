<?php

namespace App\Jobs;

use App\Throughput;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ThroughputTick implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $run, public float $queuedAt) {}

    public function displayName(): string
    {
        return 'throughput.tick';
    }

    public function handle(Throughput $throughput): void
    {
        $throughput->tick($this->run, $this->job->uuid(), $this->queuedAt);
    }
}
