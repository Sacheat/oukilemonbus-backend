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
</body>
</html>