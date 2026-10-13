<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiContract
{
    public function handle(Request $request, Closure $next): Response
    {
        $rid = $request->header('X-Request-ID', '');
        if (! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $rid)) {
            $rid = str_replace('-', '', (string) Str::uuid());
        }
        $response = $this->validate($request) ?? $next($request);
        $response->headers->set('X-Request-ID', $rid);

        return $response;
    }

    private function validate(Request $request): ?Response
    {
        if (strlen($request->getContent()) > 65536) {
            return response()->json(['error' => 'body exceeds 64 KiB'], 400);
        }
        if ($request->method() !== 'POST') {
            return null;
        }
        if (trim(explode(';', $request->header('Content-Type', ''))[0]) !== 'application/json') {
            return response()->json(['error' => 'expected application/json'], 415);
        }
        $raw = $request->getContent();
        $decoded = $raw === '' ? new \stdClass : json_decode($raw);
        if (! $decoded instanceof \stdClass || ($raw !== '' && json_last_error() !== JSON_ERROR_NONE)) {
            return response()->json(['error' => 'expected a JSON object'], 400);
        }
        $request->attributes->set('contract_data', (array) $decoded);

        return null;
    }
}
