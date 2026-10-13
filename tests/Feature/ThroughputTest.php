<?php

namespace Tests\Feature;

use App\Telemetry;
use App\Throughput;
use Tests\TestCase;

class ThroughputTest extends TestCase
{
    public function test_count_validation_and_deadlines(): void
    {
        $throughput = new Throughput(new Telemetry);
        foreach ([true, false, null, '1000', 1.5, 1.0, 0, 10001, []] as $bad) {
            $this->assertSame([400, ['error' => 'count must be an integer from 1 to 10000']], $throughput->start(['count' => $bad]));
        }
        $this->assertSame(80.0, Throughput::deadline(1000));
        $this->assertSame(300.0, Throughput::deadline(10000));
    }

    public function test_rate_percentiles_and_verdicts(): void
    {
        $this->assertSame([404, ['error' => 'unknown run']], Throughput::calculate([]));
        $this->assertSame(404, Throughput::calculate(['d:late' => 1])[0]);
        $raw = ['count' => '2', 'started_at' => '100', 'd:a' => '1', 'd:b' => '1', 'w:a' => '0.001', 'w:b' => '0.002', 'f:a' => '100.01', 'f:b' => '100.02'];
        [$code, $body] = Throughput::calculate($raw, 101);
        $this->assertSame(200, $code);
        $this->assertSame(['state' => 'done', 'count' => 2, 'processed' => 2, 'jobs_per_s' => 100.0,
            'wait_ms' => ['p50' => 1.0, 'p95' => 2.0], 'lost' => 0, 'duplicates' => 0, 'verdict' => 'pass'], $body);
        $this->assertSame('fail', Throughput::calculate([...$raw, 'd:a' => '2'], 101)[1]['verdict']);
        $this->assertSame('warn', Throughput::calculate([...$raw, 'f:b' => '101'], 102)[1]['verdict']);
        $this->assertSame('warn', Throughput::calculate([...$raw, 'w:b' => '16'], 102)[1]['verdict']);
        $pending = ['count' => '2', 'started_at' => '100'];
        $this->assertSame('running', Throughput::calculate($pending, 130.1)[1]['state']);
        $this->assertSame('fail', Throughput::calculate($pending, 131)[1]['verdict']);
        $this->assertSame(2, Throughput::calculate($pending, 131)[1]['lost']);
        $this->assertSame('failed', Throughput::calculate([...$pending, 'error' => 'dispatch failed'], 100)[1]['state']);
        $this->assertSame($body, Throughput::calculate(['result' => json_encode($body, JSON_PRESERVE_ZERO_FRACTION)], 999)[1]);
    }
}
