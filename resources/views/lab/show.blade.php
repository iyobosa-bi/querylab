@extends('lab.layout')
@section('title', $lab['title'])

@section('content')
    <p class="muted" style="margin:0"><a href="{{ route('lab.index') }}">← All labs</a></p>
    <h1>{{ $lab['title'] }}</h1>
    <p class="question">{{ $lab['question'] }}</p>

    <div class="tabs">
        @foreach ($lab['variants'] as $key => $v)
            <a href="{{ route('lab.show', array_merge(request()->except('v'), ['slug' => $slug, 'v' => $key])) }}" @class(['active' => $key === $variantKey])>{{ $v['label'] }}</a>
        @endforeach
    </div>

    <div class="look" style="margin:12px 0 16px"><strong>What to look at:</strong> {!! preg_replace('/`([^`]+)`/', '<code>$1</code>', e($lab['look'])) !!}</div>

    @include('lab.partials.metrics', ['result' => $result])

    <h2>Code that ran</h2>
    <pre>{{ $code }}</pre>

    @if ($note)
        <h2>Note</h2>
        <pre>{{ $note }}</pre>
    @endif

    @if ($plan)
        <h2>EXPLAIN (ANALYZE, BUFFERS)</h2>
        <p class="muted" style="margin-top:-4px">Read the lines bottom-up. "Seq Scan" reads every row; "Index Scan" / "Bitmap Index Scan" use an index. "actual time" is in milliseconds.</p>
        <pre>{{ $plan }}</pre>
    @endif

    <h2>Result <span class="muted" style="font-weight:400">(first {{ count($rows) }} rows)</span></h2>
    @if (count($rows))
        <div class="panel table-wrap">
            <table>
                <tr>@foreach (array_keys($rows[0]) as $column)<th>{{ $column }}</th>@endforeach</tr>
                @foreach ($rows as $row)
                    <tr>@foreach ($row as $value)<td @class(['num' => is_numeric($value)])>{{ is_null($value) ? 'NULL' : (is_scalar($value) ? $value : json_encode($value)) }}</td>@endforeach</tr>
                @endforeach
            </table>
        </div>
    @else
        <p class="muted">No rows.</p>
    @endif
@endsection
