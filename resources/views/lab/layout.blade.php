<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Query Lab') · Tech-aid Query Lab</title>
    <style>
        :root {
            --bg: #f6f7f9; --panel: #ffffff; --text: #1d2330; --muted: #5f6b7a; --border: #dde2e8;
            --accent: #2f6fdb; --accent-soft: #e8f0fd; --good: #1f8a4c; --bad: #c2410c; --code: #f1f3f6;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #12151b; --panel: #1a1f27; --text: #e6e9ee; --muted: #9aa5b4; --border: #2c333e;
                --accent: #7aa7f5; --accent-soft: #1f2b40; --good: #4ade80; --bad: #fb923c; --code: #141922;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        header { background: var(--panel); border-bottom: 1px solid var(--border); padding: 12px 20px; display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
        header strong { font-size: 16px; }
        header nav { display: flex; gap: 14px; flex-wrap: wrap; font-size: 14px; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 20px 120px; }
        h1 { font-size: 24px; margin: 0 0 6px; }
        h2 { font-size: 17px; margin: 28px 0 10px; }
        .muted { color: var(--muted); }
        .panel { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; margin: 14px 0; }
        .question { font-size: 16px; }
        .look { border-left: 3px solid var(--accent); background: var(--accent-soft); padding: 10px 14px; border-radius: 6px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin: 16px 0 4px; }
        .tabs a { border: 1px solid var(--border); background: var(--panel); padding: 7px 12px; border-radius: 999px; font-size: 14px; color: var(--text); }
        .tabs a.active { background: var(--accent); border-color: var(--accent); color: #fff; }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; }
        .metric { background: var(--panel); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; }
        .metric .label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .metric .value { font-size: 22px; font-weight: 600; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        pre { background: var(--code); border: 1px solid var(--border); border-radius: 8px; padding: 12px 14px; overflow-x: auto; font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; margin: 0; }
        .table-wrap { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; font-size: 13px; }
        th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; max-width: 320px; overflow: hidden; text-overflow: ellipsis; }
        th { color: var(--muted); font-weight: 600; }
        th.num { text-align: right; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
        .card h3 { margin: 0 0 4px; font-size: 16px; }
        .card p { margin: 0 0 8px; }
        .flash { background: var(--accent-soft); border: 1px solid var(--accent); border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
        .error { color: var(--bad); }
        button, input { font: inherit; }
        button { background: var(--accent); color: #fff; border: 0; border-radius: 8px; padding: 8px 14px; cursor: pointer; }
        button.secondary { background: transparent; color: var(--accent); border: 1px solid var(--accent); }
        input { border: 1px solid var(--border); background: var(--panel); color: var(--text); border-radius: 8px; padding: 8px 10px; }
        form.inline { display: inline-block; margin: 4px 6px 4px 0; }
        code { background: var(--code); padding: 1px 5px; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
<header>
    <strong><a href="{{ route('lab.index') }}" style="color:inherit">Tech-aid Query Lab</a></strong>
    <nav>
        <a href="{{ route('lab.index') }}">Query labs</a>
        <a href="{{ route('lab.cache.dashboard') }}">Cache: dashboard</a>
        <a href="{{ route('lab.cache.technicians') }}">Cache: technician</a>
        <a href="{{ route('lab.cache.otp') }}">Cache: OTP</a>
    </nav>
    <span class="muted" style="margin-left:auto;font-size:13px">LAB_SIZE={{ config('lab.size') }} · "today" = {{ config('lab.anchor_date') }}</span>
</header>
<main>
    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
