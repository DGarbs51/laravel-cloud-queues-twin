<?php

namespace Tests\Feature;

use App\Jobs\DispatchThroughput;
use App\Telemetry;
use App\Throughput;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RedisIntegrationTest extends TestCase
{
    public function test_real_valkey_dispatch_histories_reset_locks_dedup_and_freezing(): void
    {
        $namespace = 'lcq-twin-test:'.bin2hex(random_bytes(8)).':';
        config(['demo.key_prefix' => $namespace, 'queue.default' => 'redis', 'queue.connections.redis.queue' => $namespace.'queue']);
        $telemetry = new Telemetry;
        $store = $telemetry->store();
        try {
            $this->assertTrue($telemetry->runCheck());
            $this->assertFalse($telemetry->runCheck());
            $run = json_decode($store->get($telemetry->key('check')), true);
            $this->assertCount(7, $run['cases']);
            $this->assertCount(20, $run['cases']['burst']);
            $this->assertSame(26, (int) $store->hget($telemetry->key('stats'), 'queued'));
            $this->assertSame(25, $store->llen('queues:'.$namespace.'queue'));
            $this->assertSame(1, $store->zcard('queues:'.$namespace.'queue:delayed'));
            foreach ($run['cases'] as $kind => $uuids) {
                foreach ($uuids as $uuid) {
                    $this->assertIsString($uuid);
                    $history = json_decode($store->lindex($telemetry->key('job:'.$uuid), 0), true);
                    $this->assertSame($uuid, $history['uuid']);
                    $this->assertSame($kind === 'delayed' ? 5 : 0, $history['delay']);
                    $this->assertGreaterThan(0, $store->ttl($telemetry->key('job:'.$uuid)));
                }
            }
            $this->assertSame('running', $telemetry->snapshot()['check']['status']);
            $telemetry->reset();
            $this->assertNull($telemetry->snapshot()['check']);
            $this->assertSame([], (array) $telemetry->snapshot()['counts']);
            $this->assertFalse($telemetry->runCheck());
            Queue::fake();
            $throughput = new Throughput($telemetry);
            [$code, $body] = $throughput->start(['count' => 1]);
            $this->assertSame(202, $code);
            $id = $body['run'];
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
            Queue::assertPushed(DispatchThroughput::class, fn ($job) => $job->run === $id && $job->count === 1);
            $this->assertSame([409, ['error' => 'a throughput run is already active', 'run' => $id]], $throughput->start([]));
            $throughput->tick($id, 'uid', microtime(true));
            $throughput->tick($id, 'uid', microtime(true));
            $this->assertSame(2, (int) $store->hget($throughput->key($id), 'd:uid'));
            [$code, $frozen] = $throughput->status($id);
            $this->assertSame('fail', $frozen['verdict']);
            $this->assertSame(1, $frozen['duplicates']);
            $this->assertFalse((bool) $store->exists($throughput->key('active')));
            $throughput->tick($id, 'late', microtime(true));
            $this->assertSame($frozen, $throughput->status($id)[1]);
            $this->assertGreaterThan(0, $store->ttl($throughput->key($id)));
            $this->assertSame(404, $throughput->status('unknown')[0]);
        } finally {
            foreach ([$namespace.'*', 'queues:'.$namespace.'*'] as $pattern) {
                $cursor = null;
                do {
                    $page = $store->scan($cursor, ['match' => $pattern, 'count' => 100]);
                    if ($page === false) {
                        break;
                    }
                    [$cursor, $keys] = $page;
                    foreach ($keys as $key) {
                        $store->del($key);
                    }
                } while ($cursor);
            }
        }
    }
}
