@extends('lab.layout')
@section('title', 'Cache: technician')

@section('content')
    @php($p = $data['profile'])
    @php($s = $data['stats'])
    <p class="muted" style="margin:0">
        @if ($previous)<a href="{{ route('lab.cache.technician', $previous) }}">← #{{ $previous }}</a>@endif
        @if ($next) &nbsp; <a href="{{ route('lab.cache.technician', $next) }}">#{{ $next }} →</a>@endif
    </p>
    <h1>{{ $p->user->name }} <span class="muted" style="font-weight:400">technician #{{ $p->id }}</span></h1>
    <p class="muted" style="margin-top:0">{{ $p->area->name }} · {{ $p->categories->pluck('name')->join(', ') }} · ₦{{ number_format($p->hourly_rate) }}/hr · {{ $p->is_verified ? 'verified' : 'not verified' }}</p>

    <div class="look" style="margin:12px 0 16px">
        <strong>Try this:</strong> the first load is all misses and writes. Reload: 0 queries, only cache hits. (Debugbar still shows 2 small queries for the ← → links, which aren't cached.)
        Then flush this technician's tag and reload: profile, stats and reviews miss again, but the area list stays a hit, because it's under a different tag.
        Run <code>memurai-cli monitor</code> while you do this to see the keys.
    </div>

    @include('lab.partials.metrics', ['result' => $result])

    <div style="margin-top:12px">
        <form method="post" action="{{ route('lab.cache.technician.flush', $technicianId) }}" class="inline">@csrf<button>Flush tag technician:{{ $technicianId }}</button></form>
        <form method="post" action="{{ route('lab.cache.technicians.flush-all') }}" class="inline">@csrf<button class="secondary">Flush tag "technicians"</button></form>
    </div>

    <h2>Stats</h2>
    <div class="metrics">
        <div class="metric"><div class="label">Completed jobs</div><div class="value">{{ number_format($s['completed_jobs']) }}</div></div>
        <div class="metric"><div class="label">Active jobs</div><div class="value">{{ $s['active_jobs'] }}</div></div>
        <div class="metric"><div class="label">Rating</div><div class="value">{{ $s['avg_rating'] ?? 'No reviews' }}</div></div>
        <div class="metric"><div class="label">Reviews</div><div class="value">{{ number_format($s['reviews_count']) }}</div></div>
        <div class="metric"><div class="label">Earned</div><div class="value">₦{{ $s['total_earned'] >= 1e6 ? number_format($s['total_earned'] / 1e6, 1).'M' : number_format($s['total_earned']) }}</div></div>
        <div class="metric"><div class="label">Views today (Redis)</div><div class="value">{{ $data['viewsToday'] }}</div></div>
    </div>

    <h2>Recent reviews</h2>
    <div class="panel table-wrap">
        @forelse ($data['reviews'] as $review)
            <p style="margin:0 0 8px"><strong>{{ str_repeat('★', $review->rating) }}</strong> {{ $review->comment ?? '(no comment)' }} <span class="muted">— {{ $review->customer->name }}</span></p>
        @empty
            <p class="muted" style="margin:0">No reviews yet.</p>
        @endforelse
    </div>

    <h2>Top rated in {{ $p->area->name }} <span class="muted" style="font-weight:400">(cached under tag area:{{ $p->area_id }}, 10 min)</span></h2>
    <div class="panel table-wrap">
        <table>
            <tr><th>#</th><th>Name</th><th class="num">Avg</th><th class="num">Reviews</th></tr>
            @forelse ($data['topInArea'] as $t)
                <tr><td><a href="{{ route('lab.cache.technician', $t->id) }}">{{ $t->id }}</a></td><td>{{ $t->user->name }}</td><td class="num">{{ round($t->reviews_avg_rating, 2) }}</td><td class="num">{{ $t->reviews_count }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No verified technician here has 5+ reviews.</td></tr>
            @endforelse
        </table>
    </div>
@endsection
