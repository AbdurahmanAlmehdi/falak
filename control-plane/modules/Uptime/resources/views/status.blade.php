@php
    $overall = [
        'operational' => ['All systems operational', 'up'],
        'partial' => ['Partial outage', 'partial'],
        'outage' => ['Major outage', 'down'],
        'unknown' => ['Status unknown', 'unknown'],
    ][$page['overall']];
    $fmt = fn (?float $v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 2), '0'), '.').'%';
    $tone = fn (?float $v) => match (true) { $v === null => 'none', $v >= 99.9 => 'up', $v >= 99 => 'partial', default => 'down' };
    $duration = function (int $s): string {
        return match (true) {
            $s < 60 => "{$s} s",
            $s < 3600 => intdiv($s, 60).' min',
            $s < 86400 => intdiv($s, 3600).' h '.intdiv($s % 3600, 60).' min',
            default => intdiv($s, 86400).' d '.intdiv($s % 86400, 3600).' h',
        };
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page['title'] }} · Status</title>
    @if (config('uptime.status_page.noindex', true))
        <meta name="robots" content="noindex">
    @endif
    <style>
        :root { --bg: #fafafa; --fg: #18181b; --muted: #71717a; --card: #fff; --border: #e4e4e7; --up: #16a34a; --partial: #d97706; --down: #dc2626; --none: #d4d4d8; }
        @media (prefers-color-scheme: dark) { :root { --bg: #09090b; --fg: #fafafa; --muted: #a1a1aa; --card: #18181b; --border: #27272a; --none: #3f3f46; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 40px 16px 64px; }
        h1 { font-size: 26px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 32px 0 12px; }
        p { margin: 0; }
        .muted { color: var(--muted); font-size: 13px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
        .banner { display: flex; align-items: center; gap: 10px; margin: 24px 0; font-weight: 600; }
        .dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
        .up { background: var(--up); } .partial { background: var(--partial); } .down { background: var(--down); } .none, .unknown { background: var(--none); }
        .monitor + .monitor { margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
        .row { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .name { display: flex; align-items: center; gap: 8px; font-weight: 500; min-width: 0; overflow-wrap: anywhere; }
        .bars { display: flex; gap: 2px; height: 32px; margin: 10px 0 4px; }
        .bars span { flex: 1; border-radius: 2px; min-width: 1px; }
        ul { list-style: none; padding: 0; margin: 0; }
        li + li { margin-top: 10px; }
    </style>
</head>
<body>
<main>
    <h1>{{ $page['title'] }}</h1>
    @if ($page['description'])
        <p class="muted">{{ $page['description'] }}</p>
    @endif

    <div class="card banner"><span class="dot {{ $overall[1] }}"></span>{{ $overall[0] }}</div>

    @if ($page['monitors'] !== [])
        <div class="card">
            @foreach ($page['monitors'] as $monitor)
                <div class="monitor">
                    <div class="row">
                        <span class="name"><span class="dot {{ $monitor['state'] }}"></span>{{ $monitor['name'] }}</span>
                        <span class="muted">{{ $fmt($monitor['uptime_90d']) }} uptime</span>
                    </div>
                    <div class="bars" role="img" aria-label="Daily uptime, last 90 days">
                        @foreach ($monitor['days'] as $day)
                            <span class="{{ $tone($day['uptime']) }}" title="{{ $day['date'] }} · {{ $day['uptime'] === null ? 'no data' : $fmt($day['uptime']) }}"></span>
                        @endforeach
                    </div>
                    <div class="row muted"><span>90 days ago</span><span>Today</span></div>
                </div>
            @endforeach
        </div>
    @endif

    <h2>Incidents, last {{ (int) config('uptime.status_page.incident_days', 14) }} days</h2>
    <div class="card">
        @if ($page['incidents'] === [])
            <p class="muted">No incidents.</p>
        @else
            <ul>
                @foreach ($page['incidents'] as $incident)
                    <li>
                        <div class="row">
                            <span class="name"><span class="dot {{ $incident['resolved_at'] ? 'up' : 'down' }}"></span>{{ $incident['name'] }}</span>
                            <span class="muted">{{ $incident['resolved_at'] ? 'Resolved' : 'Ongoing' }} · {{ $duration($incident['duration_seconds']) }}</span>
                        </div>
                        <p class="muted">Started {{ \Carbon\CarbonImmutable::parse($incident['started_at'])->format('M j, H:i') }} UTC</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <p class="muted" style="margin-top: 24px">Updated {{ \Carbon\CarbonImmutable::parse($page['updated_at'])->format('M j, H:i') }} UTC · checked every minute</p>
</main>
</body>
</html>
