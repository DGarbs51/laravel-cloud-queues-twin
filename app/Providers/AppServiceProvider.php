<?php

namespace App\Providers;

use App\Telemetry;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(JobQueued::class, fn (JobQueued $event) => app(Telemetry::class)->queued($event));
    }
}
