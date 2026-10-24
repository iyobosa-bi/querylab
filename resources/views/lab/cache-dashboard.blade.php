@extends('lab.layout')
@section('title', 'Cache: dashboard')

@section('content')
    <h1>Dashboard: no cache vs remember() vs flexible()</h1>
    <p class="question">Five slow-ish queries across four tables (requests by status, 30-day revenue, top technicians, average rating, active customers). This is cached in <code>DashboardStatsService</code>, a service, because it combines several tables.</p>

    <div class="tabs">
        @foreach (['none' => 'No cache', 'remember' => 'remember() — 60s', 'flexible' => "flexible() — fresh {$fresh}s, stale until {$stale}s"] as $key => $label)
            <a href="{{ route('lab.cache.dashboard', ['v' => $key]) }}" @class(['active' => $strategy === $key])>{{ $label }}</a>
        @endforeach
    </div>

    <div class="look" style="margin:12px 0 16px">
        <strong>Try this:</strong>
        @if ($strategy === 'none')
            Reload a few times. Every load runs all the queries.
        @elseif ($strategy === 'remember')
            Reload: the first load runs the queries (cache miss + write), the next ones run none (cache hit). After 60s one unlucky visitor pays the full cost again.
        @else
            Reload during the first {{ $fresh }}s: hits. Wait until {{ $fresh }}–{{ $stale }}s and reload: you get the old numbers instantly ("computed at" doesn't change) and Laravel recomputes after the response. Reload again: new "computed at". After {{ $stale }}s of no visits, it recomputes in the request like remember().
            <br><br><strong>Note:</strong> <code>php artisan serve</code> can't send the response before the deferred refresh finishes, so the stale load still looks slow there. Under Laravel Herd, Valet, or Nginx + PHP-FPM, it returns instantly.
        @endif
    </div>

    @include('lab.partials.metrics', ['result' => $result])

    @if ($strategy !== 'none')
        <form method="post" action="{{ route('lab.cache.dashboard.clear') }}" class="inline" style="margin-top:12px">
            @csrf
            <input type="hidden" name="v" value="{{ $strategy }}">
            <button class="secondary">Clear dashboard cache</button>
        </form>
    @endif

    <h2>Stats <span class="muted" style="font-weight:400">computed at {{ $stats['computed_at'] }} (server time now {{ now()->toDateTimeString() }})</span></h2>
    <div class="metrics">
        @foreach ($stats['by_status'] as $status => $count)
            <div class="metric"><div class="label">{{ $status }}</div><div class="value">{{ number_format($count) }}</div></div>
        @endforeach
        <div class="metric"><div class="label">Revenue, 30 days</div><div class="value">₦{{ number_format($stats['revenue_30_days'] / 1e6, 1) }}M</div></div>
        <div class="metric"><div class="label">Avg rating, 90 days</div><div class="value">{{ $stats['avg_rating_90_days'] ?? '—' }}</div></div>
        <div class="metric"><div class="label">Active customers, 30 days</div><div class="value">{{ number_format($stats['active_customers_30_days']) }}</div></div>
    </div>

    <h2>Top technicians (completed jobs, 90 days)</h2>
    <div class="panel table-wrap">
        <table>
            <tr><th>#</th><th>Technician</th><th class="num">Jobs</th></tr>
            @foreach ($stats['top_technicians_90_days'] as $row)
                <tr><td><a href="{{ route('lab.cache.technician', $row['id']) }}">{{ $row['id'] }}</a></td><td>{{ $row['name'] }}</td><td class="num">{{ $row['jobs'] }}</td></tr>
            @endforeach
        </table>
    </div>
@endsection
