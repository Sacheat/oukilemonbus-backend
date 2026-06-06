import { api, apiError } from '../api.js';
import { getUser, isInternal } from '../auth.js';
import { esc, badge, fmtDate, fmtTime, fmtDateTime, showModal, notice, noticeHtml, toast, paginationHtml } from '../ui.js';
import { currentQuery } from '../router.js';

const isCreate = () => window.location.hash.includes('/create');

export async function renderReservations(el) {
    if (isCreate()) {
        await renderCreateForm(el);
    } else {
        const { highlight } = currentQuery();
        await renderList(el, 1, {}, highlight ? parseInt(highlight, 10) : null);
    }
}

// ── List ──────────────────────────────────────────────────────────────────────

async function renderList(el, page, filters = {}, highlightId = null) {
    let orgs = [];
    try { const r = await api.get('/organizations'); orgs = r.data.data ?? []; } catch {}

    el.innerHTML = `
        <div class="split">
            <h1>Réservations</h1>
            <button class="primary" id="btn-create">+ Nouvelle réservation</button>
        </div>

        <div class="filters panel" style="margin-bottom:16px">
            <label>Statut
                <select id="f-status">
                    <option value="">Tous</option>
                    <option value="en_attente">En attente</option>
                    <option value="en_conflit">En conflit</option>
                    <option value="approuve">Approuvé</option>
                    <option value="confirme">Confirmé</option>
                    <option value="refuse">Refusé</option>
                    <option value="annule">Annulé</option>
                </select>
            </label>
            <label>Du <input type="date" id="f-from" value="${filters.date_from ?? ''}"></label>
            <label>Au <input type="date" id="f-to" value="${filters.date_to ?? ''}"></label>
            ${isInternal() ? `
            <label>Organisation
                <select id="f-org">
                    <option value="">Toutes</option>
                    ${orgs.map(o => `<option value="${o.id}" ${filters.organization_id == o.id ? 'selected' : ''}>${esc(o.name)}</option>`).join('')}
                </select>
            </label>` : ''}
            <button id="btn-filter">Filtrer</button>
        </div>

        <div class="panel">
            <div id="list-container"><div class="loading">Chargement…</div></div>
        </div>
    `;

    el.querySelector('#btn-create').addEventListener('click', () => { location.hash = '#/reservations/create'; });

    el.querySelector('#btn-filter').addEventListener('click', () => {
        const newFilters = {
            status: el.querySelector('#f-status')?.value || undefined,
            date_from: el.querySelector('#f-from')?.value || undefined,
            date_to: el.querySelector('#f-to')?.value || undefined,
            organization_id: el.querySelector('#f-org')?.value || undefined,
        };
        loadList(el.querySelector('#list-container'), 1, newFilters, null);
    });

    if (filters.status) el.querySelector('#f-status').value = filters.status;
    await loadList(el.querySelector('#list-container'), page, filters, highlightId);
}

async function loadList(container, page, filters = {}, highlightId = null) {
    container.innerHTML = '<div class="loading">Chargement…</div>';

    try {
        const { data } = await api.get('/reservations', { params: { page, per_page: 15, ...filters } });
        const items = data.data ?? [];
        const meta = data.meta ?? {};
        const internal = isInternal();

        if (!items.length) {
            container.innerHTML = '<p class="muted" style="padding:8px 0">Aucune réservation.</p>';
            return;
        }

        container.innerHTML = `
            <table>
                <thead><tr>
                    <th>#</th><th>Date</th><th>Organisation</th><th>Trajet</th><th>Horaire</th>
                    ${internal ? '<th>Chauffeur</th><th>Véhicule</th>' : ''}
                    <th>Statut</th><th>Actions</th>
                </tr></thead>
                <tbody id="tbody"></tbody>
            </table>
        `;

        const tbody = container.querySelector('#tbody');
        items.forEach(r => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><code>${r.id}</code>${r.recurrence_id ? ' <span class="badge brand" title="Récurrence">↻</span>' : ''}</td>
                <td>${fmtDate(r.trip_date)}</td>
                <td>${esc(r.organization?.name ?? '—')}</td>
                <td style="font-size:12px">
                    <div>${esc(r.pickup_location?.formatted_address ?? r.pickup_location?.city ?? '—')}</div>
                    <div class="muted">→ ${esc(r.destination_location?.formatted_address ?? r.destination_location?.city ?? '—')}</div>
                </td>
                <td style="font-size:12px">${fmtTime(r.departure_time)} – ${fmtTime(r.return_time)}</td>
                ${internal ? `
                    <td>${esc(r.driver ? (r.driver.licence_number ?? '#' + r.driver.id) : '—')}</td>
                    <td>${esc(r.vehicle ? (r.vehicle.licence_plate ?? '#' + r.vehicle.id) : '—')}</td>
                ` : ''}
                <td>
                    ${badge(r.status)}
                    ${passengerConfirmationBadge(r)}
                </td>
                <td><div class="actions" id="acts-${r.id}"></div></td>
            `;
            tbody.appendChild(tr);
            buildActions(tr.querySelector(`#acts-${r.id}`), r, container, page, filters);
        });

        if (highlightId) {
            tbody.querySelectorAll('tr').forEach(tr => {
                if (tr.querySelector('code')?.textContent.trim() === String(highlightId)) {
                    tr.style.outline = '2px solid var(--brand)';
                    tr.style.borderRadius = '4px';
                    setTimeout(() => tr.scrollIntoView({ behavior: 'smooth', block: 'center' }), 50);
                }
            });
        }

        const pag = paginationHtml(meta, p => loadList(container, p, filters));
        if (pag instanceof Element) container.appendChild(pag);

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}

function buildActions(actionsEl, r, container, page, filters) {
    const internal = isInternal();
    const status = r.status;

    const btn = (label, onClick, variant = '') => {
        const b = document.createElement('button');
        b.className = `sm ${variant}`;
        b.textContent = label;
        b.onclick = onClick;
        return b;
    };

    if (internal && ['en_attente', 'en_conflit'].includes(status)) {
        actionsEl.appendChild(btn('Approuver', () => openApproveModal(r, container, page, filters), 'primary'));
    }
    if (internal && ['en_attente', 'en_conflit', 'approuve'].includes(status)) {
        actionsEl.appendChild(btn('Refuser', () => openRejectModal(r, container, page, filters), 'danger'));
    }
    if (internal && status === 'approuve') {
        actionsEl.appendChild(btn('Assigner', () => openAssignModal(r, container, page, filters)));
    }
    if (internal && status === 'approuve' && r.driver && r.vehicle) {
        actionsEl.appendChild(btn('Confirmer', () => apiAction('PATCH', `/reservations/${r.id}/confirm`, {}, container, page, filters, 'Réservation confirmée'), 'primary'));
    }
    if (internal && ['en_attente', 'en_conflit'].includes(status)) {
        actionsEl.appendChild(btn('Décision collège', () => openCouncilModal(r, container, page, filters)));
    }
    if (internal && status === 'en_conflit') {
        actionsEl.appendChild(btn('Conflits', () => openConflictsModal(r)));
    }
    if (['en_attente', 'en_conflit'].includes(status)) {
        actionsEl.appendChild(btn('Modifier', () => openEditModal(r, container, page, filters)));
    }
    const user = getUser();
    const isRequesterOrResponsable = internal || user?.id === r.requested_by_user_id;
    if (isRequesterOrResponsable && !['refuse', 'annule'].includes(status)) {
        actionsEl.appendChild(btn('Passagers ✓', () => openConfirmPassengersModal(r, container, page, filters)));
    }
    if (!['refuse', 'annule'].includes(status)) {
        actionsEl.appendChild(btn('Annuler', () => openCancelModal(r, container, page, filters), 'danger'));
    }
}

// ── Action helpers ────────────────────────────────────────────────────────────

async function apiAction(method, url, body, container, page, filters, msg) {
    try {
        await api({ method, url, data: body });
        toast(msg);
        await loadList(container, page, filters);
    } catch (err) { toast(apiError(err), 'error'); }
}

function openApproveModal(r, container, page, filters) {
    const { el, close } = showModal(`Approuver — #${r.id}`, `
        <p class="muted" style="margin-top:0;font-size:12px">Commentaire facultatif :</p>
        <textarea id="approve-reason" placeholder="Raison ou commentaire…" style="width:100%"></textarea>
        <div id="approve-notice" style="margin-top:8px"></div>
    `);
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="a-cancel">Annuler</button><button type="button" id="a-ok" class="primary">Approuver</button>`;
    el.querySelector('.modal').appendChild(footer);
    el.querySelector('#a-cancel').addEventListener('click', close);
    el.querySelector('#a-ok').addEventListener('click', async () => {
        try {
            await api.patch(`/reservations/${r.id}/approve`, { reason: el.querySelector('#approve-reason').value || null });
            close(); toast('Réservation approuvée');
            await loadList(container, page, filters);
        } catch (err) { el.querySelector('#approve-notice').innerHTML = notice(apiError(err), 'error'); }
    });
}

function openRejectModal(r, container, page, filters) {
    const { el, close } = showModal(`Refuser — #${r.id}`, `
        <p class="muted" style="margin-top:0;font-size:12px">Motif de refus <strong>(obligatoire)</strong> :</p>
        <textarea id="reject-reason" placeholder="Expliquez le motif du refus…" style="width:100%" required></textarea>
        <div id="reject-notice" style="margin-top:8px"></div>
    `);
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="r-cancel">Annuler</button><button type="button" id="r-ok" class="danger">Refuser</button>`;
    el.querySelector('.modal').appendChild(footer);
    el.querySelector('#r-cancel').addEventListener('click', close);
    el.querySelector('#r-ok').addEventListener('click', async () => {
        const reason = el.querySelector('#reject-reason').value.trim();
        if (!reason) { el.querySelector('#reject-notice').innerHTML = notice('Le motif est obligatoire.', 'error'); return; }
        try {
            await api.patch(`/reservations/${r.id}/reject`, { reason });
            close(); toast('Réservation refusée');
            await loadList(container, page, filters);
        } catch (err) { el.querySelector('#reject-notice').innerHTML = notice(apiError(err), 'error'); }
    });
}

function openCancelModal(r, container, page, filters) {
    const { el, close } = showModal(`Annuler — #${r.id}`, `
        <p class="muted" style="margin-top:0;font-size:12px">Motif d'annulation (obligatoire) :</p>
        <textarea id="cancel-reason" placeholder="Motif…" style="width:100%"></textarea>
        <div id="cancel-notice" style="margin-top:8px"></div>
    `);
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="c-cancel">Fermer</button><button type="button" id="c-ok" class="danger">Annuler la réservation</button>`;
    el.querySelector('.modal').appendChild(footer);
    el.querySelector('#c-cancel').addEventListener('click', close);
    el.querySelector('#c-ok').addEventListener('click', async () => {
        const reason = el.querySelector('#cancel-reason').value.trim();
        if (!reason) { el.querySelector('#cancel-notice').innerHTML = notice('Le motif est obligatoire.', 'error'); return; }
        try {
            await api.patch(`/reservations/${r.id}/cancel`, { reason });
            close(); toast('Réservation annulée');
            await loadList(container, page, filters);
        } catch (err) { el.querySelector('#cancel-notice').innerHTML = notice(apiError(err), 'error'); }
    });
}

async function openAssignModal(r, container, page, filters) {
    let drivers = [], vehicles = [];
    try {
        const [d, v] = await Promise.all([api.get('/drivers', { params: { is_active: true } }), api.get('/vehicles', { params: { is_active: true } })]);
        drivers = d.data.data ?? [];
        vehicles = v.data.data ?? [];
    } catch {}

    const { el, close } = showModal(`Assigner — #${r.id}`, `
        <div class="form-grid">
            <label>Chauffeur
                <select id="m-driver">
                    <option value="">Aucun</option>
                    ${drivers.map(d => `<option value="${d.id}" ${r.driver?.id == d.id ? 'selected' : ''}>${esc(d.licence_number ?? '#' + d.id)}</option>`).join('')}
                </select>
            </label>
            <label>Véhicule
                <select id="m-vehicle">
                    <option value="">Aucun</option>
                    ${vehicles.map(v => `<option value="${v.id}" ${r.vehicle?.id == v.id ? 'selected' : ''}>${esc(v.licence_plate ?? '#' + v.id)} (${v.capacity} pl.)</option>`).join('')}
                </select>
            </label>
        </div>
        <div id="assign-notice" style="margin-top:10px"></div>
    `);
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="as-cancel">Annuler</button><button type="button" id="as-ok" class="primary">Assigner</button>`;
    el.querySelector('.modal').appendChild(footer);
    el.querySelector('#as-cancel').addEventListener('click', close);
    el.querySelector('#as-ok').addEventListener('click', async () => {
        try {
            await api.patch(`/reservations/${r.id}/assign`, {
                driver_id: el.querySelector('#m-driver').value || null,
                vehicle_id: el.querySelector('#m-vehicle').value || null,
            });
            close(); toast('Assignation effectuée');
            await loadList(container, page, filters);
        } catch (err) { el.querySelector('#assign-notice').innerHTML = notice(apiError(err), 'error'); }
    });
}

async function openCouncilModal(r, container, page, filters) {
    let existing = null;
    try { const { data } = await api.get('/council-decisions', { params: { reservation_id: r.id } }); existing = (data.data ?? []).find(d => d.reservation_id === r.id) ?? null; } catch {}

    const { el, close } = showModal(`Décision du collège — #${r.id}`, `
        <div class="form-grid">
            <label>Statut
                <select id="cd-status">
                    <option value="en_attente" ${!existing || existing.decision_status === 'en_attente' ? 'selected' : ''}>En attente</option>
                    <option value="approuve" ${existing?.decision_status === 'approuve' ? 'selected' : ''}>Approuvée</option>
                    <option value="refuse" ${existing?.decision_status === 'refuse' ? 'selected' : ''}>Refusée</option>
                </select>
            </label>
            <label>Date
                <input type="date" id="cd-date" value="${existing?.decision_date?.slice(0,10) ?? new Date().toISOString().slice(0,10)}">
            </label>
            <label class="full">Notes
                <textarea id="cd-notes">${esc(existing?.notes ?? '')}</textarea>
            </label>
        </div>
        <div id="cd-notice" style="margin-top:10px"></div>
    `);
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="cd-cancel">Annuler</button><button type="button" id="cd-save" class="primary">Enregistrer</button>`;
    el.querySelector('.modal').appendChild(footer);
    el.querySelector('#cd-cancel').addEventListener('click', close);
    el.querySelector('#cd-save').addEventListener('click', async () => {
        const body = { decision_status: el.querySelector('#cd-status').value, decision_date: el.querySelector('#cd-date').value, notes: el.querySelector('#cd-notes').value };
        try {
            if (existing) await api.patch(`/council-decisions/${existing.id}`, body);
            else await api.post('/council-decisions', { reservation_id: r.id, ...body });
            close(); toast('Décision enregistrée');
            await loadList(container, page, filters);
        } catch (err) { el.querySelector('#cd-notice').innerHTML = notice(apiError(err), 'error'); }
    });
}

async function openConflictsModal(r) {
    const { el } = showModal(`Conflits — #${r.id}`, '<div class="loading">Chargement…</div>');
    try {
        const { data } = await api.get(`/reservations/${r.id}/conflicts`);
        const conflicts = data.data ?? [];
        const body = el.querySelector('.modal-body');
        if (!conflicts.length) { body.innerHTML = '<p class="muted">Aucun conflit actif.</p>'; return; }
        body.innerHTML = `<div class="stack">${conflicts.map(c => `
            <div class="conflict-item" style="display:flex;justify-content:space-between;align-items:start;gap:8px">
                <div>
                    <strong>${esc(c.type ?? '—')}</strong>
                    ${c.message ? `<p style="margin:3px 0 0;color:var(--danger)">${esc(c.message)}</p>` : ''}
                </div>
                <button class="sm danger" data-cid="${c.id}">Résoudre</button>
            </div>
        `).join('')}</div>`;
        body.querySelectorAll('[data-cid]').forEach(btn => {
            btn.addEventListener('click', async () => {
                try { await api.patch(`/reservations/${r.id}/conflicts/${btn.dataset.cid}/resolve`); btn.closest('.conflict-item').remove(); toast('Conflit résolu'); }
                catch (err) { toast(apiError(err), 'error'); }
            });
        });
    } catch (err) { el.querySelector('.modal-body').innerHTML = notice(apiError(err), 'error'); }
}

// ── Edit reservation ──────────────────────────────────────────────────────────

function openEditModal(r, container, page, filters) {
    const tripDate = r.trip_date?.slice(0, 10) ?? '';
    const dep = r.departure_time?.slice(0, 5) ?? '';
    const ret = r.return_time?.slice(0, 5) ?? '';

    const { el, close } = showModal(`Modifier la réservation #${r.id}`, `
        <p class="muted" style="margin-top:0;font-size:12px">
            Les lieux de départ et destination ne sont pas modifiables ici.
        </p>
        <div class="form-grid">
            <label>Date du trajet
                <input type="date" id="e-date" value="${tripDate}">
            </label>
            <label></label>
            <label>Heure de départ
                <input type="time" id="e-dep" value="${dep}">
            </label>
            <label>Heure de retour
                <input type="time" id="e-ret" value="${ret}">
            </label>
            <label>Adultes
                <input type="number" id="e-adults" value="${r.nb_adults ?? 1}" min="1">
            </label>
            <label>Enfants
                <input type="number" id="e-children" value="${r.nb_children ?? 0}" min="0">
            </label>
            <label class="full">Objet du trajet
                <textarea id="e-purpose" style="width:100%">${esc(r.trip_purpose ?? '')}</textarea>
            </label>
        </div>
        <div id="edit-notice" style="margin-top:8px"></div>
    `);
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="e-cancel">Annuler</button><button type="button" id="e-ok" class="primary">Enregistrer</button>`;
    el.querySelector('.modal').appendChild(footer);
    el.querySelector('#e-cancel').addEventListener('click', close);
    el.querySelector('#e-ok').addEventListener('click', async () => {
        const body = {
            trip_date:      el.querySelector('#e-date').value || undefined,
            departure_time: el.querySelector('#e-dep').value  || undefined,
            return_time:    el.querySelector('#e-ret').value  || undefined,
            nb_adults:      parseInt(el.querySelector('#e-adults').value, 10),
            nb_children:    parseInt(el.querySelector('#e-children').value, 10),
            trip_purpose:   el.querySelector('#e-purpose').value.trim() || undefined,
        };
        try {
            await api.patch(`/reservations/${r.id}`, body);
            close();
            toast('Réservation modifiée');
            await loadList(container, page, filters, null);
        } catch (err) {
            el.querySelector('#edit-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

// ── Passenger confirmation ────────────────────────────────────────────────────

function passengerConfirmationBadge(r) {
    if (['refuse', 'annule'].includes(r.status)) return '';
    if (r.passengers_confirmed_at) {
        const d = new Date(r.passengers_confirmed_at);
        const label = d.toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric' });
        return `<span class="badge ok" style="font-size:10px;display:block;margin-top:3px" title="Confirmé le ${label}">✓ Passagers confirmés</span>`;
    }
    if (['approuve', 'confirme'].includes(r.status)) {
        return `<span class="badge warning" style="font-size:10px;display:block;margin-top:3px">⚠ À confirmer</span>`;
    }
    return '';
}

function openConfirmPassengersModal(r, container, page, filters) {
    const { el, close } = showModal(`Passagers — Réservation #${r.id}`, `
        <p class="muted" style="margin-top:0;font-size:12px">
            Mettez à jour si besoin, puis confirmez. La feuille de route sera régénérée si le trajet est aujourd'hui.
        </p>
        <div class="form-grid">
            <label>Adultes
                <input type="number" id="cp-adults" value="${r.nb_adults ?? 1}" min="1">
            </label>
            <label>Enfants
                <input type="number" id="cp-children" value="${r.nb_children ?? 0}" min="0">
            </label>
            <label class="full">Objet du trajet
                <textarea id="cp-purpose" style="width:100%">${esc(r.trip_purpose ?? '')}</textarea>
            </label>
        </div>
        <div id="cp-notice" style="margin-top:8px"></div>
    `);
    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="cp-cancel">Annuler</button><button type="button" id="cp-ok" class="primary">✓ Confirmer les passagers</button>`;
    el.querySelector('.modal').appendChild(footer);
    el.querySelector('#cp-cancel').addEventListener('click', close);
    el.querySelector('#cp-ok').addEventListener('click', async () => {
        const body = {
            nb_adults: parseInt(el.querySelector('#cp-adults').value, 10),
            nb_children: parseInt(el.querySelector('#cp-children').value, 10),
            trip_purpose: el.querySelector('#cp-purpose').value.trim() || null,
        };
        try {
            await api.patch(`/reservations/${r.id}/confirm-passengers`, body);
            close();
            toast('Passagers confirmés ✓');
            await loadList(container, page, filters, null);
        } catch (err) {
            el.querySelector('#cp-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

// ── Location autocomplete (ORS-powered) ──────────────────────────────────────
//
// Flow:
//  1. User types → debounced GET /api/locations/suggest?q=... → ORS autocomplete
//  2. User picks a suggestion → POST /api/locations with coordinates already set
//     (backend skips geocoding + deduplicates by place_id)
//  3. The resulting location_id is stored and passed to the reservation form.

function createLocationSearch(parentEl, inputId) {
    let selectedId = null;
    let debounceTimer = null;

    parentEl.innerHTML = `
        <div class="loc-wrap">
            <input type="text" id="input-${inputId}" placeholder="Saisir une adresse (ex: Rue de la Loi, Bruxelles)…" autocomplete="off">
            <div class="loc-dropdown" id="drop-${inputId}"></div>
            <div id="tag-${inputId}"></div>
        </div>
    `;

    const input = parentEl.querySelector(`#input-${inputId}`);
    const dropdown = parentEl.querySelector(`#drop-${inputId}`);
    const tagEl = parentEl.querySelector(`#tag-${inputId}`);

    // Called once the user has confirmed a suggestion and we have a DB location_id
    function markSelected(locationId, label) {
        selectedId = locationId;
        input.value = '';
        input.style.display = 'none';
        dropdown.classList.remove('open');
        tagEl.innerHTML = `
            <div class="loc-selected-tag">
                ${esc(label)}
                <button type="button" data-clear>✕</button>
            </div>
        `;
        tagEl.querySelector('[data-clear]').addEventListener('click', () => {
            selectedId = null;
            input.style.display = '';
            input.value = '';
            tagEl.innerHTML = '';
            input.focus();
        });
    }

    // Save the ORS suggestion to the DB (or get existing) and mark it selected
    async function confirmSuggestion(suggestion) {
        dropdown.classList.remove('open');
        dropdown.innerHTML = '<div class="loc-item muted">Enregistrement…</div>';
        dropdown.classList.add('open');
        try {
            const { data } = await api.post('/locations', {
                formatted_address: suggestion.formatted_address,
                street:            suggestion.street ?? null,
                street_number:     suggestion.street_number ?? null,
                postal_code:       suggestion.postal_code ?? null,
                city:              suggestion.city ?? null,
                country:           suggestion.country ?? 'Belgique',
                latitude:          suggestion.latitude,
                longitude:         suggestion.longitude,
                place_id:          suggestion.place_id ?? null,
            });
            const loc = data.data;
            dropdown.classList.remove('open');
            markSelected(loc.id, loc.formatted_address ?? suggestion.formatted_address);
        } catch (err) {
            dropdown.innerHTML = `<div class="loc-item" style="color:var(--danger)">Erreur : ${esc(apiError(err))}</div>`;
        }
    }

    async function suggest(q) {
        dropdown.innerHTML = '<div class="loc-item muted">Recherche…</div>';
        dropdown.classList.add('open');

        const { data } = await api.get('/locations/suggest', { params: { q } });
        const suggestions = data.data ?? [];

        if (!suggestions.length) {
            dropdown.innerHTML = `<div class="loc-item muted">Aucun résultat ORS pour « ${esc(q)} ».<br><small>Vérifiez la clé ORS_API_KEY dans .env</small></div>`;
            return;
        }

        dropdown.innerHTML = suggestions.map((s, i) => `
            <div class="loc-item" data-idx="${i}">
                <div class="loc-item-addr">${esc(s.formatted_address ?? s.label)}</div>
                ${s.city ? `<div class="loc-item-city">${esc(s.city)}${s.postal_code ? ', ' + esc(s.postal_code) : ''}</div>` : ''}
            </div>
        `).join('');

        dropdown.querySelectorAll('[data-idx]').forEach(item => {
            item.addEventListener('click', () => confirmSuggestion(suggestions[item.dataset.idx]));
        });
    }

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(debounceTimer);
        if (q.length < 3) { dropdown.classList.remove('open'); return; }
        debounceTimer = setTimeout(() => suggest(q).catch(() => {
            dropdown.innerHTML = '<div class="loc-item muted">Erreur de connexion ORS.</div>';
        }), 300);
    });

    input.addEventListener('focus', () => {
        if (input.value.trim().length >= 3 && !selectedId) dropdown.classList.add('open');
    });

    document.addEventListener('click', e => {
        if (!parentEl.contains(e.target)) dropdown.classList.remove('open');
    });

    return { getLocationId: () => selectedId };
}

// ── Preview display ───────────────────────────────────────────────────────────

const CONFLICT_TYPE_LABELS = {
    schedule_overlap:              'Chevauchement de planning',
    no_driver_available:           'Aucun chauffeur disponible',
    no_vehicle_available:          'Aucun véhicule disponible',
    departure_before_allowed_time: 'Départ avant l\'heure autorisée',
    return_after_allowed_time:     'Retour après l\'heure autorisée',
    duration_over_allowed_limit:   'Durée maximale dépassée',
    impossible_time_window:        'Créneau impossible',
    distance_not_calculated:       'Distance non calculée',
    quota_exceeded:                'Quota dépassé',
    vehicle_capacity_unavailable:  'Aucun véhicule de capacité suffisante',
    departure_before_08h:          'Départ avant 08h00',
    return_after_17h:              'Retour après 17h00',
};

// Renvoie true si le créneau suggéré dépasse les horaires autorisés pour les
// organisations soumises aux restrictions (toutes sauf ecole_communale).
// approval_type === 'auto' identifie les orgs exemptées (écoles, personnel communal).
function nextSlotExceedsAllowedHours(approval_type, nextDeparture, nextReturn) {
    if (approval_type === 'auto') return false;
    const MAX_RETURN = '16:00';
    const MIN_DEPART = '07:30';
    const dep = (nextDeparture ?? '').slice(0, 5);
    const ret = (nextReturn   ?? '').slice(0, 5);
    return (dep && dep < MIN_DEPART) || (ret && ret > MAX_RETURN);
}

function renderPreview(data, isRecurrence = false) {
    if (isRecurrence) return renderRecurrencePreview(data);

    const { is_allowed, distance_km, estimated_duration_min, availability, conflicts = [], warnings = [], status, approval_type, estimated_occupation } = data;

    const statusColor = { en_attente: 'warning', approuve: 'ok', en_conflit: 'danger' };
    const statusLabel = { en_attente: 'En attente de validation', approuve: 'Approbation automatique', en_conflit: 'Créée avec conflits' };

    const nextDep = availability?.next_available_departure_time;
    const nextRet = availability?.next_available_return_time;
    const slotExceedsHours = nextSlotExceedsAllowedHours(approval_type, nextDep, nextRet);

    return `
        <div class="preview-box">
            <div class="preview-section">
                <div class="preview-section-title">Résultat</div>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    ${badge(status, statusColor[status])} <span>${esc(statusLabel[status] ?? status)}</span>
                    ${distance_km ? `<span class="muted">· ${distance_km} km · ${estimated_duration_min} min trajet</span>` : ''}
                </div>
            </div>

            ${estimated_occupation ? `
            <div class="preview-section">
                <div class="preview-section-title">Fenêtre d'occupation du bus</div>
                <div style="font-size:12px">
                    ${fmtDateTime(estimated_occupation.start_at)} → ${fmtDateTime(estimated_occupation.end_at)}
                    <span class="muted">(${estimated_occupation.duration_min} min total)</span>
                </div>
            </div>` : ''}

            ${!availability?.is_available ? `
            <div class="preview-section">
                <div class="next-slot-box">
                    <strong>⚠ Bus non disponible à ce créneau</strong>
                    ${availability.message ? `<p style="margin:4px 0 0">${esc(availability.message)}</p>` : ''}
                    ${nextDep ? `
                    <p style="margin:6px 0 0">
                        Prochain départ disponible : <strong>${esc(nextDep)}</strong>
                        ${nextRet ? ` – retour : <strong>${esc(nextRet)}</strong>` : ''}
                    </p>
                    ${slotExceedsHours ? `
                    <p style="margin:6px 0 0;color:var(--warning);font-size:12px">
                        ⚠ Ce créneau dépasse les horaires autorisés (départ ≥ 07h30, retour ≤ 16h00).
                        Il peut être accepté moyennant motivation auprès du collège communal.
                    </p>` : ''}` : ''}
                </div>
            </div>` : ''}

            ${warnings.length ? `
            <div class="preview-section">
                <div class="preview-section-title" style="color:var(--warning)">⚠ ${warnings.length} avertissement(s) — motivation requise</div>
                ${warnings.map(w => `
                    <div style="border-left:3px solid var(--warning);padding:6px 10px;background:var(--warning-soft);border-radius:0 4px 4px 0;font-size:12px;margin-bottom:4px">
                        <strong>${esc(CONFLICT_TYPE_LABELS[w.type] ?? w.type ?? '—')}</strong>
                        ${w.message ? `<div style="margin-top:2px">${esc(w.message)}</div>` : ''}
                    </div>
                `).join('')}
                <div style="font-size:11px;color:var(--warning);margin-top:4px">La réservation sera créée en attente. La décision du collège communal est requise pour approbation.</div>
            </div>` : ''}

            ${conflicts.length ? `
            <div class="preview-section">
                <div class="preview-section-title">${conflicts.length} conflit(s) bloquant(s) détecté(s)</div>
                ${conflicts.map(c => `
                    <div class="conflict-item">
                        <strong>${esc(CONFLICT_TYPE_LABELS[c.type] ?? c.type ?? '—')}</strong>
                        ${c.message ? `<div>${esc(c.message)}</div>` : ''}
                    </div>
                `).join('')}
            </div>` : `
            <div class="preview-section">
                <div style="color:var(--ok)">✓ Aucun conflit bloquant — réservation possible</div>
            </div>`}
        </div>
    `;
}

function renderRecurrencePreview(data) {
    const { valid_dates = [], skipped_dates = [], conflicts_by_date = {}, total_valid_occurrences, total_skipped } = data;
    const conflictDates = Object.keys(conflicts_by_date);

    return `
        <div class="recurrence-preview">
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:10px">
                <span><strong>${total_valid_occurrences}</strong> occurrence(s) valide(s)</span>
                ${total_skipped ? `<span class="muted">${total_skipped} date(s) ignorée(s)</span>` : ''}
                ${conflictDates.length ? `<span style="color:var(--warning)">${conflictDates.length} date(s) avec conflits</span>` : ''}
            </div>

            ${valid_dates.length ? `
            <div style="margin-bottom:8px">
                <div class="preview-section-title" style="font-size:11px">Dates valides</div>
                <div>${valid_dates.slice(0, 20).map(d => `<span class="date-chip">${fmtDate(d)}</span>`).join('')}
                ${valid_dates.length > 20 ? `<span class="muted" style="font-size:11px">… et ${valid_dates.length - 20} autres</span>` : ''}
                </div>
            </div>` : ''}

            ${skipped_dates.length ? `
            <details style="margin-top:8px">
                <summary style="cursor:pointer;font-size:12px;color:var(--muted)">${skipped_dates.length} date(s) ignorée(s) ▸</summary>
                <div style="margin-top:6px">
                    ${skipped_dates.map(s => `<div style="font-size:11px;padding:2px 0"><span style="color:var(--danger)">✕ ${fmtDate(s.date)}</span> — ${esc(s.reason)}</div>`).join('')}
                </div>
            </details>` : ''}

            ${conflictDates.length ? `
            <details style="margin-top:8px">
                <summary style="cursor:pointer;font-size:12px;color:var(--warning)">${conflictDates.length} date(s) avec conflits potentiels ▸</summary>
                <div style="margin-top:6px">
                    ${conflictDates.map(d => `<div style="font-size:11px;padding:2px 0;color:var(--warning)">⚠ ${fmtDate(d)}</div>`).join('')}
                </div>
            </details>` : ''}
        </div>
    `;
}

// ── Create form ───────────────────────────────────────────────────────────────

async function renderCreateForm(el) {
    let organizations = [];
    try { const r = await api.get('/organizations'); organizations = r.data.data ?? []; } catch {}

    el.innerHTML = `
        <div class="split">
            <h1>Nouvelle réservation</h1>
            <button onclick="location.hash='#/reservations'">← Retour</button>
        </div>

        <div class="panel">
            <div id="create-notice"></div>
            <form id="res-form" novalidate>
                <div class="form-grid">
                    <label class="full">Organisation
                        <select name="organization_id" id="org-select" required>
                            <option value="">Sélectionner une organisation…</option>
                            ${organizations.map(o => `<option value="${o.id}" data-school="${o.type === 'ecole_communale' ? '1' : '0'}">${esc(o.name)}</option>`).join('')}
                        </select>
                        <div class="field-error" id="err-organization_id"></div>
                    </label>

                    <label>Lieu de départ
                        <div id="pickup-search"></div>
                        <div class="field-error" id="err-pickup_location_id"></div>
                    </label>

                    <label>Destination
                        <div id="dest-search"></div>
                        <div class="field-error" id="err-destination_location_id"></div>
                    </label>

                    <div id="date-section" class="full">
                        <div class="form-grid">
                            <label>Date du trajet
                                <input type="date" id="trip-date" name="trip_date">
                                <div class="field-error" id="err-trip_date"></div>
                            </label>
                        </div>
                    </div>

                    <label>Départ
                        <input type="time" name="departure_time" id="departure_time">
                        <div class="field-error" id="err-departure_time"></div>
                    </label>

                    <label>Retour
                        <input type="time" name="return_time" id="return_time">
                        <div class="field-error" id="err-return_time"></div>
                    </label>

                    <label>Enfants
                        <input type="number" name="nb_children" value="0" min="0">
                        <div class="field-error" id="err-nb_children"></div>
                    </label>

                    <label>Adultes
                        <input type="number" name="nb_adults" value="1" min="1">
                        <div class="field-error" id="err-nb_adults"></div>
                    </label>

                    <label>Type d'activité
                        <select name="activity_type" id="activity_type">
                            <option value="standard">Standard</option>
                            <option value="gymnastique">Gymnastique</option>
                            <option value="psychomotricite">Psychomotricité</option>
                        </select>
                    </label>

                    <label class="full">Objet du trajet
                        <textarea name="trip_purpose" placeholder="Décrivez l'objet du déplacement…"></textarea>
                        <div class="field-error" id="err-trip_purpose"></div>
                    </label>
                </div>

                <div id="recurrence-block" style="display:none">
                    <hr style="border:none;border-top:1px solid var(--line);margin:16px 0">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                        <input type="checkbox" id="rec-toggle" style="width:auto">
                        <label for="rec-toggle" style="font-weight:650;color:var(--brand);cursor:pointer;display:inline">
                            Réservation récurrente (école communale)
                        </label>
                    </div>
                    <div id="rec-fields" style="display:none">
                        <div class="form-grid">
                            <label>Fréquence
                                <select id="rec-freq">
                                    <option value="hebdomadaire">Hebdomadaire</option>
                                    <option value="bihebdomadaire">Bihebdomadaire</option>
                                    <option value="mensuel">Mensuel</option>
                                </select>
                            </label>
                            <div id="rec-days-label" style="display:flex;flex-direction:column;gap:4px">
                                <span style="font-size:12px;font-weight:600;color:var(--muted)">Jours de la semaine</span>
                                <div class="days-grid" id="days-grid">
                                    ${['Lun','Mar','Mer','Jeu','Ven'].map((d, i) => `
                                        <button type="button" class="day-toggle on" data-day="${i+1}">${d}</button>
                                    `).join('')}
                                </div>
                            </div>
                            <label>Date de début
                                <input type="date" id="rec-start">
                            </label>
                            <label>Date de fin
                                <input type="date" id="rec-end">
                            </label>
                        </div>
                        <div id="rec-preview-result" style="margin-top:10px"></div>
                    </div>
                </div>

                <div class="actions" style="margin-top:16px">
                    <button type="button" id="btn-preview">Prévisualiser</button>
                    <button type="submit" class="primary" id="btn-submit">Créer la réservation</button>
                </div>
            </form>

            <div id="preview-result"></div>
        </div>
    `;

    // Init location search components
    const pickupSearch = createLocationSearch(el.querySelector('#pickup-search'), 'pickup', 'Lieu de départ');
    const destSearch = createLocationSearch(el.querySelector('#dest-search'), 'dest', 'Destination');

    const form = el.querySelector('#res-form');
    const noticeEl = el.querySelector('#create-notice');
    const previewEl = el.querySelector('#preview-result');
    const orgSelect = el.querySelector('#org-select');
    const recurrenceBlock = el.querySelector('#recurrence-block');
    const recFields = el.querySelector('#rec-fields');
    const recToggle = el.querySelector('#rec-toggle');

    // Show recurrence block only for communal schools
    orgSelect.addEventListener('change', () => {
        const opt = orgSelect.selectedOptions[0];
        const isSchool = opt?.dataset.school === '1';
        recurrenceBlock.style.display = isSchool ? '' : 'none';
        if (!isSchool) recToggle.checked = false;
        recFields.style.display = 'none';
    });

    // Toggle recurrence fields
    recToggle.addEventListener('change', () => {
        recFields.style.display = recToggle.checked ? '' : 'none';
        el.querySelector('#date-section').style.display = recToggle.checked ? 'none' : '';
        el.querySelector('#btn-submit').textContent = recToggle.checked ? 'Créer la récurrence' : 'Créer la réservation';
    });

    // Day toggle buttons
    el.querySelectorAll('.day-toggle').forEach(btn => {
        btn.addEventListener('click', () => btn.classList.toggle('on'));
    });

    // Frequency change: hide days for monthly
    el.querySelector('#rec-freq').addEventListener('change', e => {
        el.querySelector('#rec-days-label').style.display = e.target.value === 'mensuel' ? 'none' : '';
    });

    // Preview
    el.querySelector('#btn-preview').addEventListener('click', async () => {
        clearFieldErrors(el);
        previewEl.innerHTML = '<div class="loading">Calcul en cours…</div>';
        const body = buildFormBody(form, pickupSearch, destSearch, el);
        if (!body) { previewEl.innerHTML = ''; return; }

        const isRec = recToggle.checked;
        try {
            const endpoint = isRec ? '/recurrences/preview' : '/reservations/preview';
            const { data } = await api.post(endpoint, body);
            previewEl.innerHTML = renderPreview(data.data ?? data, isRec);
        } catch (err) {
            if (err.response?.status === 422) {
                const orphans = displayFieldErrors(el, err.response.data.errors ?? {});
                previewEl.innerHTML = noticeHtml(
                    'Corrigez les erreurs dans le formulaire.' +
                    (orphans.length ? '<ul style="margin:6px 0 0;padding-left:16px">' + orphans.map(o => `<li>${esc(o)}</li>`).join('') + '</ul>' : ''),
                    'error'
                );
            } else {
                previewEl.innerHTML = notice(apiError(err), 'error');
            }
        }
    });

    // Submit
    form.addEventListener('submit', async e => {
        e.preventDefault();
        clearFieldErrors(el);
        noticeEl.innerHTML = '';
        const isRec = recToggle.checked;
        const body = buildFormBody(form, pickupSearch, destSearch, el);
        if (!body) return;

        const submitBtn = el.querySelector('#btn-submit');
        submitBtn.disabled = true;

        try {
            const endpoint = isRec ? '/recurrences' : '/reservations';
            await api.post(endpoint, body);
            noticeEl.innerHTML = notice(isRec ? 'Récurrence créée avec succès !' : 'Réservation créée avec succès !');
            setTimeout(() => { location.hash = '#/reservations'; }, 1200);
        } catch (err) {
            if (err.response?.status === 422) {
                const orphans = displayFieldErrors(el, err.response.data.errors ?? {});
                noticeEl.innerHTML = noticeHtml(
                    'Corrigez les erreurs dans le formulaire.' +
                    (orphans.length ? '<ul style="margin:6px 0 0;padding-left:16px">' + orphans.map(o => `<li>${esc(o)}</li>`).join('') + '</ul>' : ''),
                    'error'
                );
            } else {
                noticeEl.innerHTML = notice(apiError(err), 'error');
            }
            submitBtn.disabled = false;
        }
    });

    // Recurrence preview on change
    ['#rec-freq', '#rec-start', '#rec-end'].forEach(sel => {
        el.querySelector(sel)?.addEventListener('change', () => debounceRecPreview(el, form, pickupSearch, destSearch));
    });
    el.querySelectorAll('.day-toggle').forEach(btn => {
        btn.addEventListener('click', () => debounceRecPreview(el, form, pickupSearch, destSearch));
    });
}

let _recDebounce = null;
function debounceRecPreview(el, form, pickupSearch, destSearch) {
    if (!el.querySelector('#rec-toggle')?.checked) return;
    clearTimeout(_recDebounce);
    _recDebounce = setTimeout(async () => {
        const body = buildFormBody(form, pickupSearch, destSearch, el);
        if (!body) return;
        const resultEl = el.querySelector('#rec-preview-result');
        resultEl.innerHTML = '<div class="loading" style="padding:8px 0">Calcul…</div>';
        try {
            const { data } = await api.post('/recurrences/preview', body);
            resultEl.innerHTML = renderRecurrencePreview(data.data ?? data);
        } catch { resultEl.innerHTML = ''; }
    }, 600);
}

function buildFormBody(form, pickupSearch, destSearch, el) {
    const fd = new FormData(form);
    const isRec = el.querySelector('#rec-toggle')?.checked ?? false;

    const pickupId = pickupSearch.getLocationId();
    const destId = destSearch.getLocationId();

    if (!fd.get('organization_id')) { el.querySelector('#err-organization_id').textContent = 'Requis.'; return null; }
    if (!pickupId) { el.querySelector('#err-pickup_location_id').textContent = 'Sélectionnez un lieu de départ.'; return null; }
    if (!destId) { el.querySelector('#err-destination_location_id').textContent = 'Sélectionnez une destination.'; return null; }

    const base = {
        organization_id: fd.get('organization_id'),
        pickup_location_id: pickupId,
        destination_location_id: destId,
        departure_time: fd.get('departure_time'),
        return_time: fd.get('return_time'),
        nb_children: fd.get('nb_children'),
        nb_adults: fd.get('nb_adults'),
        activity_type: fd.get('activity_type'),
        trip_purpose: fd.get('trip_purpose'),
    };

    if (isRec) {
        const days = [...el.querySelectorAll('.day-toggle.on')].map(b => parseInt(b.dataset.day));
        return {
            ...base,
            frequency: el.querySelector('#rec-freq').value,
            days_of_week: days,
            start_date: el.querySelector('#rec-start').value,
            end_date: el.querySelector('#rec-end').value,
        };
    } else {
        return { ...base, trip_date: fd.get('trip_date') };
    }
}

// Laravel retourne parfois des clés brutes quand les traductions ne sont pas publiées
const VALIDATION_MSGS = {
    'validation.required':       'Ce champ est obligatoire.',
    'validation.after':          "L'heure de retour doit être postérieure au départ.",
    'validation.after_or_equal': 'La date doit être égale ou postérieure.',
    'validation.before':         'La date doit être antérieure.',
    'validation.date':           "La date n'est pas valide.",
    'validation.date_format':    'Le format est invalide (HH:MM).',
    'validation.integer':        'Doit être un nombre entier.',
    'validation.numeric':        'Doit être un nombre.',
    'validation.min.numeric':    'La valeur est trop petite.',
    'validation.max.numeric':    'La valeur est trop grande.',
    'validation.min.string':     'Trop court.',
    'validation.max.string':     'Trop long (max 500 caractères).',
    'validation.exists':         'La valeur sélectionnée est invalide.',
    'validation.different':      'La destination doit être différente du départ.',
    'validation.enum':           'Valeur non autorisée.',
    'validation.string':         'Doit être du texte.',
    'validation.between.numeric':'La valeur est hors limites.',
};

function humanMsg(raw) {
    return VALIDATION_MSGS[raw] ?? raw;
}

function clearFieldErrors(el) {
    el.querySelectorAll('.field-error').forEach(e => { e.textContent = ''; });
    el.querySelectorAll('.input-error').forEach(i => i.classList.remove('input-error'));
}

// Affiche les erreurs champ par champ + retourne un HTML listant les erreurs sans champ associé
function displayFieldErrors(el, errors) {
    const orphans = [];

    Object.entries(errors).forEach(([field, msgs]) => {
        const raw = Array.isArray(msgs) ? msgs[0] : msgs;
        const msg = humanMsg(raw);
        const errEl = el.querySelector(`#err-${field}`);

        if (errEl) {
            errEl.textContent = msg;
            // Bordure rouge sur l'input associé (by name ou by id)
            const input = el.querySelector(`[name="${field}"]`) ?? el.querySelector(`#${field}`);
            if (input) input.classList.add('input-error');
        } else {
            orphans.push(`${field} : ${msg}`);
        }
    });

    // Scroll vers la première erreur visible
    const firstErr = el.querySelector('.field-error:not(:empty)');
    firstErr?.scrollIntoView({ behavior: 'smooth', block: 'center' });

    return orphans;
}
