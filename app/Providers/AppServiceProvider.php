<?php

namespace App\Providers;

use App\Repositories\CachedTechnicianRepository;
use App\Repositories\Contracts\TechnicianRepositoryInterface;
use App\Repositories\EloquentTechnicianRepository;
use App\Support\Lab\QueryMeter;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueryMeter::class);

        // Services depend on the interface. Swap this one line to turn caching off:
        //   $this->app->bind(TechnicianRepositoryInterface::class, EloquentTechnicianRepository::class);
        $this->app->bind(TechnicianRepositoryInterface::class, fn ($app) => new CachedTechnicianRepository(
            $app->make(EloquentTechnicianRepository::class),
        ));
    }

    public function boot(): void
    {
        // Note for your real app: add Model::preventLazyLoading(! $this->app->isProduction());
        // It is left off here on purpose, because the N+1 lab needs lazy loading to happen.

        if (! config('lab.enabled') || $this->app->isProduction()) {
            return;
        }

        $meter = $this->app->make(QueryMeter::class);

        DB::listen(fn ($query) => $meter->recordQuery($query));
        Event::listen('eloquent.retrieved: *', fn () => $meter->recordModel());
        Event::listen(CacheHit::class, fn () => $meter->recordCache('hit'));
        Event::listen(CacheMissed::class, fn () => $meter->recordCache('miss'));
        Event::listen(KeyWritten::class, fn () => $meter->recordCache('write'));
        Event::listen(KeyForgotten::class, fn () => $meter->recordCache('forget'));
    }
}
