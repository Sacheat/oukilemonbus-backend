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
</div>

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

        element('actionTitle').textContent = action.title ?? 'Aucune action enregistrée';
        element('actionKey').textContent = data.last_action_key ?? '—';
        element('actionGoal').textContent = action.goal ?? 'Aucune description disponible.';

        renderKeyValues('routeInfo', {
            'Démo': route.demo,
            'API': route.api ?? 'Non exposé directement en API',
        });

        renderKeyValues('controllerInfo', {
            'Contrôleur démo': controllerLabel(demoController, 'Aucun contrôleur démo dédié'),
            'Contrôleur API': controllerLabel(apiController, 'Non exposé directement en API'),
        });

        renderKeyValues('serviceInfo', {
            'Classe': service.class,
            'Méthode': service.method,
            'Méthodes internes': Array.isArray(service.internal_methods)
                ? service.internal_methods.join(' → ')
                : null,
        });

        renderChips('tables', action.tables);
        renderList('effects', action.effects);

        renderKeyValues('lastContext', data.last_context || {});
        element('rawState').textContent = JSON.stringify(data, null, 2);

        const currentActionKey = data.last_action_key ?? null;

        if (previousActionKey !== null && previousActionKey !== currentActionKey) {
            element('actionCard').classList.remove('pulse');
            void element('actionCard').offsetWidth;
            element('actionCard').classList.add('pulse');
        }

        previousActionKey = currentActionKey;
    }

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
