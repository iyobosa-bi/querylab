<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The lab pages run slow queries on purpose. They must never be reachable in production. */
class EnsureLabEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('lab.enabled') && ! app()->isProduction(), 404);

        return $next($request);
    }
}
