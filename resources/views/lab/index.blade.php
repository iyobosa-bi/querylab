@extends('lab.layout')
@section('title', 'Labs')

@section('content')
    <h1>Query &amp; cache labs</h1>
    <p class="muted">Every lab answers one question several ways. Open each variant, then compare the numbers here and in Debugbar's Queries, Models, Timeline and Cache tabs.</p>

    <h2>Query performance</h2>
    <div class="grid">
        @foreach ($labs as $slug => $lab)
            <div class="panel card">
                <h3>{{ $lab['title'] }}</h3>
                <p class="muted">{{ $lab['question'] }}</p>
                @foreach ($lab['variants'] as $key => $variant)
                    <a href="{{ route('lab.show', ['slug' => $slug, 'v' => $key]) }}">{{ $variant['label'] }}</a>@if (! $loop->last) · @endif
                @endforeach
            </div>
        @endforeach
    </div>

    <h2>Redis / Memurai caching</h2>
    <div class="grid">
        <div class="panel card">
            <h3>Dashboard: remember vs flexible</h3>
            <p class="muted">Service-layer cache of a slow multi-table dashboard.</p>
            <a href="{{ route('lab.cache.dashboard', ['v' => 'none']) }}">No cache</a> ·
            <a href="{{ route('lab.cache.dashboard', ['v' => 'remember']) }}">remember()</a> ·
            <a href="{{ route('lab.cache.dashboard', ['v' => 'flexible']) }}">flexible()</a>
        </div>
        <div class="panel card">
            <h3>Technician profile: tags + repository decorator</h3>
            <p class="muted">CachedTechnicianRepository, tag flushing, the null gotcha, an atomic view counter.</p>
            <a href="{{ route('lab.cache.technicians') }}">Open the busiest technician</a>
        </div>
        <div class="panel card">
            <h3>OTP: put, pull, add, increment</h3>
            <p class="muted">One-time codes with send limits and attempt limits.</p>
            <a href="{{ route('lab.cache.otp') }}">Open OTP lab</a>
        </div>
    </div>

    <h2>Command-line labs <span class="muted" style="font-weight:400">(Debugbar doesn't run in the console)</span></h2>
    <div class="panel">
<pre>php artisan lab:chunk-benchmark              # get vs chunk vs chunkById vs lazy vs lazyById vs cursor
php artisan lab:chunk-bug                    # watch chunk() skip rows, then chunkById() fix it
php artisan lab:race --mode=nolock           # 5 technicians accept one job at once: the race
php artisan lab:race --mode=lock             # same, protected by Cache::lock()
php artisan lab:race --mode=atomic           # same, protected by a conditional UPDATE only</pre>
    </div>
@endsection
