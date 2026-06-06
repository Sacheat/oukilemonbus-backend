import { api, apiError } from '../api.js';
import { getUser, hasRole, isInternal } from '../auth.js';
import { esc, badge, notice, toast, showModal, table } from '../ui.js';

const ORG_TYPES = [
    { value: 'ecole_communale',    label: 'École communale' },
    { value: 'personnel_communal', label: 'Personnel communal' },
    { value: 'cpas',               label: 'CPAS' },
    { value: 'asbl',               label: 'ASBL' },
    { value: 'externe',            label: 'Externe' },
];

const ORG_TYPES_EXTERNAL = ORG_TYPES.filter(t =>
    ['cpas', 'asbl', 'externe'].includes(t.value)
);

const QUOTA_EXEMPT_TYPES = ['ecole_communale', 'personnel_communal'];

function quotaDisplay(org) {
    if (QUOTA_EXEMPT_TYPES.includes(org.type)) return '<span class="muted">Illimité</span>';
    return `${org.reservations_used_this_year ?? 0} / ${org.annual_quota ?? '—'}`;
}

function typeLabel(value) {
    return ORG_TYPES.find(t => t.value === value)?.label ?? value ?? '—';
}

function typeOptions(types) {
    return types.map(t => `<option value="${t.value}">${esc(t.label)}</option>`).join('');
}

// ── Page principale ────────────────────────────────────────────────────────────

export async function renderOrganizations(el) {
    const user = getUser();
    const canCreate   = user?.permissions?.includes('organizations.create') ?? hasRole('admin', 'agent_communal');
    const canBan      = user?.permissions?.includes('organizations.ban')    ?? hasRole('admin');
    const canRequest  = !isInternal();

    el.innerHTML = `
        <div class="split" style="margin-bottom:16px">
            <h1>Organisations</h1>
            <div class="actions">
                ${canCreate  ? '<button id="btn-create-org" class="primary">+ Créer une organisation</button>' : ''}
                ${canRequest ? '<button id="btn-request-org" class="primary">+ Demander la création</button>' : ''}
            </div>
        </div>
        <div id="org-list"><div class="panel"><div class="loading">Chargement…</div></div></div>
    `;

    if (canCreate)  el.querySelector('#btn-create-org')?.addEventListener('click', () => openCreateOrgModal(reload));
    if (canRequest) el.querySelector('#btn-request-org')?.addEventListener('click', () => openRequestOrgModal());

    async function reload() { await loadOrgs(el.querySelector('#org-list'), canBan, canCreate); }
    await reload();
}

// ── Liste ─────────────────────────────────────────────────────────────────────

async function loadOrgs(container, canBan, canCreate) {
    try {
        const { data } = await api.get('/organizations');
        const items = data.data ?? [];

        if (!items.length) {
            container.innerHTML = '<div class="panel"><p class="muted">Aucune organisation.</p></div>';
            return;
        }

        const rows = items.map(o => [
            `<code>${o.id}</code>`,
            `<strong>${esc(o.name)}</strong>`,
            esc(typeLabel(o.type)),
            o.is_priority ? badge('Prioritaire', 'ok') : '',
            o.is_banned   ? badge('Banni', 'danger')   : badge('Actif', 'ok'),
            quotaDisplay(o),
            actionButtons(o, canBan, canCreate),
        ]);

        const tbl = table(
            ['#', 'Nom', 'Type', 'Priorité', 'Statut', 'Quota annuel', 'Actions'],
            rows
        );

        container.innerHTML = `<div class="panel">${tbl}</div>`;

        // Délégation événements
        container.querySelectorAll('[data-action]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const { action, id } = btn.dataset;
                const org = items.find(o => String(o.id) === id);
                if (!org) return;

                if (action === 'detail') openDetailModal(org, canBan, () => loadOrgs(container, canBan, canCreate));
                if (action === 'ban')    openBanModal(org,    () => loadOrgs(container, canBan, canCreate));
                if (action === 'unban')  await doUnban(org,   () => loadOrgs(container, canBan, canCreate));
            });
        });

    } catch (err) {
        container.innerHTML = `<div class="panel">${notice(apiError(err), 'error')}</div>`;
    }
}

function actionButtons(o, canBan, canCreate) {
    const btns = [`<button class="sm" data-action="detail" data-id="${o.id}">Détails</button>`];
    if (canBan && !o.is_banned) btns.push(`<button class="sm danger" data-action="ban"   data-id="${o.id}">Bannir</button>`);
    if (canBan &&  o.is_banned) btns.push(`<button class="sm"        data-action="unban" data-id="${o.id}">Lever le ban</button>`);
    return `<div class="actions">${btns.join('')}</div>`;
}

// ── Détail org ────────────────────────────────────────────────────────────────

function openDetailModal(org, canBan, onRefresh) {
    const banInfo = org.is_banned
        ? `<div class="notice error" style="margin-top:12px">Banni — ${esc(org.banned_reason ?? 'aucun motif')}</div>`
        : '';

    const body = `
        <div class="form-grid">
            <label>Nom<br><strong>${esc(org.name)}</strong></label>
            <label>Type<br><strong>${esc(typeLabel(org.type))}</strong></label>
            <label>Email<br><strong>${esc(org.official_email ?? '—')}</strong></label>
            <label>Téléphone<br><strong>${esc(org.phone_contact ?? '—')}</strong></label>
            <label>Contact<br><strong>${esc(org.name_contact ?? '—')}</strong></label>
            <label>Quota annuel<br><strong>${QUOTA_EXEMPT_TYPES.includes(org.type) ? 'Illimité' : `${org.reservations_used_this_year ?? 0} utilisées / ${org.annual_quota ?? '—'} max`}</strong></label>
            <label>Prioritaire<br><strong>${org.is_priority ? 'Oui' : 'Non'}</strong></label>
            <label>Statut<br><strong>${org.is_banned ? 'Banni' : 'Actif'}</strong></label>
        </div>
        ${banInfo}
    `;

    showModal(`Organisation #${org.id} — ${org.name}`, body);
}

// ── Créer org (admin/agent) ────────────────────────────────────────────────────

function openCreateOrgModal(onSuccess) {
    const body = `
        <form id="form-create-org" class="form-grid">
            <label class="full">Nom <span style="color:var(--danger)">*</span>
                <input name="name" required maxlength="50" placeholder="Nom de l'organisation">
            </label>
            <label>Type <span style="color:var(--danger)">*</span>
                <select name="type" required>
                    <option value="">— Choisir —</option>
                    ${typeOptions(ORG_TYPES)}
                </select>
            </label>
            <label>Email officiel
                <input name="official_email" type="email" placeholder="contact@org.be">
            </label>
            <label>Téléphone
                <input name="phone_contact" placeholder="+32 ...">
            </label>
            <label class="full">Nom du contact
                <input name="name_contact" placeholder="Prénom Nom">
            </label>
            <div class="full" id="create-org-error"></div>
            <div class="full actions">
                <button type="submit" class="primary">Créer</button>
            </div>
        </form>
    `;

    const { close } = showModal('Créer une organisation', body);

    document.getElementById('form-create-org').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const payload = Object.fromEntries([...fd.entries()].filter(([, v]) => v !== ''));
        const errEl = document.getElementById('create-org-error');
        errEl.innerHTML = '';

        try {
            await api.post('/organizations', payload);
            toast('Organisation créée.', 'ok');
            close();
            onSuccess();
        } catch (err) {
            errEl.innerHTML = notice(apiError(err), 'error');
        }
    });
}

// ── Bannir org ────────────────────────────────────────────────────────────────

function openBanModal(org, onSuccess) {
    const body = `
        <p>Vous allez bannir <strong>${esc(org.name)}</strong>. Cette organisation ne pourra plus soumettre de réservation.</p>
        <form id="form-ban-org" class="form-grid">
            <label class="full">Motif <span style="color:var(--danger)">*</span>
                <textarea name="reason" required maxlength="500" placeholder="Motif de l'interdiction…"></textarea>
            </label>
            <div class="full" id="ban-error"></div>
            <div class="full actions">
                <button type="submit" class="primary danger">Confirmer le ban</button>
            </div>
        </form>
    `;

    const { close } = showModal(`Bannir — ${org.name}`, body);

    document.getElementById('form-ban-org').addEventListener('submit', async e => {
        e.preventDefault();
        const reason = new FormData(e.target).get('reason');
        const errEl  = document.getElementById('ban-error');
        errEl.innerHTML = '';

        try {
            await api.post(`/organizations/${org.id}/ban`, { reason });
            toast(`${org.name} a été banni.`, 'danger');
            close();
            onSuccess();
        } catch (err) {
            errEl.innerHTML = notice(apiError(err), 'error');
        }
    });
}

async function doUnban(org, onSuccess) {
    try {
        await api.post(`/organizations/${org.id}/unban`);
        toast(`Ban levé pour ${org.name}.`, '');
        onSuccess();
    } catch (err) {
        toast(apiError(err), 'danger');
    }
}

// ── Demande de création (user externe) ────────────────────────────────────────

function openRequestOrgModal() {
    const body = `
        <form id="form-request-org" class="form-grid">
            <label class="full">Nom de l'organisation <span style="color:var(--danger)">*</span>
                <input name="organization_name" required maxlength="255" placeholder="Nom officiel">
            </label>
            <label>Type <span style="color:var(--danger)">*</span>
                <select name="organization_type" required>
                    <option value="">— Choisir —</option>
                    ${typeOptions(ORG_TYPES_EXTERNAL)}
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
            <div class="full" id="request-org-error"></div>
            <div class="full actions">
                <button type="submit" class="primary">Envoyer la demande</button>
            </div>
        </form>
    `;

    const { close } = showModal('Demande de création d\'organisation', body);

    document.getElementById('form-request-org').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const payload = Object.fromEntries([...fd.entries()].filter(([, v]) => v !== ''));
        const errEl = document.getElementById('request-org-error');
        errEl.innerHTML = '';

        try {
            await api.post('/org-registration-requests', payload);
            toast('Demande envoyée. Un agent communal la traitera prochainement.', '');
            close();
        } catch (err) {
            errEl.innerHTML = notice(apiError(err), 'error');
        }
    });
}
