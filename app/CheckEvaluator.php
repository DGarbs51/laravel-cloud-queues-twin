<?php

namespace App;

class CheckEvaluator
{
    public static function evaluate(array $run, array $histories, ?float $now = null): array
    {
        $expired = ($now ?? microtime(true)) - $run['at'] > Telemetry::CHECK_DEADLINE;
        $results = $seen = [];
        $offset = 0;
        foreach ($run['cases'] as $kind => $uuids) {
            $jobs = array_slice($histories, $offset, count($uuids));
            $offset += count($uuids);
            foreach ($jobs as $history) {
                array_push($seen, ...$history);
            }
            [$ok, $detail] = self::expectation($kind, $jobs);
            if ($ok === null && $expired) {
                [$ok, $detail] = [false, 'gave up after 240 s: '.$detail];
            }
            $results[] = ['case' => $kind, 'ok' => $ok, 'detail' => $detail];
        }
        $results[] = ['case' => 'workers', ...self::workers($seen, $expired)];
        $oks = array_column($results, 'ok');

        return ['status' => in_array(false, $oks, true) ? 'fail' : (in_array(null, $oks, true) ? 'running' : 'pass'),
            'started_at' => $run['at'], 'results' => $results];
    }

    private static function outcomes(array $history): array
    {
        return array_values(array_map(fn ($e) => [$e['event'], $e['attempt'] ?? null], array_filter($history, fn ($e) => $e['event'] !== 'queued')));
    }

    private static function firstTry(array $history): array
    {
        $outcomes = self::outcomes($history);
        if (in_array(['processed', 1], $outcomes, true)) {
            return [true, 'processed on attempt 1'];
        }
        $last = $outcomes ? $outcomes[array_key_last($outcomes)] : null;
        if ($last && in_array($last[0], ['released', 'failed'], true)) {
            return [false, $last[0].' on attempt '.$last[1]];
        }

        return [null, 'waiting for a worker'];
    }

    private static function expectation(string $kind, array $jobs): array
    {
        $history = $jobs[0];
        $outcomes = self::outcomes($history);
        if (in_array($kind, ['quick', 'async'], true)) {
            return self::firstTry($history);
        }
        if ($kind === 'delayed') {
            [$ok, $detail] = self::firstTry($history);
            if (! $ok) {
                return [$ok, $detail];
            }
            $byEvent = array_column($history, null, 'event');
            $waited = $byEvent['started']['at'] - $byEvent['queued']['at'];

            return $waited < 4.9 ? [false, sprintf('started %.1f s after dispatch, expected >= 5 s', $waited)]
                : [true, sprintf('started %.1f s after dispatch', $waited)];
        }
        if ($kind === 'flaky' || $kind === 'failing') {
            $final = $kind === 'flaky' ? 'processed' : 'failed';
            if (in_array(['released', 1], $outcomes, true) && in_array([$final, 2], $outcomes, true)) {
                return [true, 'released on attempt 1, '.$final.' on attempt 2'];
            }
            $events = array_column($outcomes, 0);
            if (($kind === 'flaky' && (in_array('failed', $events, true) || in_array(['processed', 1], $outcomes, true)))
                || ($kind === 'failing' && in_array('processed', $events, true))) {
                $tuples = array_map(fn ($o) => "('".$o[0]."', ".($o[1] ?? 'None').')', $outcomes);

                return [false, 'unexpected outcomes ['.implode(', ', $tuples).']'];
            }

            return [null, $kind === 'flaky' ? 'waiting for the retry' : 'waiting for the final attempt'];
        }
        if ($kind === 'timeout') {
            if (in_array('processed', array_column($history, 'event'), true)) {
                return [false, 'a job that exceeds its timeout was reported processed'];
            }
            $starts = array_column(array_filter($history, fn ($e) => $e['event'] === 'started'), null, 'attempt');
            if (isset($starts[1], $starts[2])) {
                return [true, sprintf('worker exited on timeout; attempt 2 redelivered %.0f s later', $starts[2]['at'] - $starts[1]['at'])];
            }

            return [null, isset($starts[1]) ? 'attempt 1 timed out; waiting for the restart and redelivery' : 'waiting for a worker'];
        }
        $processed = array_map(fn ($h) => count(array_filter($h, fn ($e) => $e['event'] === 'processed')), $jobs);
        $duplicates = count(array_filter($processed, fn ($n) => $n > 1));
        if ($duplicates) {
            return [false, $duplicates.' job(s) processed more than once'];
        }
        $done = array_sum($processed);

        return [$done === count($jobs) ? true : null, $done.'/'.count($jobs).' processed'.($done === count($jobs) ? ' exactly once' : '')];
    }

    private static function workers(array $events, bool $expired): array
    {
        $workers = [];
        foreach ($events as $event) {
            if (isset($event['worker'])) {
                $workers[$event['worker'].'|'.($event['python'] ?? '')] = [$event['worker'], $event['python'] ?? null];
            }
        }
        if (! $workers) {
            return ['ok' => $expired ? false : null, 'detail' => 'no worker has run a job'];
        }
        $names = array_map(fn ($w) => $w[0].' (Python '.($w[1] ?? 'None').')', $workers);
        sort($names, SORT_STRING);
        $names = implode(', ', $names);
        $mismatched = array_values(array_map(fn ($w) => (string) ($w[1] ?? 'None'), array_filter($workers, fn ($w) => $w[1] !== PHP_VERSION)));
        sort($mismatched, SORT_STRING);
        if ($mismatched) {
            return ['ok' => false, 'detail' => "workers on Python ['".implode("', '", $mismatched)."'], web on ".PHP_VERSION.': '.$names];
        }

        return ['ok' => true, 'detail' => count(array_unique(array_column($workers, 0))).' worker(s): '.$names];
    }
}
