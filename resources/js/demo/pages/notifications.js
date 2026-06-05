import { api, apiError } from '../api.js';
import { esc, fmtDateTime, notice, toast } from '../ui.js';
import { navigate } from '../router.js';

const CATEGORY_META = {
    reservation_updates:      { icon: '📋', label: 'Réservation' },
    admin_workflow:           { icon: '⚙️',  label: 'Workflow admin' },
    driver_planning:          { icon: '🚌', label: 'Planning chauffeur' },
    last_minute_cancellation: { icon: '🚨', label: 'Annulation urgente' },
};

export async function renderNotifications(el) {
    el.innerHTML = `
        <div class="split">
            <h1>Notifications</h1>
            <button id="btn-read-all">Tout marquer comme lu</button>
        </div>

        <div class="grid grid-2">
            <div>
                <div class="panel" id="notifs-list">
                    <div class="loading">Chargement…</div>
                </div>
            </div>
            <div>
                <div class="panel">
                    <h2>Préférences</h2>
                    <div id="prefs-container"><div class="loading">Chargement…</div></div>
                </div>
            </div>
        </div>
    `;

    el.querySelector('#btn-read-all').addEventListener('click', async () => {
        try {
            await api.patch('/notifications/read-all');
            toast('Toutes les notifications marquées comme lues');
            await loadNotifications(el.querySelector('#notifs-list'));
        } catch (err) {
            toast(apiError(err), 'error');
        }
    });

    const notifsContainer = el.querySelector('#notifs-list');

    await Promise.all([
        loadNotifications(notifsContainer),
        loadPreferences(el.querySelector('#prefs-container')),
    ]);

    const refreshInterval = setInterval(() => {
        if (!document.contains(notifsContainer)) { clearInterval(refreshInterval); return; }
        loadNotifications(notifsContainer);
    }, 30_000);
}

async function loadNotifications(container) {
    container.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const { data } = await api.get('/notifications', { params: { per_page: 20 } });
        const items = data.data ?? [];

        if (!items.length) {
            container.innerHTML = '<p class="muted">Aucune notification.</p>';
            return;
        }

        container.innerHTML = `
            <div class="stack">
                ${items.map(n => {
                    const d = n.data ?? {};
                    const isUnread = !n.read_at;
                    const isUrgent = d.priority === 'high';
                    const meta = CATEGORY_META[d.category] ?? { icon: '🔔', label: d.category ?? '' };
                    const borderColor = isUrgent ? 'var(--danger)' : 'var(--brand)';
                    const rowStyle = isUnread
                        ? `background:var(--brand-soft);padding:10px 12px;border-radius:6px;border-left:3px solid ${borderColor}`
                        : `padding:8px 0 8px 3px;border-bottom:1px solid var(--line)`;

                    const actions = [];
                    if (d.reservation_id) {
                        actions.push(`<button class="sm" data-goto-reservation="${d.reservation_id}">→ Voir la réservation</button>`);
                    }
                    if (d.document_id) {
                        actions.push(`<button class="sm" data-open-doc="/api/documents/${d.document_id}/download">📄 Voir le document</button>`);
                    }

                    return `
                    <div style="display:flex;gap:12px;align-items:flex-start;${rowStyle}">
                        <div style="font-size:18px;line-height:1;padding-top:2px">${meta.icon}</div>
                        <div style="flex:1;min-width:0">
                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                                <span style="font-weight:${isUnread ? '650' : '400'}">${esc(d.title ?? n.type ?? '—')}</span>
                                <span class="badge${isUrgent ? ' danger' : ''}" style="font-size:10px">${esc(meta.label)}</span>
                                ${isUrgent ? '<span class="badge danger" style="font-size:10px">Urgent</span>' : ''}
                            </div>
                            <div class="muted" style="font-size:12px;margin-top:2px">${esc(d.message ?? '')}</div>
                            ${actions.length ? `<div class="actions" style="margin-top:6px">${actions.join('')}</div>` : ''}
                            <div class="muted" style="font-size:11px;margin-top:4px">${fmtDateTime(n.created_at)}</div>
                        </div>
                        ${isUnread ? `<button class="sm" data-notif-id="${n.id}" style="flex-shrink:0">Lu</button>` : ''}
                    </div>
                `}).join('')}
            </div>
        `;

        container.querySelectorAll('[data-notif-id]').forEach(btn => {
            btn.addEventListener('click', async () => {
                try {
                    await api.patch(`/notifications/${btn.dataset.notifId}/read`);
                    await loadNotifications(container);
                } catch (err) {
                    toast(apiError(err), 'error');
                }
            });
        });

        container.querySelectorAll('[data-goto-reservation]').forEach(btn => {
            btn.addEventListener('click', () => navigate(`/reservations?highlight=${btn.dataset.gotoReservation}`));
        });

        container.querySelectorAll('[data-open-doc]').forEach(btn => {
            btn.addEventListener('click', () => window.open(btn.dataset.openDoc, '_blank', 'noopener'));
        });

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}

async function loadPreferences(container) {
    container.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const { data } = await api.get('/notifications/preferences');
        const prefs = data.data ?? data;

        const categories = [
            { key: 'reservation_updates', label: 'Mises à jour réservations' },
            { key: 'admin_workflow', label: 'Workflow admin' },
            { key: 'driver_planning', label: 'Planning chauffeur' },
            { key: 'last_minute_cancellation', label: 'Annulations urgentes' },
        ];

        const channels = ['database', 'mail'];

        container.innerHTML = `
            <form id="prefs-form">
                <div class="stack">
                    <label style="font-size:12px;color:var(--muted);font-weight:650">Fréquence emails
                        <select name="digest_frequency">
                            <option value="none" ${prefs.digest_frequency === 'none' ? 'selected' : ''}>Immédiat</option>
                            <option value="hourly" ${prefs.digest_frequency === 'hourly' ? 'selected' : ''}>Résumé horaire</option>
                            <option value="daily" ${prefs.digest_frequency === 'daily' ? 'selected' : ''}>Résumé quotidien</option>
                        </select>
                    </label>

                    ${categories.map(cat => `
                        <div>
                            <div style="font-size:12px;font-weight:650;margin-bottom:6px">${esc(cat.label)}</div>
                            <div class="actions">
                                ${channels.map(ch => {
                                    const catPref = prefs.categories?.[cat.key]?.[ch];
                                    return `
                                        <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text);font-weight:400">
                                            <input type="checkbox" name="${cat.key}__${ch}" ${catPref !== false ? 'checked' : ''}>
                                            ${esc(ch)}
                                        </label>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    `).join('')}
                </div>
                <div style="margin-top:14px;display:flex;gap:8px;align-items:center">
                    <button type="submit" class="primary sm">Enregistrer</button>
                    <div id="prefs-notice"></div>
                </div>
            </form>
        `;

        container.querySelector('#prefs-form').addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const body = { digest_frequency: fd.get('digest_frequency'), categories: {} };

            categories.forEach(cat => {
                body.categories[cat.key] = {};
                channels.forEach(ch => {
                    body.categories[cat.key][ch] = fd.has(`${cat.key}__${ch}`);
                });
            });

            try {
                await api.patch('/notifications/preferences', body);
                toast('Préférences enregistrées');
            } catch (err) {
                toast(apiError(err), 'error');
            }
        });

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}