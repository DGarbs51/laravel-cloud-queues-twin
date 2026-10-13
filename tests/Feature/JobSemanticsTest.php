<?php

namespace Tests\Feature;

use App\Jobs\DemoJob;
use App\Jobs\DispatchThroughput;
use App\Jobs\ThroughputTick;
use App\Telemetry;
use App\Throughput;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class JobSemanticsTest extends TestCase
{
    public function test_each_job_matches_sleep_tries_backoff_timeout_and_events(): void
    {
        foreach (['quick', 'async', 'delayed', 'burst', 'slow', 'timeout', 'flaky', 'failing'] as $kind) {
            foreach ([1, 2] as $attempt) {
                Sleep::fake();
                $job = new DemoJob($kind);
                $delivery = Mockery::mock(Job::class);
                $delivery->shouldReceive('uuid')->andReturn('uid');
                $delivery->shouldReceive('attempts')->andReturn($attempt);
                $job->setJob($delivery);
                $events = [];
                $telemetry = Mockery::mock(Telemetry::class)->makePartial();
                $telemetry->shouldReceive('record')->andReturnUsing(function ($event, $fields) use (&$events): void {
                    $events[] = [$event, $fields];
                });
                $error = null;
                try {
                    $job->handle($telemetry);
                } catch (RuntimeException $exception) {
                    $error = $exception->getMessage();
                }
                $fails = $kind === 'failing' || ($kind === 'flaky' && $attempt === 1);
                $this->assertSame($fails ? ($kind === 'flaky' ? 'flaky job fails on its first attempt' : 'this job always fails') : null, $error);
                $this->assertSame('started', $events[0][0]);
                $this->assertSame($fails ? ($attempt >= $job->tries ? 'failed' : 'released') : 'processed', $events[1][0]);
                $this->assertSame('demo.'.(in_array($kind, ['delayed', 'burst']) ? 'quick' : $kind), $events[0][1]['job']);
                $this->assertSame($attempt, $events[0][1]['attempt']);
                $this->assertSame(PHP_VERSION, $events[0][1]['python']);
                $this->assertStringEndsWith(':'.getmypid(), $events[0][1]['worker']);
                $this->assertSame(match ($kind) {
                    'flaky' => 3, 'failing', 'timeout' => 2, default => 1
                }, $job->tries);
                $this->assertSame(match ($kind) {
                    'flaky' => [2], 'failing' => [1], default => [0]
                }, $job->backoff);
                $this->assertSame($kind === 'timeout' ? 3 : 60, $job->timeout);
                if (in_array($kind, ['quick', 'async', 'delayed', 'burst'])) {
                    Sleep::assertSleptTimes(1);
                    Sleep::assertSlept(fn ($duration) => $duration->totalMicroseconds >= 50000 && $duration->totalMicroseconds <= 300000);
                } elseif (in_array($kind, ['slow', 'timeout'])) {
                    Sleep::assertSequence([$kind === 'slow' ? Sleep::for(3)->seconds() : Sleep::for(10)->seconds()]);
                } else {
                    Sleep::assertNeverSlept();
                }
            }
        }
    }

    public function test_throughput_launcher_and_tick(): void
    {
        Queue::fake();
        $throughput = Mockery::mock(Throughput::class);
        (new DispatchThroughput('run', 3))->handle($throughput);
        Queue::assertPushed(ThroughputTick::class, 3);
        Queue::assertPushed(ThroughputTick::class, fn ($tick) => $tick->run === 'run' && $tick->displayName() === 'throughput.tick');
        $tick = new ThroughputTick('run', 123.5);
        $delivery = Mockery::mock(Job::class);
        $delivery->shouldReceive('uuid')->andReturn('uid');
        $tick->setJob($delivery);
        $throughput->shouldReceive('tick')->once()->with('run', 'uid', 123.5);
        $tick->handle($throughput);
    }
}
