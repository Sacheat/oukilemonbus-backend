import { api } from '../api.js';
import { getUser, isInternal, hasRole } from '../auth.js';
import { esc, badge, fmtDate, fmtTime, roleBadge } from '../ui.js';

export async function renderDashboard(el) {
    const user = getUser();

    // Fetch data in parallel
    const [resResult, notifsResult, meResult] = await Promise.allSettled([
        api.get('/reservations', { params: { per_page: 5 } }),
        api.get('/notifications/unread-count'),
        api.get('/auth/me'),
    ]);

    const reservations = resResult.status === 'fulfilled' ? resResult.value.data.data ?? [] : [];
    const resMeta = resResult.status === 'fulfilled' ? resResult.value.data.meta ?? {} : {};
    const unread = notifsResult.status === 'fulfilled' ? (notifsResult.value.data.data?.unread_count ?? notifsResult.value.data.count ?? 0) : 0;
    const me = meResult.status === 'fulfilled' ? (meResult.value.data.data ?? meResult.value.data) : user;

    const roles = me.roles ?? user?.roles ?? [];
    const orgs = me.organizations ?? [];

    // Role-based quick actions
    const quickActions = buildQuickActions(roles);

    el.innerHTML = `
        <div class="split">
            <div>
                <h1>Tableau de bord</h1>
                <p class="lede">Bienvenue, ${esc(me.first_name)} ${esc(me.last_name)}</p>
            </div>
            ${isInternal() ? `<button class="primary" onclick="location.hash='#/reservations/create'">+ Nouvelle réservation</button>` : ''}
        </div>

        <div class="grid grid-4" style="margin-bottom:20px">
            <div class="panel">
                <div class="metric-label">Réservations visibles</div>
                <div class="metric-value">${resMeta.total ?? reservations.length}</div>
            </div>
            <div class="panel">
                <div class="metric-label">Notifs non lues</div>
                <div class="metric-value">${unread}</div>
            </div>
            <div class="panel">
                <div class="metric-label">Rôle(s)</div>
                <div style="margin-top:6px;display:flex;gap:4px;flex-wrap:wrap">
                    ${roles.map(r => roleBadge(r)).join('') || '—'}
                </div>
            </div>
            <div class="panel">
                <div class="metric-label">Organisation(s)</div>
                <div style="margin-top:6px;font-size:13px">
                    ${orgs.length ? orgs.map(o => esc(o.name)).join(', ') : '<span class="muted">Aucune</span>'}
                </div>
            </div>
        </div>

        <div class="grid grid-2">
            <div class="panel">
                <div class="split">
                    <h2>Dernières réservations</h2>
                    <a href="#/reservations" style="font-size:12px">Voir tout →</a>
                </div>
                ${renderRecentReservations(reservations)}
            </div>

            <div class="panel">
                <h2>Actions rapides</h2>
                <div class="stack">
                    ${quickActions}
                </div>
            </div>
        </div>
    `;
}

function renderRecentReservations(list) {
    if (!list.length) return '<p class="muted">Aucune réservation.</p>';
    return `
        <table>
            <thead><tr>
                <th>Date</th><th>Organisation</th><th>Statut</th>
            </tr></thead>
            <tbody>
                ${list.map(r => `
                    <tr>
                        <td>${fmtDate(r.trip_date)}</td>
                        <td>${esc(r.organization?.name ?? '—')}</td>
                        <td>${badge(r.status)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}

function buildQuickActions(roles) {
    const actions = [];

    if (roles.includes('admin') || roles.includes('agent_communal')) {
        actions.push(
            `<a href="#/reservations" class="btn">Gérer les réservations</a>`,
            `<a href="#/planning" class="btn">Voir le planning</a>`,
            `<a href="#/requests" class="btn">Traiter les demandes</a>`,
            `<a href="#/admin" class="btn">Gestion flotte & chauffeurs</a>`,
        );
    }

    if (roles.includes('chauffeur')) {
        actions.push(`<a href="#/planning" class="btn">Mon planning</a>`);
    }

    if (roles.includes('responsable_organisation') || roles.includes('user')) {
        actions.push(
            `<a href="#/reservations/create" class="btn primary">Nouvelle réservation</a>`,
            `<a href="#/reservations" class="btn">Mes réservations</a>`,
            `<a href="#/requests" class="btn">Mes demandes</a>`,
        );
    }

    actions.push(`<a href="#/notifications" class="btn">Mes notifications</a>`);

    return actions.map(a => `<div>${a}</div>`).join('');
}