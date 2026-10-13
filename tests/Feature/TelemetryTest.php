<?php

namespace Tests\Feature;

use App\CheckEvaluator;
use Tests\TestCase;

class TelemetryTest extends TestCase
{
    private function event(string $event, int $attempt = 1, float $at = 100): array
    {
        return ['event' => $event, 'attempt' => $attempt, 'at' => $at, 'worker' => 'worker:1', 'python' => PHP_VERSION];
    }

    public function test_all_seven_verdicts_match_the_python_cases(): void
    {
        $cases = ['quick' => ['q'], 'async' => ['a'], 'delayed' => ['d'], 'flaky' => ['f'], 'failing' => ['x'], 'timeout' => ['t'], 'burst' => range(1, 20)];
        $histories = [[$this->event('processed')], [$this->event('processed')],
            [$this->event('queued'), $this->event('started', 1, 105), $this->event('processed')],
            [$this->event('released'), $this->event('processed', 2)],
            [$this->event('released'), $this->event('failed', 2)],
            [$this->event('started'), $this->event('started', 2, 115)]];
        foreach (range(1, 20) as $i) {
            $histories[] = [$this->event('processed')];
        }
        $result = CheckEvaluator::evaluate(['at' => 100, 'cases' => $cases], $histories, 120);
        $this->assertSame('pass', $result['status']);
        $this->assertSame([true, true, true, true, true, true, true, true], array_column($result['results'], 'ok'));
        $this->assertSame('started 5.0 s after dispatch', $result['results'][2]['detail']);
        $this->assertSame('worker exited on timeout; attempt 2 redelivered 15 s later', $result['results'][5]['detail']);
        $this->assertSame('20/20 processed exactly once', $result['results'][6]['detail']);
    }

    public function test_waiting_expiry_early_delay_duplicate_and_processed_timeout(): void
    {
        $run = ['at' => 100, 'cases' => ['quick' => ['q']]];
        $this->assertSame('running', CheckEvaluator::evaluate($run, [[]], 340)['status']);
        $expired = CheckEvaluator::evaluate($run, [[]], 341);
        $this->assertSame('fail', $expired['status']);
        $this->assertSame('gave up after 240 s: waiting for a worker', $expired['results'][0]['detail']);
        foreach ([
            ['delayed', [$this->event('queued'), $this->event('started', 1, 104.8), $this->event('processed')]],
            ['burst', [$this->event('processed'), $this->event('processed')]],
            ['timeout', [$this->event('processed')]],
            ['flaky', [$this->event('processed')]],
            ['failing', [$this->event('processed')]],
            ['quick', [$this->event('failed')]],
        ] as [$kind, $history]) {
            $this->assertSame('fail', CheckEvaluator::evaluate(['at' => 100, 'cases' => [$kind => ['id']]], [$history], 120)['status']);
        }
        $event = $this->event('processed');
        $event['python'] = 'different';
        $this->assertSame(false, CheckEvaluator::evaluate($run, [[$event]], 120)['results'][1]['ok']);
    }
}
