<?php

namespace Tests\Feature;

use App\Jobs\DemoJob;
use App\Telemetry;
use App\Throughput;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ContractTest extends TestCase
{
    public function test_page_ping_unknown_path_and_request_ids(): void
    {
        $this->get('/')->assertOk()->assertSee('Run queue checks');
        $this->get('/api/ping', ['X-Request-ID' => 'abc'])->assertOk()->assertHeader('X-Request-ID', 'abc')
            ->assertExactJson(['ok' => true, 'deploy' => '', 'pod' => gethostname(), 'pid' => getmypid()]);
        $this->assertNotSame('bad id!', $this->get('/api/ping', ['X-Request-ID' => 'bad id!'])->headers->get('X-Request-ID'));
        $this->get('/nope')->assertStatus(404)->assertExactJson(['error' => 'not found']);
    }

    public function test_post_requires_bounded_json_object(): void
    {
        foreach (['/api/check', '/api/reset', '/api/throughput', '/api/long'] as $path) {
            $this->call('POST', $path, server: ['CONTENT_TYPE' => 'text/plain'], content: '{}')
                ->assertStatus(415)->assertExactJson(['error' => 'expected application/json']);
            foreach (['[]', 'null', 'NaN', '{', '"hi"', "\xff"] as $bad) {
                $this->call('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: $bad)
                    ->assertStatus(400)->assertExactJson(['error' => 'expected a JSON object']);
            }
            $this->call('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat('x', 65537))
                ->assertStatus(400)->assertExactJson(['error' => 'body exceeds 64 KiB']);
        }
        $this->options('/api/check', [], ['Origin' => 'https://elsewhere.invalid', 'Access-Control-Request-Method' => 'POST'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_check_cooldown_and_reset_contract(): void
    {
        $telemetry = Mockery::mock(Telemetry::class);
        $telemetry->shouldReceive('runCheck')->twice()->andReturn(true, false);
        $telemetry->shouldReceive('reset')->once();
        $this->app->instance(Telemetry::class, $telemetry);
        $this->call('POST', '/api/check', server: ['CONTENT_TYPE' => 'application/json'], content: '{}')->assertOk()->assertExactJson(['ok' => true]);
        $this->call('POST', '/api/check', server: ['CONTENT_TYPE' => 'application/json'], content: '{}')->assertStatus(409)->assertExactJson(['error' => 'a queue check is already running']);
        $this->call('POST', '/api/reset', server: ['CONTENT_TYPE' => 'application/json; charset=utf-8'], content: '')
            ->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_stats_and_service_unavailable(): void
    {
        $telemetry = Mockery::mock(Telemetry::class);
        $snapshot = ['framework' => 'Laravel', 'python' => PHP_VERSION, 'version' => Application::VERSION,
            'mode' => 'managed', 'queue' => null, 'depth' => null, 'counts' => new \stdClass, 'events' => [], 'check' => null];
        $telemetry->shouldReceive('snapshot')->once()->andReturn($snapshot);
        $this->app->instance(Telemetry::class, $telemetry);
        $response = $this->get('/api/stats')->assertOk();
        $this->assertInstanceOf(\stdClass::class, json_decode($response->getContent())->counts);
        $this->assertSame($snapshot['framework'], $response->json('framework'));
        $this->assertNull($response->json('check'));
        $telemetry->shouldReceive('snapshot')->once()->andThrow(new \RuntimeException('redis down'));
        $this->get('/api/stats')->assertStatus(503)->assertExactJson(['error' => 'service unavailable']);
    }

    public function test_throughput_route_contract(): void
    {
        $throughput = Mockery::mock(Throughput::class);
        $throughput->shouldReceive('start')->once()->with(['count' => 1000])->andReturn([202, ['run' => 'abc']]);
        $throughput->shouldReceive('status')->once()->with('abc')->andReturn([200, ['state' => 'running']]);
        $throughput->shouldReceive('status')->once()->with('unknown')->andReturn([404, ['error' => 'unknown run']]);
        $this->app->instance(Throughput::class, $throughput);
        $this->postJson('/api/throughput', ['count' => 1000])->assertStatus(202)->assertExactJson(['run' => 'abc']);
        $this->get('/api/throughput/abc')->assertOk()->assertExactJson(['state' => 'running']);
        $this->get('/api/throughput/unknown')->assertStatus(404)->assertExactJson(['error' => 'unknown run']);
    }

    public function test_long_route_bounds_seconds_and_dispatches_one_job(): void
    {
        Queue::fake();
        foreach (['{}', '{"seconds": 0}', '{"seconds": true}', '{"seconds": 3601}', '{"seconds": "60"}', '{"seconds": 1.5}'] as $bad) {
            $this->call('POST', '/api/long', server: ['CONTENT_TYPE' => 'application/json'], content: $bad)
                ->assertStatus(400)->assertExactJson(['error' => 'seconds must be an integer from 1 to 3600']);
        }
        Queue::assertNothingPushed();
        $this->postJson('/api/long', ['seconds' => 60])->assertOk()->assertJson(['seconds' => 60]);
        Queue::assertPushed(DemoJob::class, fn ($job) => $job->kind === 'long' && $job->seconds === 60
            && $job->tries === 1 && $job->timeout === 0 && $job->displayName() === 'demo.long');
    }
}
