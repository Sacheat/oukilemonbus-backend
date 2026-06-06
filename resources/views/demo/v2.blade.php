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
    {{-- ── Backend Inspector side panel ───────────────────────────────────── --}}
    <style>
        /* ── Variables ── */
        #insp-panel, #insp-toggle {
            --ib:  #0f172a;
            --ip:  #111827;
            --ips: #1f2937;
            --ibd: #1e3a5f;
            --itx: #e5e7eb;
            --imt: #9ca3af;
            --idk: #6b7280;
            --iac: #38bdf8;
            --ias: rgba(56,189,248,.12);
            --iok: #22c55e;
        }

        /* ── Toggle button (fixed bottom-right) ── */
        #insp-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9001;
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 9px 16px;
            background: #0f172a;
            color: #d1d5db;
            border: 1px solid #334155;
            border-radius: 999px;
            font: 600 12px/1 system-ui, sans-serif;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(0,0,0,.4);
            white-space: nowrap;
            transition: transform .15s, background .15s;
        }
        #insp-toggle:hover { background: #1e293b; transform: translateY(-1px); }

        .ib-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #374151;
            flex-shrink: 0;
            transition: background .3s, box-shadow .3s;
        }
        .ib-dot.live { background: #22c55e; box-shadow: 0 0 7px #22c55e; }
        @keyframes ib-ping {
            0%  { box-shadow: 0 0 0 0 rgba(34,197,94,.6); }
            70% { box-shadow: 0 0 0 8px transparent; }
            100%{ box-shadow: 0 0 7px #22c55e; }
        }
        .ib-dot.ping { animation: ib-ping .75s ease-out; }

        /* ── Side panel ── */
        #insp-panel {
            position: fixed;
            top: 0; right: 0;
            width: 460px;
            height: 100vh;
            background: #0f172a;
            border-left: 1px solid #1e3a5f;
            z-index: 9000;
            display: flex;
            flex-direction: column;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 13px;
            color: #e5e7eb;
            transform: translateX(100%);
            transition: transform .25s cubic-bezier(.4,0,.2,1);
            box-shadow: -6px 0 32px rgba(0,0,0,.5);
        }
        #insp-panel.open { transform: translateX(0); }

        /* ── Panel header ── */
        .ib-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: #070e1a;
            border-bottom: 1px solid #1e3a5f;
            flex-shrink: 0;
        }
        .ib-header-left { display: flex; align-items: center; gap: 10px; }
        .ib-title { font-size: 14px; font-weight: 750; color: #e5e7eb; letter-spacing: -.01em; }
        .ib-pill {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; color: #9ca3af;
            background: #1f2937; border: 1px solid #334155;
            padding: 3px 9px; border-radius: 999px;
        }
        .ib-pill::before {
            content: ''; width: 6px; height: 6px;
            border-radius: 50%; background: #22c55e;
            box-shadow: 0 0 6px #22c55e;
        }
        .ib-refresh { font-size: 10px; color: #4b5563; }
        .ib-close {
            background: none; border: none; cursor: pointer;
            color: #6b7280; font-size: 18px; padding: 0 4px; line-height: 1;
        }
        .ib-close:hover { color: #e5e7eb; }

        /* ── Scrollable content ── */
        .ib-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .ib-body::-webkit-scrollbar { width: 5px; }
        .ib-body::-webkit-scrollbar-track { background: #0f172a; }
        .ib-body::-webkit-scrollbar-thumb { background: #334155; border-radius: 999px; }

        /* ── Cards ── */
        .ib-card {
            background: rgba(17,24,39,.88);
            border: 1px solid #1e2d3d;
            border-radius: 12px;
            padding: 14px 16px;
        }
        .ib-card-title {
            font-size: 12px; font-weight: 700;
            color: #6b7280;
            text-transform: uppercase; letter-spacing: .07em;
            margin: 0 0 12px;
        }
        .ib-card-sep { border: none; border-top: 1px solid #1f2937; margin: 12px 0; }

        /* Action card */
        .ib-action-title {
            font-size: 18px; font-weight: 800;
            letter-spacing: -.03em; color: #e5e7eb;
            margin-bottom: 6px; line-height: 1.25;
        }
        .ib-key {
            display: inline-block;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 11px; color: #38bdf8;
            background: rgba(56,189,248,.12);
            border: 1px solid rgba(56,189,248,.25);
            padding: 4px 9px; border-radius: 8px;
            margin-bottom: 8px;
        }
        .ib-goal { color: #9ca3af; font-size: 12px; line-height: 1.5; margin: 0 0 8px; }

        /* KV grid */
        .ib-kv {
            display: grid;
            grid-template-columns: 130px 1fr;
            gap: 6px 12px;
            font-size: 12px;
        }
        .ib-kv .k { color: #6b7280; }
        .ib-kv .v { color: #e5e7eb; word-break: break-word; }
        .ib-kv-label {
            font-size: 10px; font-weight: 700; color: #4b5563;
            text-transform: uppercase; letter-spacing: .07em;
            grid-column: 1/-1; margin-top: 8px; padding-top: 8px;
            border-top: 1px solid #1f2937;
        }

        /* Chips */
        .ib-chips { display: flex; flex-wrap: wrap; gap: 6px; }
        .ib-chip {
            display: inline-flex; align-items: center;
            background: #1f2937; border: 1px solid #334155;
            border-radius: 999px; padding: 4px 9px;
            font-size: 11px; color: #e5e7eb;
        }
        .ib-chip.accent {
            color: #38bdf8; border-color: rgba(56,189,248,.3);
            background: rgba(56,189,248,.1);
        }

        /* List */
        .ib-list { margin: 0; padding-left: 16px; color: #e5e7eb; }
        .ib-list li { margin: 5px 0; font-size: 12px; }

        /* Timeline */
        .ib-tl-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #1a2335;
            font-size: 12px;
        }
        .ib-tl-item:last-child { border-bottom: 0; padding-bottom: 0; }
        .ib-tl-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: #374151; flex-shrink: 0;
        }
        .ib-tl-item.ib-tl-cur .ib-tl-dot { background: #22c55e; box-shadow: 0 0 5px #22c55e; }
        .ib-tl-item.ib-tl-cur { color: #e5e7eb; }
        .ib-tl-cat {
            font-size: 10px; padding: 2px 7px; border-radius: 999px;
            background: #1f2937; color: #6b7280; flex-shrink: 0;
        }
        .ib-tl-item.ib-tl-cur .ib-tl-cat { background: rgba(56,189,248,.12); color: #38bdf8; }
        .ib-tl-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ib-tl-time { font-size: 10px; color: #4b5563; flex-shrink: 0; }

        /* Collapsible */
        .ib-details { border-radius: 12px; overflow: hidden; }
        .ib-details summary {
            cursor: pointer; padding: 12px 16px;
            background: rgba(17,24,39,.88); border: 1px solid #1e2d3d;
            border-radius: 12px;
            font-size: 12px; font-weight: 700; color: #6b7280;
            text-transform: uppercase; letter-spacing: .07em;
            list-style: none;
            display: flex; align-items: center; gap: 6px;
        }
        .ib-details[open] summary { border-radius: 12px 12px 0 0; border-bottom: 1px solid #1f2937; }
        .ib-details summary::before { content: '▸'; font-size: 10px; }
        .ib-details[open] summary::before { content: '▾'; }
        .ib-details-body {
            padding: 14px 16px;
            background: rgba(17,24,39,.88);
            border: 1px solid #1e2d3d; border-top: 0;
            border-radius: 0 0 12px 12px;
        }

        /* Permissions: smaller chips */
        .ib-perm-chips { display: flex; flex-wrap: wrap; gap: 5px; }
        .ib-perm-chip {
            font-size: 10px; padding: 2px 7px;
            background: #1a2535; border: 1px solid #263347;
            border-radius: 999px; color: #9ca3af;
        }

        pre.ib-pre {
            margin: 0; padding: 12px;
            background: #020617; border: 1px solid #1e2d3d;
            border-radius: 8px; color: #94a3b8;
            font-size: 11px; overflow: auto;
            max-height: 280px;
        }

        @media (max-width: 520px) {
            #insp-panel { width: 100vw; }
        }
    </style>

    {{-- Toggle button --}}
    <button id="insp-toggle" type="button">
        <span class="ib-dot" id="ib-dot"></span>
        ⚙ Inspector
    </button>

    {{-- Side panel --}}
    <div id="insp-panel" role="complementary" aria-label="Backend Inspector">
        <header class="ib-header">
            <div class="ib-header-left">
                <span class="ib-title">Backend Inspector</span>
                <span class="ib-pill">actif</span>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <span class="ib-refresh" id="ib-refresh">—</span>
                <button class="ib-close" id="ib-close" type="button" title="Fermer">✕</button>
            </div>
        </header>

        <div class="ib-body">

            {{-- Last action --}}
            <div class="ib-card" id="ib-action-card" style="border-color:rgba(56,189,248,.25);background:linear-gradient(135deg,rgba(56,189,248,.08),rgba(17,24,39,.95))">
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
                <div style="font-size:11px;color:#6b7280;margin-bottom:6px;text-transform:uppercase;letter-spacing:.06em">Rôles</div>
                <div class="ib-chips" id="ib-roles"></div>
                <div style="font-size:11px;color:#6b7280;margin:10px 0 6px;text-transform:uppercase;letter-spacing:.06em">Capacités</div>
                <div class="ib-chips" id="ib-caps"></div>
            </div>

            {{-- Timeline --}}
            <div class="ib-card">
                <p class="ib-card-title">📜 Timeline de session</p>
                <div id="ib-timeline"><span style="color:#4b5563;font-size:12px">Aucune action cette session.</span></div>
            </div>

            {{-- Tables + Effects --}}
            <div class="ib-card">
                <p class="ib-card-title">🗄 Tables concernées</p>
                <div class="ib-chips" id="ib-tables"></div>
                <hr class="ib-card-sep">
                <div style="font-size:11px;color:#6b7280;margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em">Effets attendus</div>
                <ul class="ib-list" id="ib-effects"></ul>
            </div>

            {{-- Permissions (collapsed) --}}
            <details class="ib-details">
                <summary>🔐 Permissions</summary>
                <div class="ib-details-body">
                    <div class="ib-perm-chips" id="ib-permissions"></div>
                </div>
            </details>

            {{-- Context + JSON (collapsed) --}}
            <details class="ib-details">
                <summary>📌 Contexte &amp; JSON brut</summary>
                <div class="ib-details-body">
                    <div style="font-size:11px;color:#6b7280;margin-bottom:8px;text-transform:uppercase;letter-spacing:.06em">Contexte enregistré</div>
                    <div class="ib-kv" id="ib-ctx-kv" style="margin-bottom:14px"></div>
                    <pre class="ib-pre" id="ib-raw"></pre>
                </div>
            </details>

        </div>
    </div>

    <script>
    (function () {
        'use strict';

        let _open    = false;
        let _prevKey = null;
        let _timer   = null;

        const $  = (id) => document.getElementById(id);
        const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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

        function kv(targetId, pairs) {
            const el = $(targetId);
            if (!el) return;
            const entries = Object.entries(pairs || {}).filter(([,v]) => v !== null && v !== undefined);
            if (!entries.length) { el.innerHTML = '<span style="color:#4b5563;font-size:12px">—</span>'; return; }
            el.innerHTML = entries.map(([k, v]) =>
                `<div class="k">${esc(k)}</div><div class="v">${esc(fmtVal(v))}</div>`
            ).join('');
        }

        function chips(targetId, items, accent) {
            const el = $(targetId);
            if (!el) return;
            if (!Array.isArray(items) || !items.length) { el.innerHTML = '<span style="color:#4b5563;font-size:12px">—</span>'; return; }
            el.innerHTML = items.map(item => `<span class="ib-chip${accent ? ' accent' : ''}">${esc(item)}</span>`).join('');
        }

        function permChips(targetId, items) {
            const el = $(targetId);
            if (!el) return;
            if (!Array.isArray(items) || !items.length) { el.innerHTML = '<span style="color:#4b5563;font-size:12px">—</span>'; return; }
            el.innerHTML = items.map(item => `<span class="ib-perm-chip">${esc(item)}</span>`).join('');
        }

        function listEl(targetId, items) {
            const el = $(targetId);
            if (!el) return;
            if (!Array.isArray(items) || !items.length) { el.innerHTML = '<li style="color:#4b5563">—</li>'; return; }
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

        function render(data) {
            const actor   = data.actor        || {};
            const action  = data.last_action  || {};
            const history = data.history      || [];
            const key     = data.last_action_key ?? null;
            const hasDoc  = Object.keys(action).length > 0;
            const route   = action.route      || {};
            const svc     = action.service    || {};

            // Dot + refresh
            pulseDot(key);
            _prevKey = key;
            const refreshEl = $('ib-refresh');
            if (refreshEl) refreshEl.textContent = new Date().toLocaleTimeString('fr-BE');

            // ── Last action ───────────────────────────────────────────────────
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
                'Contrôleur API':    ctrlLabel(action.api_controller,  'Aucun'),
                'Service':           svc.class   ? `${svc.class}::${svc.method ?? '?'}` : '—',
                'Méthodes internes': Array.isArray(svc.internal_methods) ? svc.internal_methods.join(' → ') : '—',
            } : {});

            // ── Actor ─────────────────────────────────────────────────────────
            kv('ib-actor-kv', {
                'Nom':          actor.name         || '—',
                'Email':        actor.email        || '—',
                'Organisation': actor.organization?.name || '—',
            });
            chips('ib-roles', actor.roles);
            chips('ib-caps',  actor.capabilities, true);

            // ── Timeline ──────────────────────────────────────────────────────
            const tlEl = $('ib-timeline');
            if (tlEl) {
                if (!history.length) {
                    tlEl.innerHTML = '<span style="color:#4b5563;font-size:12px">Aucune action cette session.</span>';
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

            // ── Tables + Effects ──────────────────────────────────────────────
            chips('ib-tables', action.tables);
            listEl('ib-effects', action.effects);

            // ── Permissions ───────────────────────────────────────────────────
            permChips('ib-permissions', actor.permissions);

            // ── Context + JSON ────────────────────────────────────────────────
            kv('ib-ctx-kv', data.last_context || {});
            const rawEl = $('ib-raw');
            if (rawEl) rawEl.textContent = JSON.stringify(data, null, 2);
        }

        async function fetchState() {
            try {
                const res  = await fetch('/demo/inspector/state', { headers: { Accept: 'application/json' } });
                const data = await res.json();
                render(data);
            } catch (_) { /* silent */ }
        }

        function openPanel() {
            _open = true;
            $('insp-panel').classList.add('open');
            $('insp-toggle').innerHTML = '<span class="ib-dot live" id="ib-dot"></span>✕ Fermer';
            fetchState();
            _timer = setInterval(fetchState, 2000);
        }

        function closePanel() {
            _open = false;
            $('insp-panel').classList.remove('open');
            $('insp-toggle').innerHTML = '<span class="ib-dot" id="ib-dot"></span>⚙ Inspector';
            clearInterval(_timer);
            _timer = null;
        }

        document.addEventListener('DOMContentLoaded', () => {
            $('insp-toggle')?.addEventListener('click', () => _open ? closePanel() : openPanel());
            $('ib-close')?.addEventListener('click', closePanel);
        });
    })();
    </script>
@endif
</body>
</html>