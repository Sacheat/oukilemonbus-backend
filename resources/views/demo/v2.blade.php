<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bus Communal — Démo v2</title>
    @vite(['resources/js/demo/app.js'])
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
            --danger-soft: #fde8e4;
            --warning: #a15c07;
            --warning-soft: #fff3df;
            --ok: #23723a;
            --ok-soft: #e8f5ec;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font: 14px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
        }
        a { color: var(--brand); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* Layout */
        header {
            background: var(--panel);
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .bar {
            max-width: 1360px;
            margin: 0 auto;
            padding: 10px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .brand { font-weight: 750; font-size: 15px; letter-spacing: .01em; }
        nav { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; }
        nav a {
            border-radius: 6px;
            color: var(--muted);
            padding: 6px 10px;
            font-size: 13px;
        }
        nav a:hover { background: var(--bg); color: var(--text); text-decoration: none; }
        nav a.active { background: var(--brand-soft); color: var(--brand); font-weight: 650; }
        .user-bar { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        main { max-width: 1360px; margin: 0 auto; padding: 24px 20px; }
        h1 { font-size: 24px; margin: 0 0 6px; }
        h2 { font-size: 17px; margin: 0 0 12px; }
        h3 { font-size: 15px; margin: 0 0 10px; }
        .lede { color: var(--muted); margin: 0 0 20px; font-size: 13px; }

        /* Grid */
        .grid { display: grid; gap: 16px; }
        .grid-2 { grid-template-columns: repeat(2, 1fr); }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .grid-4 { grid-template-columns: repeat(4, 1fr); }
        @media (max-width: 900px) {
            .grid-2, .grid-3, .grid-4 { grid-template-columns: 1fr; }
        }

        /* Panel */
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
        }
        .metric-label { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
        .metric-value { font-size: 28px; font-weight: 760; margin-top: 2px; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border-bottom: 1px solid var(--line); padding: 9px 8px; text-align: left; vertical-align: middle; }
        th { color: var(--muted); font-size: 11px; font-weight: 650; text-transform: uppercase; }
        tr:last-child td { border-bottom: 0; }
        tbody tr:hover { background: var(--bg); }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: #eef2f7;
            color: #364152;
            font-size: 11px;
            font-weight: 650;
            padding: 2px 8px;
            white-space: nowrap;
        }
        .badge.ok { background: var(--ok-soft); color: var(--ok); }
        .badge.warning { background: var(--warning-soft); color: var(--warning); }
        .badge.danger { background: var(--danger-soft); color: var(--danger); }
        .badge.brand { background: var(--brand-soft); color: var(--brand); }
        .badge.muted { background: #f0f0f0; color: var(--muted); }

        /* Buttons */
        button, .btn {
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            color: var(--text);
            padding: 6px 12px;
            font: 13px/1 system-ui, sans-serif;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        button:hover { background: var(--bg); }
        button.primary, .btn.primary { background: var(--brand); border-color: var(--brand); color: #fff; }
        button.primary:hover { opacity: .9; }
        button.danger { border-color: #e5a39a; color: var(--danger); }
        button.danger:hover { background: var(--danger-soft); }
        button.sm { padding: 4px 8px; font-size: 12px; }
        button:disabled { opacity: .5; cursor: not-allowed; }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .form-grid .full { grid-column: 1 / -1; }
        @media (max-width: 640px) { .form-grid { grid-template-columns: 1fr; } }
        label { display: grid; gap: 4px; font-size: 12px; font-weight: 600; color: var(--muted); }
        input, select, textarea {
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            color: var(--text);
            padding: 7px 10px;
            font: 13px/1 system-ui, sans-serif;
            width: 100%;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--brand);
        }
        input.input-error, select.input-error, textarea.input-error {
            border-color: var(--danger);
            background: #fff8f7;
        }
        textarea { min-height: 72px; resize: vertical; }

        /* Actions row */
        .actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .split {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        /* Notices */
        .notice {
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
            background: var(--brand-soft);
            color: var(--brand);
            font-size: 13px;
        }
        .notice.error { background: var(--danger-soft); color: var(--danger); }
        .notice.warning { background: var(--warning-soft); color: var(--warning); }

        /* Role switcher dropdown */
        .switcher-wrap { position: relative; }
        .switcher-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 6px);
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,.12);
            z-index: 50;
            min-width: 230px;
            padding: 6px;
        }
        .switcher-dropdown.open { display: block; }
        .switcher-item {
            width: 100%;
            text-align: left;
            padding: 8px 10px;
            border-radius: 6px;
            border: none;
            background: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font: 13px/1.3 system-ui, sans-serif;
        }
        .switcher-item:hover { background: var(--bg); }
        .switcher-item.active { background: var(--brand-soft); }
        .switcher-item-label { font-weight: 650; }
        .switcher-item-desc { font-size: 11px; color: var(--muted); }

        /* Filters */
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
            margin-bottom: 16px;
        }
        .filters label { min-width: 120px; }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal {
            background: var(--panel);
            border-radius: 10px;
            width: 100%;
            max-width: 540px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 8px 40px rgba(0,0,0,.18);
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
        }
        .modal-header h3 { margin: 0; }
        .modal-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: var(--muted);
            padding: 0 4px;
        }
        .modal-body { padding: 16px; }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 12px 16px;
            border-top: 1px solid var(--line);
        }

        /* Login screen */
        .login-screen {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 32px;
            width: 100%;
            max-width: 440px;
        }
        .login-title { font-size: 22px; font-weight: 750; margin: 0 0 6px; }
        .role-switcher { display: grid; gap: 8px; margin: 20px 0; }
        .role-btn {
            width: 100%;
            text-align: left;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--panel);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .role-btn:hover { border-color: var(--brand); background: var(--brand-soft); }
        .role-btn .role-name { font-weight: 650; font-size: 13px; }
        .role-btn .role-label { font-size: 11px; color: var(--muted); }

        /* Loading */
        .loading { color: var(--muted); padding: 40px; text-align: center; font-size: 13px; }

        /* Misc */
        .muted { color: var(--muted); }
        .stack { display: grid; gap: 12px; }
        code { background: #eef2f7; border-radius: 4px; padding: 1px 5px; font-family: monospace; font-size: 12px; }

        /* Tabs */
        .tabs { display: flex; gap: 0; border-bottom: 1px solid var(--line); margin-bottom: 16px; }
        .tab {
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
        }
        .tab.active { color: var(--brand); border-bottom-color: var(--brand); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* Location autocomplete */
        .loc-wrap { position: relative; }
        .loc-input-row { display: flex; gap: 6px; }
        .loc-input-row input { flex: 1; }
        .loc-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 6px;
            box-shadow: 0 4px 16px rgba(0,0,0,.10);
            z-index: 30;
            max-height: 220px;
            overflow-y: auto;
            margin-top: 2px;
        }
        .loc-dropdown.open { display: block; }
        .loc-item {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 13px;
            border-bottom: 1px solid var(--line);
        }
        .loc-item:last-child { border-bottom: none; }
        .loc-item:hover { background: var(--bg); }
        .loc-item.selected { background: var(--brand-soft); }
        .loc-item-addr { font-weight: 600; }
        .loc-item-city { font-size: 11px; color: var(--muted); }
        .loc-item-create { color: var(--brand); font-style: italic; }
        .loc-selected-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--brand-soft);
            color: var(--brand);
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 4px;
        }
        .loc-selected-tag button {
            background: none;
            border: none;
            color: var(--brand);
            cursor: pointer;
            padding: 0 2px;
            min-height: auto;
            font-size: 14px;
            line-height: 1;
        }
        .field-error { color: var(--danger); font-size: 11px; margin-top: 3px; }

        /* Preview box */
        .preview-box {
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 14px;
            margin-top: 12px;
            font-size: 13px;
        }
        .preview-section { margin-bottom: 12px; }
        .preview-section:last-child { margin-bottom: 0; }
        .preview-section-title { font-weight: 650; font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 6px; }
        .conflict-item {
            border-left: 3px solid var(--danger);
            padding: 6px 10px;
            background: var(--danger-soft);
            border-radius: 0 4px 4px 0;
            font-size: 12px;
            margin-bottom: 4px;
        }
        .next-slot-box {
            background: var(--warning-soft);
            border: 1px solid #f0c060;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 12px;
        }

        /* Recurrence */
        .days-grid { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
        .day-toggle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 1px solid var(--line);
            background: var(--panel);
            cursor: pointer;
            font-size: 11px;
            font-weight: 650;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            min-height: auto;
        }
        .day-toggle.on { background: var(--brand); border-color: var(--brand); color: #fff; }
        .recurrence-preview { background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: 12px; margin-top: 10px; font-size: 12px; }
        .date-chip { display: inline-block; background: var(--ok-soft); color: var(--ok); border-radius: 4px; padding: 2px 6px; font-size: 11px; margin: 2px; }

        @media (max-width: 700px) {
            .bar { flex-direction: column; align-items: flex-start; }
            .user-bar { width: 100%; }
            main { padding: 16px; }
        }
    </style>
</head>
<body>
    <div id="app"></div>

@if(config('backend_inspector.enabled', false))
    {{-- ── Backend Inspector — floating shell window ───────────────────────── --}}
    <style>
        /* ── Toggle button ── */
        #insp-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9001;
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 8px 14px;
            background: #0f172a;
            color: #d1d5db;
            border: 1px solid #334155;
            border-radius: 999px;
            font: 600 12px/1 system-ui, sans-serif;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(0,0,0,.45);
            white-space: nowrap;
            transition: background .15s, box-shadow .15s;
            user-select: none;
        }
        #insp-toggle:hover { background: #1e293b; box-shadow: 0 6px 24px rgba(0,0,0,.55); }

        .ib-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #374151;
            flex-shrink: 0;
            transition: background .3s, box-shadow .3s;
        }
        .ib-dot.live { background: #22c55e; box-shadow: 0 0 7px #22c55e; }
        @keyframes ib-ping {
            0%   { box-shadow: 0 0 0 0 rgba(34,197,94,.6); }
            70%  { box-shadow: 0 0 0 8px transparent; }
            100% { box-shadow: 0 0 7px #22c55e; }
        }
        .ib-dot.ping { animation: ib-ping .75s ease-out; }

        /* ── Floating shell window ── */
        #insp-panel {
            position: fixed;
            z-index: 9000;
            width: 860px;
            height: 520px;
            min-width: 380px;
            min-height: 220px;
            display: flex;
            flex-direction: column;
            background: #0d1117;
            border: 1px solid #1e3a5f;
            border-radius: 12px;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 13px;
            color: #e5e7eb;
            box-shadow: 0 28px 80px rgba(0,0,0,.7), 0 0 0 1px rgba(255,255,255,.04);
            opacity: 0;
            pointer-events: none;
            transform: scale(.96) translateY(10px);
            transition: opacity .2s cubic-bezier(.4,0,.2,1), transform .2s cubic-bezier(.4,0,.2,1);
            overflow: hidden;
        }
        #insp-panel.open {
            opacity: 1;
            pointer-events: all;
            transform: scale(1) translateY(0);
        }

        /* ── Resize handles (8 directions) ── */
        .ib-rsz { position: absolute; z-index: 20; }
        .ib-rsz-n  { top: 0;    left: 8px;  right: 8px;  height: 5px; cursor: n-resize; }
        .ib-rsz-s  { bottom: 0; left: 8px;  right: 8px;  height: 5px; cursor: s-resize; }
        .ib-rsz-e  { right: 0;  top: 8px;   bottom: 8px; width: 5px;  cursor: e-resize; }
        .ib-rsz-w  { left: 0;   top: 8px;   bottom: 8px; width: 5px;  cursor: w-resize; }
        .ib-rsz-ne { top: 0;    right: 0;   width: 12px; height: 12px; cursor: ne-resize; }
        .ib-rsz-nw { top: 0;    left: 0;    width: 12px; height: 12px; cursor: nw-resize; }
        .ib-rsz-se { bottom: 0; right: 0;   width: 12px; height: 12px; cursor: se-resize; }
        .ib-rsz-sw { bottom: 0; left: 0;    width: 12px; height: 12px; cursor: sw-resize; }

        /* ── Title bar (drag handle) ── */
        .ib-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            background: #070e1a;
            border-bottom: 1px solid #1a2d47;
            border-radius: 12px 12px 0 0;
            flex-shrink: 0;
            cursor: move;
            user-select: none;
        }
        .ib-header-left { display: flex; align-items: center; gap: 10px; }
        .ib-header-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

        /* macOS-style traffic lights */
        .ib-traffic { display: flex; gap: 6px; align-items: center; margin-right: 4px; }
        .ib-tl {
            width: 12px; height: 12px;
            border-radius: 50%; border: none;
            cursor: pointer; padding: 0;
            font-size: 0; line-height: 1;
            transition: filter .15s;
            flex-shrink: 0;
        }
        .ib-tl:hover { filter: brightness(1.3); font-size: 8px; color: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; }
        .ib-tl-close { background: #ff5f57; }
        .ib-tl-min   { background: #febc2e; }
        .ib-tl-max   { background: #28c840; }

        .ib-title { font-size: 13px; font-weight: 650; color: #c9d1d9; letter-spacing: -.01em; }
        .ib-pill {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 10px; color: #9ca3af;
            background: #161b22; border: 1px solid #30363d;
            padding: 2px 8px; border-radius: 999px;
        }
        .ib-pill::before {
            content: ''; width: 5px; height: 5px;
            border-radius: 50%; background: #22c55e;
            box-shadow: 0 0 5px #22c55e;
        }
        .ib-refresh { font-size: 10px; color: #3d4b5c; }

        /* ── Scrollable body ── */
        .ib-body {
            flex: 1;
            overflow-y: auto;
            padding: 14px 16px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-auto-rows: max-content;
            gap: 12px;
            align-content: start;
        }
        .ib-body::-webkit-scrollbar { width: 5px; }
        .ib-body::-webkit-scrollbar-track { background: #0d1117; }
        .ib-body::-webkit-scrollbar-thumb { background: #21262d; border-radius: 999px; }

        /* Full-width items */
        .ib-col-full { grid-column: 1 / -1; }

        /* ── Cards ── */
        .ib-card {
            background: #161b22;
            border: 1px solid #21262d;
            border-radius: 10px;
            padding: 12px 14px;
        }
        .ib-card-title {
            font-size: 10px; font-weight: 700;
            color: #484f58;
            text-transform: uppercase; letter-spacing: .08em;
            margin: 0 0 10px;
        }
        .ib-card-sep { border: none; border-top: 1px solid #21262d; margin: 10px 0; }

        /* Action card */
        .ib-action-title {
            font-size: 16px; font-weight: 800;
            letter-spacing: -.03em; color: #e6edf3;
            margin-bottom: 5px; line-height: 1.3;
        }
        .ib-key {
            display: inline-block;
            font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
            font-size: 11px; color: #58a6ff;
            background: rgba(56,139,253,.1);
            border: 1px solid rgba(56,139,253,.25);
            padding: 3px 8px; border-radius: 6px;
            margin-bottom: 7px;
        }
        .ib-goal { color: #8b949e; font-size: 12px; line-height: 1.5; margin: 0 0 8px; }

        /* KV grid */
        .ib-kv {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 5px 10px;
            font-size: 11px;
        }
        .ib-kv .k { color: #484f58; }
        .ib-kv .v { color: #c9d1d9; word-break: break-all; font-family: ui-monospace, monospace; font-size: 10px; }

        /* Chips */
        .ib-chips { display: flex; flex-wrap: wrap; gap: 5px; }
        .ib-chip {
            display: inline-flex; align-items: center;
            background: #21262d; border: 1px solid #30363d;
            border-radius: 999px; padding: 3px 8px;
            font-size: 11px; color: #c9d1d9;
        }
        .ib-chip.accent {
            color: #58a6ff; border-color: rgba(56,139,253,.3);
            background: rgba(56,139,253,.1);
        }

        /* List */
        .ib-list { margin: 0; padding-left: 14px; color: #c9d1d9; }
        .ib-list li { margin: 4px 0; font-size: 11px; }

        /* Timeline */
        .ib-tl-item {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 0;
            border-bottom: 1px solid #161b22;
            font-size: 11px;
        }
        .ib-tl-item:last-child { border-bottom: 0; padding-bottom: 0; }
        .ib-tl-dot { width: 5px; height: 5px; border-radius: 50%; background: #21262d; flex-shrink: 0; }
        .ib-tl-item.ib-tl-cur .ib-tl-dot { background: #22c55e; box-shadow: 0 0 4px #22c55e; }
        .ib-tl-cat {
            font-size: 9px; padding: 2px 6px; border-radius: 999px;
            background: #21262d; color: #484f58; flex-shrink: 0;
        }
        .ib-tl-item.ib-tl-cur .ib-tl-cat { background: rgba(56,139,253,.12); color: #58a6ff; }
        .ib-tl-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #8b949e; }
        .ib-tl-item.ib-tl-cur .ib-tl-name { color: #e6edf3; }
        .ib-tl-time { font-size: 9px; color: #30363d; flex-shrink: 0; }

        /* Collapsible */
        .ib-details { border-radius: 10px; overflow: hidden; }
        .ib-details summary {
            cursor: pointer; padding: 10px 14px;
            background: #161b22; border: 1px solid #21262d;
            border-radius: 10px;
            font-size: 10px; font-weight: 700; color: #484f58;
            text-transform: uppercase; letter-spacing: .08em;
            list-style: none;
            display: flex; align-items: center; gap: 6px;
        }
        .ib-details[open] summary { border-radius: 10px 10px 0 0; border-bottom: 1px solid #21262d; }
        .ib-details summary::before { content: '▸'; font-size: 9px; }
        .ib-details[open] summary::before { content: '▾'; }
        .ib-details-body {
            padding: 12px 14px;
            background: #161b22;
            border: 1px solid #21262d; border-top: 0;
            border-radius: 0 0 10px 10px;
        }

        /* Permissions */
        .ib-perm-chips { display: flex; flex-wrap: wrap; gap: 4px; }
        .ib-perm-chip {
            font-size: 9px; padding: 2px 6px;
            background: #0d1117; border: 1px solid #21262d;
            border-radius: 999px; color: #484f58;
        }

        pre.ib-pre {
            margin: 0; padding: 10px;
            background: #010409; border: 1px solid #21262d;
            border-radius: 6px; color: #8b949e;
            font-size: 10px; overflow: auto;
            max-height: 200px;
            font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
        }

        /* ── Status bar ── */
        .ib-statusbar {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 5px 14px;
            background: #040810;
            border-top: 1px solid #1a2d47;
            border-radius: 0 0 12px 12px;
            font-size: 10px;
            color: #3d4b5c;
            flex-shrink: 0;
        }
        .ib-statusbar span { display: flex; align-items: center; gap: 5px; }

        @media (max-width: 600px) {
            #insp-panel { width: calc(100vw - 16px) !important; left: 8px !important; }
            .ib-body { grid-template-columns: 1fr; }
        }
    </style>

    {{-- Toggle button --}}
    <button id="insp-toggle" type="button">
        <span class="ib-dot" id="ib-dot"></span>
        ⚙ Inspector
    </button>

    {{-- Floating shell window --}}
    <div id="insp-panel" role="complementary" aria-label="Backend Inspector">

        {{-- Resize handles (8 directions) --}}
        <div class="ib-rsz ib-rsz-n"  data-dir="n"></div>
        <div class="ib-rsz ib-rsz-s"  data-dir="s"></div>
        <div class="ib-rsz ib-rsz-e"  data-dir="e"></div>
        <div class="ib-rsz ib-rsz-w"  data-dir="w"></div>
        <div class="ib-rsz ib-rsz-ne" data-dir="ne"></div>
        <div class="ib-rsz ib-rsz-nw" data-dir="nw"></div>
        <div class="ib-rsz ib-rsz-se" data-dir="se"></div>
        <div class="ib-rsz ib-rsz-sw" data-dir="sw"></div>

        {{-- Title bar --}}
        <header class="ib-header" id="ib-drag-handle">
            <div class="ib-header-left">
                <div class="ib-traffic">
                    <button class="ib-tl ib-tl-close" id="ib-close" title="Fermer">✕</button>
                    <button class="ib-tl ib-tl-min"   id="ib-minimize" title="Réduire">–</button>
                    <button class="ib-tl ib-tl-max"   id="ib-maximize" title="Agrandir">+</button>
                </div>
                <span class="ib-dot" id="ib-dot"></span>
                <span class="ib-title">Backend Inspector</span>
                <span class="ib-pill" id="ib-status-pill">actif</span>
            </div>
            <div class="ib-header-right">
                <span class="ib-refresh" id="ib-refresh">—</span>
            </div>
        </header>

        {{-- Content grid --}}
        <div class="ib-body">

            {{-- Last action (full width) --}}
            <div class="ib-card ib-col-full" id="ib-action-card" style="border-color:rgba(56,139,253,.2);background:linear-gradient(135deg,rgba(56,139,253,.07),rgba(22,27,34,.98))">
                <p class="ib-card-title">⚙ Dernière action backend</p>
                <div id="ib-action-title" class="ib-action-title">Aucune action</div>
                <div id="ib-action-key"   class="ib-key">—</div>
                <p   id="ib-action-goal"  class="ib-goal"></p>
                <div class="ib-kv" id="ib-action-kv"></div>
            </div>

            {{-- Actor --}}
            <div class="ib-card">
                <p class="ib-card-title">👤 Acteur courant</p>
                <div class="ib-kv" id="ib-actor-kv"></div>
                <hr class="ib-card-sep">
                <div style="font-size:10px;color:#484f58;margin-bottom:5px;text-transform:uppercase;letter-spacing:.07em">Rôles</div>
                <div class="ib-chips" id="ib-roles"></div>
                <div style="font-size:10px;color:#484f58;margin:8px 0 5px;text-transform:uppercase;letter-spacing:.07em">Capacités</div>
                <div class="ib-chips" id="ib-caps"></div>
            </div>

            {{-- Tables + Effects --}}
            <div class="ib-card">
                <p class="ib-card-title">🗄 Tables concernées</p>
                <div class="ib-chips" id="ib-tables"></div>
                <hr class="ib-card-sep">
                <div style="font-size:10px;color:#484f58;margin-bottom:6px;text-transform:uppercase;letter-spacing:.07em">Effets attendus</div>
                <ul class="ib-list" id="ib-effects"></ul>
            </div>

            {{-- Timeline (full width) --}}
            <div class="ib-card ib-col-full">
                <p class="ib-card-title">📜 Timeline de session</p>
                <div id="ib-timeline"><span style="color:#30363d;font-size:11px">Aucune action cette session.</span></div>
            </div>

            {{-- Permissions (collapsed, full width) --}}
            <details class="ib-details ib-col-full">
                <summary>🔐 Permissions</summary>
                <div class="ib-details-body">
                    <div class="ib-perm-chips" id="ib-permissions"></div>
                </div>
            </details>

            {{-- Context + JSON (collapsed, full width) --}}
            <details class="ib-details ib-col-full">
                <summary>📌 Contexte &amp; JSON brut</summary>
                <div class="ib-details-body">
                    <div style="font-size:10px;color:#484f58;margin-bottom:6px;text-transform:uppercase;letter-spacing:.07em">Contexte enregistré</div>
                    <div class="ib-kv" id="ib-ctx-kv" style="margin-bottom:12px"></div>
                    <pre class="ib-pre" id="ib-raw"></pre>
                </div>
            </details>

        </div>

        {{-- Status bar --}}
        <div class="ib-statusbar">
            <span><span class="ib-dot live" style="width:5px;height:5px"></span> Live · 2s</span>
            <span id="ib-sb-action">—</span>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        const STORAGE_KEY = 'bus_inspector_win';
        const MIN_W = 380, MIN_H = 220;

        let _open    = false;
        let _prevKey = null;
        let _timer   = null;
        let _minimized = false;
        let _savedHeight = null;

        const $   = (id) => document.getElementById(id);
        const esc = (s)  => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        const ago = (ds) => {
            if (!ds) return '';
            const d = Math.floor((Date.now() - new Date(ds).getTime()) / 1000);
            if (d < 5)    return 'maintenant';
            if (d < 60)   return d + 's';
            if (d < 3600) return Math.floor(d / 60) + 'm';
            return Math.floor(d / 3600) + 'h';
        };
        const fmtVal = (v) => {
            if (v === null || v === undefined || v === '') return '—';
            if (typeof v === 'boolean') return v ? 'Oui' : 'Non';
            if (typeof v === 'object')  return JSON.stringify(v);
            return v;
        };

        // ── Persist window geometry ──────────────────────────────────────────
        function saveGeometry() {
            const p = $('insp-panel');
            const r = p.getBoundingClientRect();
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify({
                    left: r.left, top: r.top, width: r.width, height: r.height
                }));
            } catch (_) {}
        }

        function restoreGeometry() {
            const p = $('insp-panel');
            try {
                const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
                if (saved && saved.width >= MIN_W && saved.height >= MIN_H) {
                    const maxL = window.innerWidth  - Math.min(saved.width,  window.innerWidth);
                    const maxT = window.innerHeight - Math.min(saved.height, window.innerHeight);
                    p.style.left   = Math.max(0, Math.min(saved.left, maxL)) + 'px';
                    p.style.top    = Math.max(0, Math.min(saved.top,  maxT)) + 'px';
                    p.style.width  = Math.min(saved.width,  window.innerWidth)  + 'px';
                    p.style.height = Math.min(saved.height, window.innerHeight) + 'px';
                    return;
                }
            } catch (_) {}
            // Default: centered, lower third
            const w = Math.min(860, window.innerWidth  - 32);
            const h = Math.min(520, window.innerHeight - 80);
            p.style.width  = w + 'px';
            p.style.height = h + 'px';
            p.style.left   = Math.max(0, (window.innerWidth  - w) / 2) + 'px';
            p.style.top    = Math.max(0, (window.innerHeight - h) / 2) + 'px';
        }

        // ── Drag ────────────────────────────────────────────────────────────
        function initDrag() {
            const panel  = $('insp-panel');
            const handle = $('ib-drag-handle');
            let dragging = false, sx, sy, sl, st;

            handle.addEventListener('mousedown', (e) => {
                if (e.target.closest('.ib-tl')) return;
                dragging = true;
                sx = e.clientX; sy = e.clientY;
                const r = panel.getBoundingClientRect();
                sl = r.left; st = r.top;
                document.body.style.userSelect = 'none';
                document.body.style.cursor = 'move';
                e.preventDefault();
            });

            document.addEventListener('mousemove', (e) => {
                if (!dragging) return;
                const nl = sl + (e.clientX - sx);
                const nt = st + (e.clientY - sy);
                panel.style.left   = Math.max(0, Math.min(nl, window.innerWidth  - panel.offsetWidth))  + 'px';
                panel.style.top    = Math.max(0, Math.min(nt, window.innerHeight - 40)) + 'px';
                panel.style.right  = 'auto';
                panel.style.bottom = 'auto';
            });

            document.addEventListener('mouseup', () => {
                if (!dragging) return;
                dragging = false;
                document.body.style.userSelect = '';
                document.body.style.cursor = '';
                saveGeometry();
            });
        }

        // ── Resize ──────────────────────────────────────────────────────────
        function initResize() {
            const panel = $('insp-panel');

            document.querySelectorAll('.ib-rsz').forEach((handle) => {
                handle.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const dir = handle.dataset.dir;
                    const sx = e.clientX, sy = e.clientY;
                    const r  = panel.getBoundingClientRect();
                    const sl = r.left, st = r.top, sw = r.width, sh = r.height;

                    document.body.style.userSelect = 'none';
                    document.body.style.cursor = getComputedStyle(handle).cursor;

                    function onMove(e) {
                        const dx = e.clientX - sx, dy = e.clientY - sy;
                        let nl = sl, nt = st, nw = sw, nh = sh;

                        if (dir.includes('e')) nw = Math.max(MIN_W, sw + dx);
                        if (dir.includes('w')) { nw = Math.max(MIN_W, sw - dx); nl = sl + sw - nw; }
                        if (dir.includes('s')) nh = Math.max(MIN_H, sh + dy);
                        if (dir.includes('n')) { nh = Math.max(MIN_H, sh - dy); nt = st + sh - nh; }

                        panel.style.left   = Math.max(0, nl) + 'px';
                        panel.style.top    = Math.max(0, nt) + 'px';
                        panel.style.width  = nw + 'px';
                        panel.style.height = nh + 'px';
                        panel.style.right  = 'auto';
                        panel.style.bottom = 'auto';
                    }

                    function onUp() {
                        document.body.style.userSelect = '';
                        document.body.style.cursor = '';
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        saveGeometry();
                    }

                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', onUp);
                });
            });
        }

        // ── Minimize / Maximize ──────────────────────────────────────────────
        function toggleMinimize() {
            const panel = $('insp-panel');
            if (!_minimized) {
                _savedHeight = panel.offsetHeight;
                const headerH = $('ib-drag-handle').offsetHeight + ($('insp-panel').querySelector('.ib-statusbar')?.offsetHeight ?? 0) + 2;
                panel.style.height = headerH + 'px';
                panel.style.overflow = 'hidden';
                _minimized = true;
            } else {
                panel.style.height = (_savedHeight ?? 520) + 'px';
                panel.style.overflow = 'hidden';
                _minimized = false;
                saveGeometry();
            }
        }

        function toggleMaximize() {
            const panel = $('insp-panel');
            const r = panel.getBoundingClientRect();
            const isMax = r.width >= window.innerWidth - 10 && r.top <= 2;
            if (!isMax) {
                saveGeometry();
                panel.style.left   = '0';
                panel.style.top    = '0';
                panel.style.width  = window.innerWidth  + 'px';
                panel.style.height = window.innerHeight + 'px';
            } else {
                restoreGeometry();
            }
        }

        // ── Render helpers ───────────────────────────────────────────────────
        function kv(targetId, pairs) {
            const el = $(targetId);
            if (!el) return;
            const entries = Object.entries(pairs || {}).filter(([,v]) => v !== null && v !== undefined);
            if (!entries.length) { el.innerHTML = '<span style="color:#30363d;font-size:11px">—</span>'; return; }
            el.innerHTML = entries.map(([k, v]) =>
                `<div class="k">${esc(k)}</div><div class="v">${esc(fmtVal(v))}</div>`
            ).join('');
        }

        function chips(targetId, items, accent) {
            const el = $(targetId);
            if (!el) return;
            if (!Array.isArray(items) || !items.length) { el.innerHTML = '<span style="color:#30363d;font-size:11px">—</span>'; return; }
            el.innerHTML = items.map(item => `<span class="ib-chip${accent ? ' accent' : ''}">${esc(item)}</span>`).join('');
        }

        function permChips(targetId, items) {
            const el = $(targetId);
            if (!el) return;
            if (!Array.isArray(items) || !items.length) { el.innerHTML = '<span style="color:#30363d;font-size:11px">—</span>'; return; }
            el.innerHTML = items.map(item => `<span class="ib-perm-chip">${esc(item)}</span>`).join('');
        }

        function listEl(targetId, items) {
            const el = $(targetId);
            if (!el) return;
            if (!Array.isArray(items) || !items.length) { el.innerHTML = '<li style="color:#30363d">—</li>'; return; }
            el.innerHTML = items.map(item => `<li>${esc(item)}</li>`).join('');
        }

        function ctrlLabel(c, fallback) {
            if (!c) return fallback ?? '—';
            return `${c.class ?? '—'}::${c.method ?? '—'}`;
        }

        function pulseDot(key) {
            const dot = $('ib-dot');
            if (!dot) return;
            dot.className = key ? 'ib-dot live' : 'ib-dot';
            if (key && _prevKey !== key) {
                dot.classList.add('ping');
                setTimeout(() => dot.classList.remove('ping'), 800);
            }
        }

        // ── Render ───────────────────────────────────────────────────────────
        function render(data) {
            const actor   = data.actor        || {};
            const action  = data.last_action  || {};
            const history = data.history      || [];
            const key     = data.last_action_key ?? null;
            const hasDoc  = Object.keys(action).length > 0;
            const route   = action.route      || {};
            const svc     = action.service    || {};

            pulseDot(key);
            _prevKey = key;

            const refreshEl = $('ib-refresh');
            if (refreshEl) refreshEl.textContent = new Date().toLocaleTimeString('fr-BE');

            const sbAction = $('ib-sb-action');
            if (sbAction) sbAction.textContent = key ? key : '— aucune action';

            // Last action
            if ($('ib-action-title')) {
                if (!key) {
                    $('ib-action-title').textContent = 'Aucune action enregistrée';
                    $('ib-action-key').textContent   = '—';
                    $('ib-action-goal').textContent  = 'Effectuez une action dans la démo pour voir les détails ici.';
                } else {
                    $('ib-action-title').textContent = hasDoc ? (action.title ?? key) : 'Action non documentée';
                    $('ib-action-key').textContent   = key;
                    $('ib-action-goal').textContent  = hasDoc ? (action.goal ?? '') : 'Fiche manquante dans config/backend_inspector.php.';
                }
            }

            kv('ib-action-kv', hasDoc ? {
                'Démo':              route.demo  || '—',
                'API':               route.api   || '—',
                'Contrôleur API':    ctrlLabel(action.api_controller, 'Aucun'),
                'Service':           svc.class   ? `${svc.class}::${svc.method ?? '?'}` : '—',
                'Méthodes internes': Array.isArray(svc.internal_methods) ? svc.internal_methods.join(' → ') : '—',
            } : {});

            kv('ib-actor-kv', {
                'Nom':          actor.name                  || '—',
                'Email':        actor.email                 || '—',
                'Organisation': actor.organization?.name   || '—',
            });
            chips('ib-roles', actor.roles);
            chips('ib-caps',  actor.capabilities, true);

            const tlEl = $('ib-timeline');
            if (tlEl) {
                if (!history.length) {
                    tlEl.innerHTML = '<span style="color:#30363d;font-size:11px">Aucune action cette session.</span>';
                } else {
                    tlEl.innerHTML = history.map((item, i) => `
                        <div class="ib-tl-item ${i === 0 ? 'ib-tl-cur' : ''}">
                            <span class="ib-tl-dot"></span>
                            <span class="ib-tl-cat">${esc(item.action?.category ?? '?')}</span>
                            <span class="ib-tl-name">${esc(item.action?.title || item.action_key)}</span>
                            <span class="ib-tl-time">${esc(ago(item.recorded_at))}</span>
                        </div>
                    `).join('');
                }
            }

            chips('ib-tables', action.tables);
            listEl('ib-effects', action.effects);
            permChips('ib-permissions', actor.permissions);
            kv('ib-ctx-kv', data.last_context || {});
            const rawEl = $('ib-raw');
            if (rawEl) rawEl.textContent = JSON.stringify(data, null, 2);
        }

        // ── Fetch ────────────────────────────────────────────────────────────
        async function fetchState() {
            try {
                const res  = await fetch('/demo/inspector/state', { headers: { Accept: 'application/json' } });
                const data = await res.json();
                render(data);
            } catch (_) { /* silent */ }
        }

        // ── Open / Close ─────────────────────────────────────────────────────
        function openPanel() {
            _open = true;
            restoreGeometry();
            $('insp-panel').classList.add('open');
            const dot = $('ib-dot');
            if (dot) dot.classList.add('live');
            fetchState();
            _timer = setInterval(fetchState, 2000);
        }

        function closePanel() {
            _open = false;
            $('insp-panel').classList.remove('open');
            const dot = $('ib-dot');
            if (dot) dot.classList.remove('live');
            clearInterval(_timer);
            _timer = null;
        }

        // ── Boot ─────────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            initDrag();
            initResize();

            $('insp-toggle')?.addEventListener('click', () => _open ? closePanel() : openPanel());
            $('ib-close')?.addEventListener('click', closePanel);
            $('ib-minimize')?.addEventListener('click', (e) => { e.stopPropagation(); toggleMinimize(); });
            $('ib-maximize')?.addEventListener('click', (e) => { e.stopPropagation(); toggleMaximize(); });
        });
    })();
    </script>
@endif
</body>
</html>