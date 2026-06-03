import { api, apiError } from '../api.js';
import { esc, badge, fmtDateTime, notice, toast, showModal } from '../ui.js';
import { isInternal } from '../auth.js';

export async function renderRequests(el) {
    el.innerHTML = `
        <h1>Demandes</h1>
        <div class="tabs">
            <div class="tab active" data-tab="org">Demandes d'organisation</div>
            <div class="tab" data-tab="user-org">Demandes d'adhésion</div>
        </div>
        <div id="tab-org" class="tab-panel active"></div>
        <div id="tab-user-org" class="tab-panel"></div>
    `;

    el.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            el.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            el.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            el.querySelector(`#tab-${tab.dataset.tab}`).classList.add('active');
        });
    });

    await Promise.all([
        loadOrgRequests(el.querySelector('#tab-org')),
        loadUserOrgRequests(el.querySelector('#tab-user-org')),
    ]);
}

// ── Org registration requests ─────────────────────────────────────────────────

async function loadOrgRequests(container) {
    container.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const { data } = await api.get('/org-registration-requests');
        const items = data.data ?? [];

        if (!items.length) {
            container.innerHTML = '<p class="muted">Aucune demande.</p>';
            return;
        }

        container.innerHTML = `
            <div class="panel">
                <table>
                    <thead><tr>
                        <th>#</th><th>Organisation</th><th>Demandeur</th><th>Type</th><th>Score</th><th>Statut</th><th>Date</th>
                        ${isInternal() ? '<th>Actions</th>' : ''}
                    </tr></thead>
                    <tbody>
                        ${items.map(r => `
                            <tr>
                                <td><code>${r.id}</code></td>
                                <td>${esc(r.organization_name ?? '—')}</td>
                                <td>${esc(r.user?.first_name)} ${esc(r.user?.last_name)}</td>
                                <td>${esc(r.organization_type ?? '—')}</td>
                                <td>${r.confidence_score ?? '—'}</td>
                                <td>${badge(r.status)}</td>
                                <td style="font-size:12px">${fmtDateTime(r.created_at)}</td>
                                ${isInternal() ? `
                                    <td><div class="actions">
                                        ${r.status === 'en_attente' ? `
                                            <button class="sm primary" data-action="approve-org" data-id="${r.id}">Approuver</button>
                                            <button class="sm danger" data-action="reject-org" data-id="${r.id}">Refuser</button>
                                        ` : ''}
                                    </div></td>
                                ` : ''}
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;

        container.querySelectorAll('[data-action="approve-org"]').forEach(btn => {
            btn.addEventListener('click', () => approveOrg(btn.dataset.id, container));
        });
        container.querySelectorAll('[data-action="reject-org"]').forEach(btn => {
            btn.addEventListener('click', () => rejectOrg(btn.dataset.id, container));
        });

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}

async function approveOrg(id, container) {
    if (!confirm('Approuver cette demande d\'organisation ?')) return;
    try {
        await api.patch(`/org-registration-requests/${id}/approve`);
        toast('Demande approuvée');
        await loadOrgRequests(container);
    } catch (err) {
        toast(apiError(err), 'error');
    }
}

async function rejectOrg(id, container) {
    const reason = prompt('Motif de refus (optionnel)');
    if (reason === null) return;
    try {
        await api.patch(`/org-registration-requests/${id}/reject`, { reason });
        toast('Demande refusée');
        await loadOrgRequests(container);
    } catch (err) {
        toast(apiError(err), 'error');
    }
}

// ── User org requests ─────────────────────────────────────────────────────────

async function loadUserOrgRequests(container) {
    container.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const { data } = await api.get('/user-org-requests');
        const items = data.data ?? [];

        if (!items.length) {
            container.innerHTML = '<p class="muted">Aucune demande d\'adhésion.</p>';
            return;
        }

        container.innerHTML = `
            <div class="panel">
                <table>
                    <thead><tr>
                        <th>#</th><th>Utilisateur</th><th>Organisation</th><th>Rôle demandé</th><th>Score</th><th>Statut</th><th>Date</th>
                        ${isInternal() ? '<th>Actions</th>' : ''}
                    </tr></thead>
                    <tbody>
                        ${items.map(r => `
                            <tr>
                                <td><code>${r.id}</code></td>
                                <td>${esc(r.user?.first_name)} ${esc(r.user?.last_name)}</td>
                                <td>${esc(r.organization?.name ?? '—')}</td>
                                <td>${esc(r.requested_role ?? '—')}</td>
                                <td>${r.confidence_score ?? '—'}</td>
                                <td>${badge(r.status)}</td>
                                <td style="font-size:12px">${fmtDateTime(r.created_at)}</td>
                                ${isInternal() ? `
                                    <td><div class="actions">
                                        ${r.status === 'en_attente' ? `
                                            <button class="sm primary" data-action="approve-uorg" data-id="${r.id}">Approuver</button>
                                            <button class="sm danger" data-action="reject-uorg" data-id="${r.id}">Refuser</button>
                                        ` : ''}
                                    </div></td>
                                ` : ''}
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;

        container.querySelectorAll('[data-action="approve-uorg"]').forEach(btn => {
            btn.addEventListener('click', () => approveUserOrg(btn.dataset.id, container));
        });
        container.querySelectorAll('[data-action="reject-uorg"]').forEach(btn => {
            btn.addEventListener('click', () => rejectUserOrg(btn.dataset.id, container));
        });

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}

async function approveUserOrg(id, container) {
    if (!confirm('Approuver cette adhésion ?')) return;
    try {
        await api.patch(`/user-org-requests/${id}/approve`);
        toast('Adhésion approuvée');
        await loadUserOrgRequests(container);
    } catch (err) {
        toast(apiError(err), 'error');
    }
}

async function rejectUserOrg(id, container) {
    const reason = prompt('Motif de refus (optionnel)');
    if (reason === null) return;
    try {
        await api.patch(`/user-org-requests/${id}/reject`, { reason });
        toast('Adhésion refusée');
        await loadUserOrgRequests(container);
    } catch (err) {
        toast(apiError(err), 'error');
    }
}