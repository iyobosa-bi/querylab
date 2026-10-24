@extends('lab.layout')
@section('title', 'Cache: OTP')

@section('content')
    <h1>OTP: put, pull, add, increment</h1>
    <p class="question">A one-time code that expires in 5 minutes, can be requested 3 times per 10 minutes, and allows 5 wrong guesses.</p>

    <div class="look" style="margin:12px 0 16px">
        <strong>Try this:</strong> request a code, enter a wrong one a few times, then the right one. Then try the right code again: it fails, because it was pulled.
        Request 4 codes quickly to hit the send limit. Watch <code>memurai-cli monitor</code>: you'll see SET with EX (put), GET+DEL (pull), SET NX (add) and INCRBY (increment).
    </div>

    <div class="grid">
        <div class="panel">
            <h2 style="margin-top:0">1. Request a code</h2>
            <form method="post" action="{{ route('lab.cache.otp.send') }}">
                @csrf
                <p><input name="phone" value="{{ old('phone', '08012345678') }}" placeholder="08012345678"></p>
                @error('phone')<p class="error">{{ $message }}</p>@enderror
                <button>Send code</button>
            </form>
        </div>
        <div class="panel">
            <h2 style="margin-top:0">2. Verify it</h2>
            <form method="post" action="{{ route('lab.cache.otp.verify') }}">
                @csrf
                <p><input name="phone" value="{{ old('phone', '08012345678') }}"></p>
                <p><input name="code" placeholder="6-digit code" inputmode="numeric" autocomplete="one-time-code"></p>
                @error('code')<p class="error">{{ $message }}</p>@enderror
                <button>Verify</button>
            </form>
        </div>
    </div>

    <h2>Keys this uses</h2>
<pre>otp:{phone}            put()   hashed code + expiry, TTL 5 min      pull() on verify
otp:sends:{phone}      add() + increment()   send limit, fixed 10-minute window
otp:attempts:{phone}   add() + increment()   wrong-guess limit</pre>
    <p class="muted">In a real app, Laravel's <code>RateLimiter</code> does the add + increment counting for you. It's done by hand here so you can see how it works.</p>
@endsection
