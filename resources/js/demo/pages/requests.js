import { api, apiError } from '../api.js';
import { esc, badge, fmtDateTime, notice, toast, showModal } from '../ui.js';
import { isInternal } from '../auth.js';

const ORG_TYPES_EXTERNAL = [
    { value: 'cpas',     label: 'CPAS' },
    { value: 'asbl',     label: 'ASBL' },
    { value: 'externe',  label: 'Externe' },
];

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

        const btnHtml = !isInternal()
            ? `<div style="margin-bottom:12px"><button id="btn-new-org-request" class="primary">+ Demander la création d'une organisation</button></div>`
            : '';

        if (!items.length) {
            container.innerHTML = btnHtml + '<p class="muted">Aucune demande.</p>';
        } else {
            container.innerHTML = btnHtml + `
                <div class="panel">
                    <table>
                        <thead><tr>
                            <th>#</th><th>Organisation</th><th>Demandeur</th><th>Type</th><th>Justificatif</th><th>Score</th><th>Statut</th><th>Date</th>
                            ${isInternal() ? '<th>Actions</th>' : ''}
                        </tr></thead>
                        <tbody>
                            ${items.map(r => `
                                <tr>
                                    <td><code>${r.id}</code></td>
                                    <td>${esc(r.organization_name ?? '—')}</td>
                                    <td>${esc(r.requested_by_user?.first_name ?? '')} ${esc(r.requested_by_user?.last_name ?? '')}</td>
                                    <td>${esc(r.organization_type ?? '—')}</td>
                                    <td>${r.proof_document_id
                                        ? `<div class="actions">
                                               ${isInternal()
                                                   ? `<button class="sm" data-open-proof="/api/documents/${r.proof_document_id}/download">📎 Voir</button>`
                                                   : `<span class="badge ok" title="Fichier joint">📎 Joint</span>`
                                               }
                                               ${!isInternal() && r.status === 'en_attente' ? `<button class="sm danger" data-del-proof-org="${r.id}">✕</button>` : ''}
                                           </div>`
                                        : `<span class="muted" style="font-size:11px">—</span>`
                                    }</td>
                                    <td>${r.confidence_note ?? '—'}</td>
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
        }

        container.querySelector('#btn-new-org-request')?.addEventListener('click', () =>
            openRequestOrgModal(() => loadOrgRequests(container))
        );
        container.querySelectorAll('[data-open-proof]').forEach(btn => {
            btn.addEventListener('click', () => window.open(btn.dataset.openProof, '_blank', 'noopener'));
        });
        container.querySelectorAll('[data-del-proof-org]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Supprimer la pièce jointe ?')) return;
                try {
                    await api.delete(`/org-registration-requests/${btn.dataset.delProofOrg}/proof`);
                    toast('Pièce jointe supprimée');
                    loadOrgRequests(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });
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
    const reason = prompt('Motif de refus (obligatoire)');
    if (reason === null || reason.trim() === '') return;
    try {
        await api.patch(`/org-registration-requests/${id}/reject`, { rejection_reason: reason.trim() });
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

        const btnHtml = !isInternal()
            ? `<div style="margin-bottom:12px"><button id="btn-new-uorg-request" class="primary">+ Demander l'adhésion à une organisation</button></div>`
            : '';

        if (!items.length) {
            container.innerHTML = btnHtml + '<p class="muted">Aucune demande d\'adhésion.</p>';
        } else {
            container.innerHTML = btnHtml + `
                <div class="panel">
                    <table>
                        <thead><tr>
                            <th>#</th><th>Utilisateur</th><th>Organisation</th><th>Rôle demandé</th><th>Justificatif</th><th>Score</th><th>Statut</th><th>Date</th>
                            ${isInternal() ? '<th>Actions</th>' : ''}
                        </tr></thead>
                        <tbody>
                            ${items.map(r => `
                                <tr>
                                    <td><code>${r.request_id}</code></td>
                                    <td>${esc(r.requested_by_user?.first_name ?? '')} ${esc(r.requested_by_user?.last_name ?? '')}</td>
                                    <td>${esc(r.organization?.name ?? '—')}</td>
                                    <td>${esc(r.requested_role ?? '—')}</td>
                                    <td>${r.proof_document_id
                                        ? `<div class="actions">
                                               ${isInternal()
                                                   ? `<button class="sm" data-open-proof="/api/documents/${r.proof_document_id}/download">📎 Voir</button>`
                                                   : `<span class="badge ok" title="Fichier joint">📎 Joint</span>`
                                               }
                                               ${!isInternal() && r.status === 'en_attente' ? `<button class="sm danger" data-del-proof-uorg="${r.request_id}">✕</button>` : ''}
                                           </div>`
                                        : `<span class="muted" style="font-size:11px">—</span>`
                                    }</td>
                                    <td>${r.confidence_note ?? '—'}</td>
                                    <td>${badge(r.status)}</td>
                                    <td style="font-size:12px">${fmtDateTime(r.created_at)}</td>
                                    ${isInternal() ? `
                                        <td><div class="actions">
                                            ${r.status === 'en_attente' ? `
                                                <button class="sm primary" data-action="approve-uorg" data-id="${r.request_id}">Approuver</button>
                                                <button class="sm danger" data-action="reject-uorg" data-id="${r.request_id}">Refuser</button>
                                            ` : ''}
                                        </div></td>
                                    ` : ''}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }

        container.querySelector('#btn-new-uorg-request')?.addEventListener('click', () =>
            openJoinOrgModal(() => loadUserOrgRequests(container))
        );
        container.querySelectorAll('[data-open-proof]').forEach(btn => {
            btn.addEventListener('click', () => window.open(btn.dataset.openProof, '_blank', 'noopener'));
        });
        container.querySelectorAll('[data-del-proof-uorg]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Supprimer la pièce jointe ?')) return;
                try {
                    await api.delete(`/user-org-requests/${btn.dataset.delProofUorg}/proof`);
                    toast('Pièce jointe supprimée');
                    loadUserOrgRequests(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });
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
    const reason = prompt('Motif de refus (obligatoire)');
    if (reason === null || reason.trim() === '') return;
    try {
        await api.patch(`/user-org-requests/${id}/reject`, { rejection_reason: reason.trim() });
        toast('Adhésion refusée');
        await loadUserOrgRequests(container);
    } catch (err) {
        toast(apiError(err), 'error');
    }
}

// ── Nouvelle demande de création d'organisation (utilisateur externe) ──────────

function openRequestOrgModal(onSuccess) {
    const typeOptions = ORG_TYPES_EXTERNAL
        .map(t => `<option value="${t.value}">${esc(t.label)}</option>`)
        .join('');

    const body = `
        <form id="form-request-org" class="form-grid">
            <label class="full">Nom de l'organisation <span style="color:var(--danger)">*</span>
                <input name="organization_name" required maxlength="255" placeholder="Nom officiel">
            </label>
            <label>Type <span style="color:var(--danger)">*</span>
                <select name="organization_type" required>
                    <option value="">— Choisir —</option>
                    ${typeOptions}
                </select>
            </label>
            <label>N° d'entreprise
                <input name="enterprise_number" placeholder="BE 0xxx.xxx.xxx">
            </label>
            <label>Email officiel <span style="color:var(--danger)">*</span>
                <input name="official_email" type="email" required placeholder="contact@org.be">
            </label>
            <label>Téléphone
                <input name="phone" placeholder="+32 ...">
            </label>
            <label>Nom du contact
                <input name="contact_person_name" placeholder="Prénom Nom">
            </label>
            <label>Rôle du contact
                <input name="contact_person_role" placeholder="Président, Trésorier…">
            </label>
            <label class="full">Commentaire
                <textarea name="comment" placeholder="Informations complémentaires…"></textarea>
            </label>
            <label class="full">
                Pièce jointe justificative <span style="color:var(--muted);font-weight:400">(optionnel — PDF, JPG ou PNG, max 5 Mo)</span>
                <input id="proof-file-input" type="file" accept=".pdf,.jpg,.jpeg,.png" style="padding:4px">
            </label>
            <div class="full" id="request-org-error"></div>
            <div class="full actions">
                <button type="submit" class="primary" id="btn-submit-request">Envoyer la demande</button>
            </div>
        </form>
    `;

    const { close } = showModal('Demande de création d\'organisation', body);

    document.getElementById('form-request-org').addEventListener('submit', async e => {
        e.preventDefault();
        const fd     = new FormData(e.target);
        const file   = document.getElementById('proof-file-input').files[0] ?? null;
        const payload = Object.fromEntries([...fd.entries()].filter(([k, v]) => k !== 'proof_document' && v !== ''));
        const errEl  = document.getElementById('request-org-error');
        const btn    = document.getElementById('btn-submit-request');
        errEl.innerHTML = '';
        btn.disabled = true;
        btn.textContent = 'Envoi…';

        try {
            // Étape 1 : créer la demande
            const { data } = await api.post('/org-registration-requests', payload);
            const requestId = data.data?.id ?? data.id;

            // Étape 2 : upload du justificatif si présent
            if (file && requestId) {
                try {
                    const formData = new FormData();
                    formData.append('proof_document', file);
                    await api.post(`/org-registration-requests/${requestId}/proof`, formData);
                    toast('Demande envoyée avec justificatif.');
                } catch (uploadErr) {
                    // La demande est créée mais l'upload a échoué — on informe sans bloquer
                    toast(`Demande créée mais le justificatif n'a pas pu être joint : ${apiError(uploadErr)}`, 'warning');
                }
            } else {
                toast('Demande envoyée. Un agent communal la traitera prochainement.');
            }

            close();
            onSuccess?.();
        } catch (err) {
            errEl.innerHTML = notice(apiError(err), 'error');
            btn.disabled = false;
            btn.textContent = 'Envoyer la demande';
        }
    });
}

// ── Demande d'adhésion à une organisation (utilisateur externe) ───────────────

const REQUESTED_ROLES = [
    { value: 'responsable', label: 'Responsable' },
    { value: 'referent',    label: 'Référent' },
    { value: 'directeur',   label: 'Directeur' },
    { value: 'prof',        label: 'Professeur' },
    { value: 'animateur',   label: 'Animateur' },
];

async function openJoinOrgModal(onSuccess) {
    // Charger les organisations rejoignables (pas déjà membre, pas de demande en attente)
    let orgOptions = '<option value="">Chargement…</option>';
    try {
        const { data } = await api.get('/user-org-requests/available-organizations');
        const orgs = data.data ?? [];
        orgOptions = orgs.length
            ? orgs.map(o => `<option value="${o.id}">${esc(o.name)} (${esc(o.type ?? '')})</option>`).join('')
            : '<option value="" disabled>Aucune organisation disponible à rejoindre</option>';
    } catch {
        orgOptions = '<option value="" disabled>Impossible de charger les organisations</option>';
    }

    const roleOptions = REQUESTED_ROLES
        .map(r => `<option value="${r.value}">${esc(r.label)}</option>`)
        .join('');

    const body = `
        <form id="form-join-org" class="form-grid">
            <label class="full">Organisation <span style="color:var(--danger)">*</span>
                <select name="organization_id" required>
                    <option value="">— Choisir —</option>
                    ${orgOptions}
                </select>
            </label>
            <label class="full">Rôle demandé <span style="color:var(--danger)">*</span>
                <select name="requested_role" required>
                    <option value="">— Choisir —</option>
                    ${roleOptions}
                </select>
            </label>
            <label class="full">Justification
                <textarea name="justification" placeholder="Expliquez votre lien avec cette organisation…" rows="3"></textarea>
            </label>
            <label class="full">
                Pièce jointe justificative <span style="color:var(--muted);font-weight:400">(optionnel — PDF, JPG ou PNG, max 5 Mo)</span>
                <input id="join-proof-file-input" type="file" accept=".pdf,.jpg,.jpeg,.png" style="padding:4px">
            </label>
            <div class="full" id="join-org-error"></div>
            <div class="full actions">
                <button type="submit" class="primary" id="btn-submit-join">Envoyer la demande</button>
            </div>
        </form>
    `;

    const { close } = showModal('Demande d\'adhésion à une organisation', body);

    document.getElementById('form-join-org').addEventListener('submit', async e => {
        e.preventDefault();
        const fd      = new FormData(e.target);
        const file    = document.getElementById('join-proof-file-input').files[0] ?? null;
        const payload = Object.fromEntries([...fd.entries()].filter(([, v]) => v !== ''));
        const errEl   = document.getElementById('join-org-error');
        const btn     = document.getElementById('btn-submit-join');
        errEl.innerHTML = '';
        btn.disabled = true;
        btn.textContent = 'Envoi…';

        try {
            // Étape 1 : créer la demande d'adhésion
            const { data } = await api.post('/user-org-requests', payload);
            const requestId = data.data?.request_id ?? data.request_id;

            // Étape 2 : upload du justificatif si présent
            if (file && requestId) {
                try {
                    const formData = new FormData();
                    formData.append('proof_document', file);
                    await api.post(`/user-org-requests/${requestId}/proof`, formData);
                    toast('Demande envoyée avec justificatif.');
                } catch (uploadErr) {
                    toast(`Demande créée mais le justificatif n'a pas pu être joint : ${apiError(uploadErr)}`, 'warning');
                }
            } else {
                toast('Demande d\'adhésion envoyée.');
            }

            close();
            onSuccess?.();
        } catch (err) {
            errEl.innerHTML = notice(apiError(err), 'error');
            btn.disabled = false;
            btn.textContent = 'Envoyer la demande';
        }
    });
}