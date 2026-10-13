<?php

namespace App\Http\Controllers;

use App\Jobs\DemoJob;
use App\Telemetry;
use App\Throughput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

class QueueController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'deploy' => config('demo.deploy_marker'), 'pod' => gethostname(), 'pid' => getmypid()]);
    }

    public function stats(Telemetry $telemetry): JsonResponse
    {
        return response()->json($telemetry->snapshot());
    }

    public function check(Telemetry $telemetry): JsonResponse
    {
        return $telemetry->runCheck() ? response()->json(['ok' => true])
            : response()->json(['error' => 'a queue check is already running'], 409);
    }

    public function reset(Telemetry $telemetry): JsonResponse
    {
        $telemetry->reset();

        return response()->json(['ok' => true]);
    }

    public function startThroughput(Request $request, Throughput $throughput): JsonResponse
    {
        [$code, $body] = $throughput->start($request->attributes->get('contract_data'));

        return response()->json($body, $code);
    }

    public function long(Request $request): JsonResponse
    {
        $seconds = $request->attributes->get('contract_data')['seconds'] ?? null;
        if (! is_int($seconds) || $seconds < 1 || $seconds > 3600) {
            return response()->json(['error' => 'seconds must be an integer from 1 to 3600'], 400);
        }
        $job = new DemoJob('long', $seconds);
        Queue::push($job);

        return response()->json(['uuid' => $job->uuid, 'seconds' => $seconds]);
    }

    public function throughput(string $run, Throughput $throughput): JsonResponse
    {
        [$code, $body] = $throughput->status($run);

        return response()->json($body, $code);
    }
}
