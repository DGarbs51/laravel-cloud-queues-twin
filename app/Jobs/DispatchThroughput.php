<?php

namespace App\Jobs;

use App\Throughput;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Queue;
use Throwable;

class DispatchThroughput implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $run, public int $count) {}

    public function displayName(): string
    {
        return 'throughput.dispatch';
    }

    public function handle(Throughput $throughput): void
    {
        try {
            for ($i = 0; $i < $this->count; $i++) {
                Queue::push(new ThroughputTick($this->run, microtime(true)));
            }
        } catch (Throwable $exception) {
            $throughput->fail($this->run, $exception);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            app(Throughput::class)->fail($this->run, $exception);
        }
    }
}
