<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Demo backend' }} - Bus communal</title>
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --line: #d7dde5;
            --brand: #0f766e;
            --brand-soft: #e8f5f2;
            --danger: #b42318;
            --warning: #a15c07;
            --ok: #23723a;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.45 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        a { color: var(--brand); text-decoration: none; }
        a:hover { text-decoration: underline; }
        header {
            background: var(--panel);
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .bar, main { max-width: 1320px; margin: 0 auto; padding: 16px 20px; }
        .bar { display: flex; align-items: center; gap: 18px; justify-content: space-between; }
        .brand { font-weight: 750; letter-spacing: .01em; }
        nav { display: flex; gap: 6px; flex-wrap: wrap; }
        nav a {
            border-radius: 6px;
            color: var(--text);
            padding: 7px 10px;
        }
        nav a.active { background: var(--brand-soft); color: var(--brand); font-weight: 650; }
        .actor { display: flex; align-items: center; gap: 8px; color: var(--muted); }

        main { padding-top: 24px; }
        h1 { font-size: 26px; margin: 0 0 8px; }
        h2 { font-size: 18px; margin: 0 0 12px; }
        .lede { color: var(--muted); margin: 0 0 20px; }
        .grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 16px; }
        .span-3 { grid-column: span 3; }
        .span-4 { grid-column: span 4; }
        .span-6 { grid-column: span 6; }
        .span-8 { grid-column: span 8; }
        .span-12 { grid-column: span 12; }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
        }
        .metric { color: var(--muted); font-size: 12px; text-transform: uppercase; }
        .metric-value { font-size: 30px; font-weight: 760; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid var(--line); padding: 10px 8px; text-align: left; vertical-align: top; }
        th { color: var(--muted); font-size: 12px; font-weight: 650; text-transform: uppercase; }
        tr:last-child td { border-bottom: 0; }
        .muted { color: var(--muted); }
        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: #eef2f7;
            color: #364152;
            font-size: 12px;
            font-weight: 650;
            padding: 3px 8px;
            white-space: nowrap;
        }
        .badge.ok { background: #e8f5ec; color: var(--ok); }
        .badge.warning { background: #fff3df; color: var(--warning); }
        .badge.danger { background: #fde8e4; color: var(--danger); }
        .badge.brand { background: var(--brand-soft); color: var(--brand); }
        .actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .stack { display: grid; gap: 12px; }
        .split { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; justify-content: space-between; }
        .inline { display: inline-flex; gap: 6px; align-items: center; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(180px, 1fr)); gap: 12px; }
        .form-grid .full { grid-column: 1 / -1; }
        textarea { min-height: 74px; resize: vertical; }
        code { background: #eef2f7; border-radius: 4px; padding: 1px 4px; }
        button, .button, select, input {
            border: 1px solid var(--line);
            border-radius: 6px;
            background: #fff;
            color: var(--text);
            min-height: 34px;
            padding: 6px 9px;
            font: inherit;
        }
        button, .button { cursor: pointer; font-weight: 650; }
        button.primary, .button.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        button.danger { border-color: #e5a39a; color: var(--danger); }
        .notice {
            border-radius: 8px;
            margin-bottom: 16px;
            padding: 12px 14px;
            background: var(--brand-soft);
            color: var(--brand);
        }
        .notice.error { background: #fde8e4; color: var(--danger); }
        .filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; margin-bottom: 16px; }
        label { display: grid; gap: 4px; color: var(--muted); font-size: 12px; font-weight: 650; }
        .pagination { margin-top: 14px; }

        @media (max-width: 860px) {
            .bar { align-items: flex-start; flex-direction: column; }
            .actor { width: 100%; align-items: flex-start; flex-direction: column; }
            .grid { grid-template-columns: 1fr; }
            .span-3, .span-4, .span-6, .span-8, .span-12 { grid-column: span 1; }
            table { display: block; overflow-x: auto; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<header>
    <div class="bar">
        <div>
            <div class="brand">OuKiLeMonBus - demo backend</div>
            <nav aria-label="Navigation demo">
                <a class="{{ request()->routeIs('demo.dashboard') ? 'active' : '' }}" href="{{ route('demo.dashboard') }}">Dashboard</a>
                @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('viewAny', \App\Models\Reservation::class))
                    <a class="{{ request()->routeIs('demo.reservations*') ? 'active' : '' }}" href="{{ route('demo.reservations') }}">Reservations</a>
                    <a class="{{ request()->routeIs('demo.planning') ? 'active' : '' }}" href="{{ route('demo.planning') }}">Planning</a>
                @endif
                @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('viewAny', \App\Models\Organization::class))
                    <a class="{{ request()->routeIs('demo.organizations') ? 'active' : '' }}" href="{{ route('demo.organizations') }}">Organisations</a>
                @endif
                @if ($actor->can('org-registration-requests.view') || $actor->can('org-registration-requests.create') || $actor->can('user-org-requests.view') || $actor->can('user-org-requests.create'))
                    <a class="{{ request()->routeIs('demo.requests') ? 'active' : '' }}" href="{{ route('demo.requests') }}">Demandes</a>
                @endif
                @if (\Illuminate\Support\Facades\Gate::forUser($actor)->allows('viewAny', \App\Models\Document::class))
                    <a class="{{ request()->routeIs('demo.documents') ? 'active' : '' }}" href="{{ route('demo.documents') }}">Documents</a>
                @endif
                <a class="{{ request()->routeIs('demo.notifications') ? 'active' : '' }}" href="{{ route('demo.notifications') }}">Notifications</a>
                @if ($actor->can('users.view') || $actor->can('drivers.view') || $actor->can('vehicles.view') || $actor->can('unavailabilities.view'))
                    <a class="{{ request()->routeIs('demo.admin') ? 'active' : '' }}" href="{{ route('demo.admin') }}">Gestion</a>
                @endif
            </nav>
        </div>
        <form class="actor" method="post" action="{{ route('demo.actor') }}">
            @csrf
            <span>Acteur</span>
            <select name="user_id" onchange="this.form.submit()">
                @foreach ($demoUsers as $demoUser)
                    <option value="{{ $demoUser->id }}" @selected($actor->id === $demoUser->id)>
                        #{{ $demoUser->id }} {{ $demoUser->first_name }} {{ $demoUser->last_name }} - {{ $demoUser->roles->pluck('name')->join(', ') }}
                    </option>
                @endforeach
            </select>
        </form>
    </div>
</header>
<main>
    @if (session('status'))
        <div class="notice">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    @if ($errors->any())
        <div class="notice error">{{ $errors->first() }}</div>
    @endif

    @yield('content')
</main>
</body>
</html>
