<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Backend Inspector</title>

    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --panel-soft: #1f2937;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --border: #334155;
            --ok: #22c55e;
            --danger: #ef4444;
            --warning: #f59e0b;
            --accent: #38bdf8;
            --accent-soft: rgba(56, 189, 248, .12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(56, 189, 248, .18), transparent 30%),
                radial-gradient(circle at bottom right, rgba(34, 197, 94, .10), transparent 25%),
                var(--bg);
            color: var(--text);
        }

        .page {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px;
        }

        .hero {
            display: flex;
            justify-content: space-between;
            gap: 24px;
            align-items: flex-start;
            margin-bottom: 24px;
        }

        .hero h1 {
            margin: 0;
            font-size: 34px;
            letter-spacing: -0.04em;
        }

        .hero p {
            margin: 8px 0 0;
            color: var(--muted);
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--muted);
            font-size: 14px;
            white-space: nowrap;
        }

        .pill::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--muted);
        }

        .pill.ok::before {
            background: var(--ok);
            box-shadow: 0 0 14px var(--ok);
        }

        .pill.ko::before {
            background: var(--danger);
            box-shadow: 0 0 14px var(--danger);
        }

        .grid {
            display: grid;
            grid-template-columns: 1.1fr 1.4fr;
            gap: 20px;
        }

        .stack {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .card {
            border: 1px solid var(--border);
            background: rgba(17, 24, 39, .88);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 18px 50px rgba(0, 0, 0, .22);
        }

        .card h2 {
            margin: 0 0 14px;
            font-size: 18px;
        }

        .card h3 {
            margin: 18px 0 8px;
            font-size: 14px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .big-action {
            border-color: rgba(56, 189, 248, .45);
            background: linear-gradient(135deg, rgba(56, 189, 248, .16), rgba(17, 24, 39, .92));
        }

        .action-title {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-bottom: 8px;
        }

        .action-key {
            display: inline-block;
            font-family: Consolas, "Courier New", monospace;
            color: var(--accent);
            background: var(--accent-soft);
            border: 1px solid rgba(56, 189, 248, .28);
            padding: 6px 10px;
            border-radius: 10px;
            margin-bottom: 12px;
        }

        .muted {
            color: var(--muted);
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--border);
            background: var(--panel-soft);
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 13px;
            color: var(--text);
        }

        .chip.accent {
            color: var(--accent);
            border-color: rgba(56, 189, 248, .35);
            background: var(--accent-soft);
        }

        .kv {
            display: grid;
            grid-template-columns: 170px 1fr;
            gap: 8px 14px;
            font-size: 14px;
        }

        .kv .key {
            color: var(--muted);
        }

        .kv .value {
            color: var(--text);
            word-break: break-word;
        }

        .list {
            margin: 0;
            padding-left: 18px;
            color: var(--text);
        }

        .list li {
            margin: 6px 0;
        }

        pre {
            margin: 0;
            padding: 16px;
            border-radius: 14px;
            background: #020617;
            border: 1px solid var(--border);
            color: #d1d5db;
            overflow: auto;
            max-height: 420px;
            font-size: 13px;
        }

        details summary {
            cursor: pointer;
            color: var(--accent);
            margin-bottom: 12px;
        }

        .pulse {
            animation: pulse .7s ease-out;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
                border-color: var(--accent);
            }
            50% {
                transform: scale(1.01);
                box-shadow: 0 0 0 4px rgba(56, 189, 248, .16);
            }
            100% {
                transform: scale(1);
            }
        }

        /* Timeline */
        .timeline-section {
            margin-top: 20px;
        }

        .timeline-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .timeline-item {
            display: grid;
            grid-template-columns: 110px 1fr auto auto;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            border: 1px solid var(--border);
            background: rgba(17, 24, 39, .55);
            border-radius: 12px;
            font-size: 13px;
            opacity: .6;
            transition: opacity .15s;
        }

        .timeline-item:hover { opacity: 1; }

        .timeline-item.ti-current {
            opacity: 1;
            border-color: rgba(56, 189, 248, .45);
            background: linear-gradient(135deg, rgba(56, 189, 248, .10), rgba(17, 24, 39, .92));
        }

        .ti-cat {
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 999px;
            background: var(--panel-soft);
            color: var(--muted);
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .timeline-item.ti-current .ti-cat {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .ti-title { color: var(--text); font-weight: 600; }

        .ti-key {
            font-family: Consolas, 'Courier New', monospace;
            font-size: 11px;
            color: var(--accent);
            background: var(--accent-soft);
            border: 1px solid rgba(56, 189, 248, .25);
            padding: 2px 7px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .ti-time { color: var(--muted); font-size: 11px; white-space: nowrap; }

        /* ── Toolbox modal ──────────────────────────────────────────────── */
        .pill-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 999px;
            border: 1px solid rgba(56, 189, 248, .45);
            background: var(--accent-soft);
            color: var(--accent);
            font-size: 14px;
            cursor: pointer;
            font-family: inherit;
            transition: background .15s, border-color .15s;
            white-space: nowrap;
        }

        .pill-btn:hover { background: rgba(56, 189, 248, .22); border-color: var(--accent); }

        .toolbox-overlay {
            position: fixed;
            inset: 0;
            margin: 0;
            padding: 24px;
            border: none;
            width: 100%;
            max-width: 100vw;
            height: 100%;
            max-height: 100dvh;
            background: rgba(2, 6, 23, .78);
            backdrop-filter: blur(6px);
            z-index: 999;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .toolbox-overlay[open] { display: flex; }
        .toolbox-overlay::backdrop { display: none; }

        .toolbox-modal {
            background: var(--panel);
            border: 1px solid rgba(56, 189, 248, .35);
            border-radius: 24px;
            width: 100%;
            max-width: 900px;
            max-height: 82vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 32px 80px rgba(0, 0, 0, .55);
            overflow: hidden;
        }

        .toolbox-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 24px 0;
            flex-shrink: 0;
        }

        .toolbox-header h2 { margin: 0; font-size: 22px; letter-spacing: -.03em; }

        .toolbox-close {
            background: none;
            border: 1px solid var(--border);
            color: var(--muted);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: border-color .15s, color .15s;
        }

        .toolbox-close:hover { border-color: var(--danger); color: var(--danger); }

        .toolbox-tabs {
            display: flex;
            gap: 4px;
            padding: 16px 24px 0;
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }

        .toolbox-tab {
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            padding: 8px 14px;
            color: var(--muted);
            font-size: 14px;
            cursor: pointer;
            font-family: inherit;
            border-radius: 8px 8px 0 0;
            transition: color .15s, background .15s;
        }

        .toolbox-tab:hover { color: var(--text); background: var(--panel-soft); }
        .toolbox-tab.active { color: var(--accent); border-bottom-color: var(--accent); background: var(--accent-soft); }

        .toolbox-content { overflow-y: auto; padding: 24px; flex: 1; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        .toolbox-table { width: 100%; border-collapse: collapse; font-size: 14px; }

        .toolbox-table th {
            text-align: left;
            color: var(--muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 8px 12px;
            border-bottom: 1px solid var(--border);
        }

        .toolbox-table td {
            padding: 8px 12px;
            border-bottom: 1px solid rgba(51, 65, 85, .5);
            color: var(--text);
        }

        .toolbox-table tr:last-child td { border-bottom: none; }
        .toolbox-table td.num { color: var(--accent); font-weight: 600; font-variant-numeric: tabular-nums; }

        .user-group { margin-bottom: 20px; }

        .user-group-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--muted);
            margin: 0 0 10px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border);
        }

        .user-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid rgba(51, 65, 85, .35);
        }

        .user-row:last-child { border-bottom: none; }
        .user-info { flex: 1; min-width: 0; }
        .user-name { font-weight: 600; font-size: 14px; }
        .user-email { color: var(--muted); font-size: 12px; margin-top: 2px; }

        .matrix-wrap { overflow-x: auto; }

        .matrix-table { border-collapse: collapse; font-size: 12px; white-space: nowrap; }

        .matrix-table th {
            padding: 6px 10px;
            color: var(--accent);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .08em;
            border-bottom: 1px solid var(--border);
            text-align: center;
        }

        .matrix-table th:first-child { text-align: left; color: var(--muted); min-width: 200px; }

        .matrix-table td {
            padding: 5px 10px;
            border-bottom: 1px solid rgba(51, 65, 85, .3);
            text-align: center;
            color: var(--muted);
        }

        .matrix-table td:first-child {
            text-align: left;
            color: var(--text);
            font-family: Consolas, 'Courier New', monospace;
            font-size: 11px;
        }

        .matrix-check { color: var(--ok); }
        .toolbox-loading { text-align: center; padding: 40px; color: var(--muted); }

        @media (max-width: 950px) {
            .page {
                padding: 18px;
            }

            .hero {
                flex-direction: column;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .kv {
                grid-template-columns: 1fr;
            }

            .timeline-item {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <header class="hero">
        <div>
            <h1>Backend Inspector</h1>
            <p>Lecture humaine de ce que le backend vient de faire dans la démo.</p>
        </div>

        <div class="stack">
            <span id="enabledPill" class="pill">Chargement...</span>
            <span id="lastRefresh" class="pill">Dernière mise à jour : —</span>
            <button class="pill-btn" onclick="openToolbox()">🧰 Toolbox</button>
        </div>
    </header>

    <main class="grid">
        <section class="stack">
            <article class="card">
                <h2>👤 Acteur courant</h2>
                <div class="kv" id="actorInfo"></div>

                <h3>Rôles</h3>
                <div class="chips" id="actorRoles"></div>

                <h3>Capacités métier</h3>
                <div class="chips" id="actorCapabilities"></div>
            </article>

            <article class="card">
                <h2>🔐 Permissions</h2>
                <div class="chips" id="actorPermissions"></div>
            </article>

            <article class="card">
                <h2>📌 Contexte enregistré</h2>
                <div class="kv" id="lastContext"></div>
            </article>
        </section>

        <section class="stack">
            <article class="card big-action" id="actionCard">
                <h2>⚙️ Dernière action backend</h2>
                <div class="action-title" id="actionTitle">Chargement...</div>
                <div class="action-key" id="actionKey">—</div>
                <p class="muted" id="actionGoal">—</p>

                <h3>Route</h3>
                <div class="kv" id="routeInfo"></div>

                <h3>Contrôleurs</h3>
                <div class="kv" id="controllerInfo"></div>

                <h3>Service / logique appelée</h3>
                <div class="kv" id="serviceInfo"></div>
            </article>

            <article class="card">
                <h2>🗄️ Tables concernées</h2>
                <div class="chips" id="tables"></div>

                <h3>Effets attendus</h3>
                <ul class="list" id="effects"></ul>
            </article>

            <article class="card">
                <details>
                    <summary>Voir le JSON brut</summary>
                    <pre id="rawState">Chargement...</pre>
                </details>
            </article>
        </section>
    </main>

    <section class="timeline-section">
        <article class="card">
            <h2>📜 Timeline de session</h2>
            <div class="timeline-list" id="timelineList"><span class="muted">Chargement...</span></div>
        </article>
    </section>
</div>

<!-- ── Toolbox modal ──────────────────────────────────────────────── -->
<dialog id="toolboxOverlay" class="toolbox-overlay" aria-label="Dev Toolbox">
    <div class="toolbox-modal">
        <header class="toolbox-header">
            <h2>🧰 Dev Toolbox</h2>
            <button class="toolbox-close" onclick="closeToolbox()">✕</button>
        </header>
        <nav class="toolbox-tabs">
            <button class="toolbox-tab active"  onclick="switchToolboxTab('stats',  this)">📊 Base de données</button>
            <button class="toolbox-tab"         onclick="switchToolboxTab('rules',  this)">📋 Règles métier</button>
            <button class="toolbox-tab"         onclick="switchToolboxTab('users',  this)">👥 Utilisateurs</button>
            <button class="toolbox-tab"         onclick="switchToolboxTab('matrix', this)">🔒 Permissions</button>
        </nav>
        <div class="toolbox-content">
            <div id="tab-stats"  class="tab-pane active"><div class="toolbox-loading">Chargement...</div></div>
            <div id="tab-rules"  class="tab-pane">       <div class="toolbox-loading">Chargement...</div></div>
            <div id="tab-users"  class="tab-pane">       <div class="toolbox-loading">Chargement...</div></div>
            <div id="tab-matrix" class="tab-pane">       <div class="toolbox-loading">Chargement...</div></div>
        </div>
    </div>
</dialog>

<script>
    let previousActionKey = null;

    const element = (id) => document.getElementById(id);

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function renderKeyValues(targetId, values) {
        const entries = Object.entries(values || {});

        if (entries.length === 0) {
            element(targetId).innerHTML = '<span class="muted">Aucune donnée.</span>';
            return;
        }

        element(targetId).innerHTML = entries.map(([key, value]) => `
            <div class="key">${escapeHtml(key)}</div>
            <div class="value">${escapeHtml(formatValue(value))}</div>
        `).join('');
    }

    function renderChips(targetId, items, accent = false) {
        if (!Array.isArray(items) || items.length === 0) {
            element(targetId).innerHTML = '<span class="muted">Aucun élément.</span>';
            return;
        }

        element(targetId).innerHTML = items.map((item) => `
            <span class="chip ${accent ? 'accent' : ''}">${escapeHtml(item)}</span>
        `).join('');
    }

    function renderList(targetId, items) {
        if (!Array.isArray(items) || items.length === 0) {
            element(targetId).innerHTML = '<li class="muted">Aucun effet documenté.</li>';
            return;
        }

        element(targetId).innerHTML = items.map((item) => `
            <li>${escapeHtml(item)}</li>
        `).join('');
    }

    function formatValue(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        if (typeof value === 'boolean') {
            return value ? 'Oui' : 'Non';
        }

        if (typeof value === 'object') {
            return JSON.stringify(value);
        }

        return value;
    }

    function timeAgo(dateString) {
        if (!dateString) return '—';
        const diff = Math.floor((Date.now() - new Date(dateString).getTime()) / 1000);
        if (diff < 5)    return 'maintenant';
        if (diff < 60)   return `${diff}s`;
        if (diff < 3600) return `${Math.floor(diff / 60)}m`;
        return `${Math.floor(diff / 3600)}h`;
    }

    function renderTimeline(history) {
        const container = element('timelineList');
        if (!container) return;

        if (!Array.isArray(history) || history.length === 0) {
            container.innerHTML = '<span class="muted">Aucune action enregistrée cette session.</span>';
            return;
        }

        container.innerHTML = history.map((item, index) => {
            const action   = item.action || {};
            const title    = action.title    || item.action_key || '—';
            const category = action.category || '?';
            return `
                <div class="timeline-item ${index === 0 ? 'ti-current' : ''}">
                    <span class="ti-cat">${escapeHtml(category)}</span>
                    <span class="ti-title">${escapeHtml(title)}</span>
                    <span class="ti-key">${escapeHtml(item.action_key)}</span>
                    <span class="ti-time">${escapeHtml(timeAgo(item.recorded_at))}</span>
                </div>
            `;
        }).join('');
    }

    function controllerLabel(controller, fallback = 'Aucun contrôleur dédié') {
        if (!controller) {
            return fallback;
        }

        const className = controller.class ?? '—';
        const methodName = controller.method ?? '—';

        return `${className}::${methodName}`;
    }

    function render(data) {
        const actor = data.actor || {};
        const action = data.last_action || {};
        const route = action.route || {};
        const demoController = action.demo_controller || null;
        const apiController = action.api_controller || null;
        const service = action.service || {};

        const isEnabled = Boolean(data.enabled);
        const actionKey = data.last_action_key ?? null;
        const hasActionKey = actionKey !== null && actionKey !== '';
        const hasDocumentedAction = Object.keys(action).length > 0;

        const missingDocumentationMessage = hasActionKey
            ? 'Fiche inspector manquante dans config/backend_inspector.php'
            : 'Aucune donnée.';

        element('enabledPill').className = `pill ${isEnabled ? 'ok' : 'ko'}`;
        element('enabledPill').textContent = isEnabled ? 'Inspector actif' : 'Inspector désactivé';
        element('lastRefresh').textContent = `Dernière mise à jour : ${new Date().toLocaleTimeString('fr-BE')}`;

        renderKeyValues('actorInfo', {
            'Sélectionné': actor.selected,
            'Nom': actor.name,
            'Email': actor.email,
            'Organisation': actor.organization?.name ?? null,
        });

        renderChips('actorRoles', actor.roles);
        renderChips('actorCapabilities', actor.capabilities, true);
        renderChips('actorPermissions', actor.permissions);

        if (!hasActionKey) {
            element('actionTitle').textContent = 'Aucune action enregistrée';
            element('actionGoal').textContent = 'Aucune action backend n’a encore été capturée dans cette session.';
        } else if (!hasDocumentedAction) {
            element('actionTitle').textContent = 'Action enregistrée mais non documentée';
            element('actionGoal').textContent =
                `La clé "${actionKey}" a bien été enregistrée par le backend, mais aucune fiche correspondante n’existe encore dans config/backend_inspector.php.`;
        } else {
            element('actionTitle').textContent = action.title ?? 'Action backend enregistrée';
            element('actionGoal').textContent = action.goal ?? 'Aucune description disponible.';
        }

        element('actionKey').textContent = actionKey ?? '—';

        renderKeyValues('routeInfo', {
            'Démo': hasDocumentedAction
                ? route.demo
                : missingDocumentationMessage,
            'API': hasDocumentedAction
                ? (route.api ?? 'Non exposé directement en API')
                : missingDocumentationMessage,
        });

        renderKeyValues('controllerInfo', {
            'Contrôleur démo': hasDocumentedAction
                ? controllerLabel(demoController, 'Aucun contrôleur démo dédié')
                : missingDocumentationMessage,
            'Contrôleur API': hasDocumentedAction
                ? controllerLabel(apiController, 'Non exposé directement en API')
                : missingDocumentationMessage,
        });

        renderKeyValues('serviceInfo', {
            'Classe': hasDocumentedAction ? service.class : missingDocumentationMessage,
            'Méthode': hasDocumentedAction ? service.method : missingDocumentationMessage,
            'Méthodes internes': hasDocumentedAction && Array.isArray(service.internal_methods)
                ? service.internal_methods.join(' → ')
                : missingDocumentationMessage,
        });

        renderChips('tables', action.tables);

        if (hasActionKey && !hasDocumentedAction) {
            renderList('effects', [
                `L’action "${actionKey}" est bien capturée dans la session inspector.`,
                'La fiche descriptive manque encore dans config/backend_inspector.php.',
                'Il faut ajouter cette clé dans le tableau flows pour afficher la route, le contrôleur, le service et les effets attendus.',
            ]);
        } else {
            renderList('effects', action.effects);
        }

        renderKeyValues('lastContext', data.last_context || {});
        renderTimeline(data.history || []);
        element('rawState').textContent = JSON.stringify(data, null, 2);

        const currentActionKey = data.last_action_key ?? null;

        if (previousActionKey !== null && previousActionKey !== currentActionKey) {
            element('actionCard').classList.remove('pulse');
            void element('actionCard').offsetWidth;
            element('actionCard').classList.add('pulse');
        }

        previousActionKey = currentActionKey;
    }

    // ── Toolbox ────────────────────────────────────────────────────────────────
    let toolboxLoaded = false;

    function openToolbox() {
        element('toolboxOverlay').showModal();
        if (!toolboxLoaded) {
            loadToolboxData();
            toolboxLoaded = true;
        }
    }

    function closeToolbox() {
        element('toolboxOverlay').close();
    }

    function switchToolboxTab(name, btn) {
        document.querySelectorAll('.toolbox-tab').forEach((b) => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach((p) => p.classList.remove('active'));
        btn.classList.add('active');
        element('tab-' + name).classList.add('active');
    }

    async function loadToolboxData() {
        try {
            const res  = await fetch('/demo/inspector/toolbox', { headers: { Accept: 'application/json' } });
            const data = await res.json();
            renderToolboxStats(data.db_stats       || []);
            renderToolboxRules(data.business_rules  || []);
            renderToolboxUsers(data.users           || {});
            renderToolboxMatrix(data.permissions    || {});
        } catch (err) {
            ['stats', 'rules', 'users', 'matrix'].forEach((t) => {
                element('tab-' + t).innerHTML = `<span class="muted">Erreur : ${escapeHtml(err.message)}</span>`;
            });
        }
    }

    function renderToolboxStats(stats) {
        if (!stats.length) {
            element('tab-stats').innerHTML = '<span class="muted">Aucune donnée configurée.</span>';
            return;
        }
        element('tab-stats').innerHTML = `
            <table class="toolbox-table">
                <thead><tr><th>Entité</th><th>Enregistrements</th></tr></thead>
                <tbody>${stats.map((s) => `
                    <tr>
                        <td>${escapeHtml(s.label)}</td>
                        <td class="num">${escapeHtml(String(s.count))}</td>
                    </tr>`).join('')}
                </tbody>
            </table>`;
    }

    function renderToolboxRules(rules) {
        if (!rules.length) {
            element('tab-rules').innerHTML = '<span class="muted">Aucune règle configurée.</span>';
            return;
        }
        element('tab-rules').innerHTML = `
            <table class="toolbox-table">
                <thead><tr><th>Règle</th><th>Valeur</th></tr></thead>
                <tbody>${rules.map((r) => `
                    <tr>
                        <td>${escapeHtml(r.label)}</td>
                        <td class="num">${escapeHtml(r.value)}${r.unit ? ` <span style="color:var(--muted);font-size:11px">${escapeHtml(r.unit)}</span>` : ''}</td>
                    </tr>`).join('')}
                </tbody>
            </table>`;
    }

    function renderToolboxUsers(grouped) {
        const groups = Object.entries(grouped);
        if (!groups.length) {
            element('tab-users').innerHTML = '<span class="muted">Aucun utilisateur.</span>';
            return;
        }
        element('tab-users').innerHTML = groups.map(([role, members]) => `
            <div class="user-group">
                <p class="user-group-title">${escapeHtml(role)}</p>
                ${members.map((u) => `
                    <div class="user-row">
                        <div class="user-info">
                            <div class="user-name">${escapeHtml(u.name)}</div>
                            <div class="user-email">${escapeHtml(u.email)}</div>
                        </div>
                    </div>`).join('')}
            </div>`).join('');
    }

    function renderToolboxMatrix(data) {
        const { roles = [], permissions = [], matrix = {} } = data;
        if (!roles.length || !permissions.length) {
            element('tab-matrix').innerHTML = '<span class="muted">Aucune donnée de permissions.</span>';
            return;
        }
        element('tab-matrix').innerHTML = `
            <div class="matrix-wrap">
                <table class="matrix-table">
                    <thead>
                        <tr>
                            <th>Permission</th>
                            ${roles.map((r) => `<th>${escapeHtml(r)}</th>`).join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${permissions.map((perm) => `
                            <tr>
                                <td>${escapeHtml(perm)}</td>
                                ${roles.map((r) => {
                                    const has = Array.isArray(matrix[r]) && matrix[r].includes(perm);
                                    return `<td${has ? ' class="matrix-check"' : ''}>${has ? '✓' : ''}</td>`;
                                }).join('')}
                            </tr>`).join('')}
                    </tbody>
                </table>
            </div>`;
    }

    element('toolboxOverlay').addEventListener('click', (e) => {
        if (e.target === element('toolboxOverlay')) closeToolbox();
    });

    async function refreshInspector() {
        try {
            const response = await fetch('/demo/inspector/state', {
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();
            render(data);
        } catch (error) {
            element('enabledPill').className = 'pill ko';
            element('enabledPill').textContent = 'Erreur de lecture';
            element('rawState').textContent = error.message;
        }
    }

    refreshInspector();
    setInterval(refreshInspector, 2000);
</script>
</body>
</html>
