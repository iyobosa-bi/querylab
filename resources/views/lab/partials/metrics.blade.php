@php($fmt = \App\Support\Lab\QueryMeter::formatBytes(...))
<div class="metrics">
    <div class="metric"><div class="label">Queries</div><div class="value">{{ number_format($result['queries']) }}</div></div>
    <div class="metric"><div class="label">SQL time</div><div class="value">{{ number_format($result['sql_ms'], 1) }} ms</div></div>
    <div class="metric"><div class="label">Wall time</div><div class="value">{{ number_format($result['wall_ms'], 1) }} ms</div></div>
    <div class="metric"><div class="label">Peak memory</div><div class="value">{{ $fmt($result['peak_memory']) }}</div></div>
    <div class="metric"><div class="label">Models</div><div class="value">{{ number_format($result['models']) }}</div></div>
    @if (array_sum($result['cache']) > 0)
        <div class="metric"><div class="label">Cache hit / miss / write</div><div class="value">{{ $result['cache']['hit'] }} / {{ $result['cache']['miss'] }} / {{ $result['cache']['write'] }}</div></div>
    @endif
</div>
<p class="muted" style="font-size:13px;margin:6px 0 0">Measured around the code below only. Debugbar measures the whole request, so its numbers are a bit higher. Compare variants against each other, not exact values.</p>

@if (count($result['statements']))
    <details class="panel">
        <summary><strong>{{ count($result['statements']) }} distinct SQL statement(s)</strong> <span class="muted">— repeated statements are the N+1 signature</span></summary>
        <div class="table-wrap" style="margin-top:10px">
            <table>
                <tr><th>Times run</th><th>Total ms</th><th>SQL</th></tr>
                @foreach (array_slice($result['statements'], 0, 12, true) as $sql => $stat)
                    <tr><td class="num">{{ $stat['count'] }}</td><td class="num">{{ number_format($stat['ms'], 1) }}</td><td style="white-space:normal;max-width:none"><code>{{ \Illuminate\Support\Str::limit($sql, 220) }}</code></td></tr>
                @endforeach
            </table>
        </div>
    </details>
@endif
