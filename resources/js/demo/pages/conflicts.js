import { api, apiError } from '../api.js';
import { esc, badge, fmtDate, fmtTime, showModal, notice, toast } from '../ui.js';
import { navigate } from '../router.js';

const CONFLICT_META = {
    schedule_overlap:              { icon: '🔄', label: 'Chevauchement',         derogation: false },
    no_driver_available:           { icon: '👤', label: 'Chauffeur indispo',      derogation: false },
    no_vehicle_available:          { icon: '🚌', label: 'Véhicule indispo',       derogation: false },
    departure_before_allowed_time: { icon: '⏰', label: 'Départ trop tôt',        derogation: true  },
    return_after_allowed_time:     { icon: '⏰', label: 'Retour trop tard',        derogation: true  },
    duration_over_allowed_limit:   { icon: '⏱', label: 'Durée dépassée',          derogation: true  },
    impossible_time_window:        { icon: '🚫', label: 'Créneau impossible',      derogation: false },
    distance_not_calculated:       { icon: '📍', label: 'Distance inconnue',       derogation: false },
    departure_before_08h:          { icon: '⏰', label: 'Départ avant 08h',        derogation: true  },
    return_after_17h:              { icon: '⏰', label: 'Retour après 17h',        derogation: true  },
    quota_exceeded:                { icon: '📊', label: 'Quota dépassé',           derogation: false },
    vehicle_capacity_unavailable:  { icon: '🚌', label: 'Capacité insuffisante',   derogation: false },
};

export async function renderConflicts(el) {
    el.innerHTML = `
        <div class="split">
            <h1>Gestion des conflits</h1>
            <button id="btn-refresh">↺ Actualiser</button>
        </div>
        <div id="conflicts-body"><div class="loading">Chargement…</div></div>
    `;

    el.querySelector('#btn-refresh').addEventListener('click', () => loadConflicts(el.querySelector('#conflicts-body')));

    await loadConflicts(el.querySelector('#conflicts-body'));
}

async function loadConflicts(container) {
    container.innerHTML = '<div class="loading">Chargement…</div>';

    try {
        const { data } = await api.get('/reservations', {
            params: { status: 'en_conflit', per_page: 50 },
        });
        const reservations = data.data ?? [];

        if (!reservations.length) {
            container.innerHTML = `
                <div class="panel" style="text-align:center;padding:32px 16px;color:var(--ok)">
                    <div style="font-size:32px;margin-bottom:8px">✓</div>
                    <strong>Aucune réservation en conflit.</strong>
                </div>`;
            return;
        }

        // Charger les conflits de toutes les réservations en parallèle
        const conflictsMap = {};
        await Promise.all(reservations.map(async r => {
            try {
                const res = await api.get(`/reservations/${r.id}/conflicts`);
                conflictsMap[r.id] = (res.data.data ?? []).filter(c => !c.is_resolved);
            } catch {
                conflictsMap[r.id] = [];
            }
        }));

        container.innerHTML = `
            <div class="notice" style="margin-bottom:16px">
                <strong>⚠ ${reservations.length} réservation(s) en conflit</strong>
            </div>
            <div class="stack" id="conflict-cards"></div>
        `;

        const cards = container.querySelector('#conflict-cards');
        for (const r of reservations) {
            const card = buildCard(r, conflictsMap[r.id] ?? [], container);
            cards.appendChild(card);
        }

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}

function buildCard(r, conflicts, container) {
    const card = document.createElement('div');
    card.className = 'panel';
    card.style.borderLeft = '4px solid var(--danger)';
    card.dataset.reservationId = r.id;

    const driverLabel = r.driver
        ? (r.driver.licence_number ?? `#${r.driver.id}`)
        : '<span class="muted">Non assigné</span>';
    const vehicleLabel = r.vehicle
        ? (r.vehicle.licence_plate ?? `#${r.vehicle.id}`)
        : '<span class="muted">Non assigné</span>';

    card.innerHTML = `
        <div class="split" style="margin-bottom:10px">
            <div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <code>#${r.id}</code>
                    ${badge(r.status)}
                    <strong>${esc(fmtDate(r.trip_date))}</strong>
                    <span class="muted">${fmtTime(r.departure_time)} – ${fmtTime(r.return_time)}</span>
                </div>
                <div style="font-size:12px;margin-top:4px;color:var(--muted)">
                    ${esc(r.organization?.name ?? '—')}
                    · 👤 ${driverLabel} · 🚌 ${vehicleLabel}
                </div>
                <div style="font-size:12px;margin-top:2px;color:var(--muted)">
                    ${esc(r.pickup_location?.city ?? r.pickup_location?.formatted_address ?? '—')}
                    → ${esc(r.destination_location?.city ?? r.destination_location?.formatted_address ?? '—')}
                </div>
            </div>
            <div class="actions" id="res-acts-${r.id}"></div>
        </div>

        <div id="conflict-list-${r.id}" style="margin-bottom:12px"></div>

        <div class="actions" id="conflict-acts-${r.id}"></div>
    `;

    renderConflictList(card.querySelector(`#conflict-list-${r.id}`), r, conflicts, container);
    buildResolutionActions(card.querySelector(`#res-acts-${r.id}`), r, conflicts, container);
    buildConflictActions(card.querySelector(`#conflict-acts-${r.id}`), r, container);

    return card;
}

function renderConflictList(el, r, conflicts, container) {
    if (!conflicts.length) {
        el.innerHTML = '<p class="muted" style="font-size:12px;margin:0">Aucun conflit non-résolu (en attente de transition automatique…)</p>';
        return;
    }

    el.innerHTML = `
        <div style="display:flex;flex-direction:column;gap:6px">
            ${conflicts.map(c => {
                const typeKey = c.type?.value ?? c.type ?? '';
                const meta = CONFLICT_META[typeKey] ?? { icon: '⚠', label: typeKey, derogation: false };
                const blockingId = c.related_reservation_id;
                return `
                <div style="display:flex;align-items:flex-start;gap:10px;background:#fef9f9;padding:8px 10px;border-radius:6px;border:1px solid var(--line)">
                    <span style="font-size:16px;flex-shrink:0">${meta.icon}</span>
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                            <span style="font-size:12px;font-weight:650;color:var(--danger)">${esc(meta.label)}</span>
                            ${meta.derogation ? '<span class="badge warning" style="font-size:10px">Dérogation collège requise</span>' : ''}
                        </div>
                        ${c.message ? `<div style="font-size:12px;margin-top:2px;color:var(--text)">${esc(c.message)}</div>` : ''}
                        ${blockingId ? `<button class="sm" data-goto-reservation="${blockingId}" style="margin-top:4px;font-size:11px">→ Voir réservation bloquante #${blockingId}</button>` : ''}
                    </div>
                    <button class="sm primary" data-resolve-conflict="${c.id}" style="flex-shrink:0">Résoudre</button>
                </div>`;
            }).join('')}
        </div>
    `;

    el.querySelectorAll('[data-resolve-conflict]').forEach(btn => {
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.textContent = '…';
            try {
                await api.patch(`/reservations/${r.id}/conflicts/${btn.dataset.resolveConflict}/resolve`);
                toast('Conflit résolu');
                await loadConflicts(container);
            } catch (err) {
                toast(apiError(err), 'error');
                btn.disabled = false;
                btn.textContent = 'Résoudre';
            }
        });
    });

    el.querySelectorAll('[data-goto-reservation]').forEach(btn => {
        btn.addEventListener('click', () => navigate(`/reservations?highlight=${btn.dataset.gotoReservation}`));
    });
}

function buildResolutionActions(el, r, conflicts, container) {
    const btn = (label, onClick, variant = '') => {
        const b = document.createElement('button');
        b.className = `sm ${variant}`;
        b.textContent = label;
        b.onclick = onClick;
        return b;
    };

    const hasAssignConflict = !r.driver_id || !r.vehicle_id || conflicts.some(c => {
        const t = c.type?.value ?? c.type ?? '';
        return t === 'no_driver_available' || t === 'no_vehicle_available';
    });

    if (hasAssignConflict) {
        const retryBtn = document.createElement('button');
        retryBtn.className = 'sm primary';
        retryBtn.textContent = '⚡ Réassigner auto';
        retryBtn.title = 'Tente de trouver un chauffeur et un véhicule disponibles et de les affecter automatiquement';
        retryBtn.addEventListener('click', async () => {
            retryBtn.disabled = true;
            retryBtn.textContent = '…';
            try {
                const { data } = await api.patch(`/reservations/${r.id}/retry-assign`);
                const newStatus = data.data?.status ?? data.status;
                toast(`Réassignation effectuée — statut : ${newStatus}`);
                await loadConflicts(container);
            } catch (err) {
                toast(apiError(err), 'error');
                retryBtn.disabled = false;
                retryBtn.textContent = '⚡ Réassigner auto';
            }
        });
        el.appendChild(retryBtn);
    }

    el.appendChild(btn('Décision collège', () => openCouncilModal(r, container)));
    el.appendChild(btn('Assigner', () => openAssignModal(r, container)));
    el.appendChild(btn('Approuver', () => openApproveModal(r, container), 'primary'));
    el.appendChild(btn('Refuser', () => openRejectModal(r, container), 'danger'));
    el.appendChild(btn('Annuler', () => openCancelModal(r, container), 'danger'));
}

function buildConflictActions(el, r, container) {
    const btn = document.createElement('button');
    btn.className = 'sm';
    btn.textContent = '✓ Résoudre tous les conflits résolus';
    btn.title = 'Marque comme résolus tous les conflits de cette réservation (à utiliser après avoir traité la cause racine)';
    btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            const { data } = await api.get(`/reservations/${r.id}/conflicts`);
            const unresolved = (data.data ?? []).filter(c => !c.is_resolved);
            await Promise.all(unresolved.map(c =>
                api.patch(`/reservations/${r.id}/conflicts/${c.id}/resolve`).catch(() => {})
            ));
            toast(`${unresolved.length} conflit(s) résolu(s)`);
            await loadConflicts(container);
        } catch (err) {
            toast(apiError(err), 'error');
            btn.disabled = false;
        }
    });
    el.appendChild(btn);
}

// ── Modals ────────────────────────────────────────────────────────────────────

async function openAssignModal(r, container) {
    let drivers = [], vehicles = [];
    try {
        const [d, v] = await Promise.all([
            api.get('/drivers', { params: { is_active: true } }),
            api.get('/vehicles', { params: { is_active: true } }),
        ]);
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
            close();
            toast('Assignation effectuée');
            await loadConflicts(container);
        } catch (err) {
            el.querySelector('#assign-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

function openApproveModal(r, container) {
    const { el, close } = showModal(`Approuver — #${r.id}`, `
        <p class="muted" style="margin-top:0;font-size:12px">Commentaire facultatif :</p>
        <textarea id="approve-reason" placeholder="Raison…" style="width:100%"></textarea>
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
            close();
            toast('Réservation approuvée');
            await loadConflicts(container);
        } catch (err) {
            el.querySelector('#approve-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

function openRejectModal(r, container) {
    const { el, close } = showModal(`Refuser — #${r.id}`, `
        <p class="muted" style="margin-top:0;font-size:12px">Motif <strong>(obligatoire)</strong> :</p>
        <textarea id="reject-reason" placeholder="Motif du refus…" style="width:100%" required></textarea>
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
            close();
            toast('Réservation refusée');
            await loadConflicts(container);
        } catch (err) {
            el.querySelector('#reject-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

function openCancelModal(r, container) {
    const { el, close } = showModal(`Annuler — #${r.id}`, `
        <p class="muted" style="margin-top:0;font-size:12px">Motif d'annulation <strong>(obligatoire)</strong> :</p>
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
            close();
            toast('Réservation annulée');
            await loadConflicts(container);
        } catch (err) {
            el.querySelector('#cancel-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

async function openCouncilModal(r, container) {
    let existing = null;
    try {
        const { data } = await api.get('/council-decisions', { params: { reservation_id: r.id } });
        existing = (data.data ?? []).find(d => d.reservation_id === r.id) ?? null;
    } catch {}

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
        const body = {
            decision_status: el.querySelector('#cd-status').value,
            decision_date: el.querySelector('#cd-date').value,
            notes: el.querySelector('#cd-notes').value,
        };
        try {
            if (existing) await api.patch(`/council-decisions/${existing.id}`, body);
            else await api.post('/council-decisions', { reservation_id: r.id, ...body });
            close();
            toast('Décision enregistrée');
            await loadConflicts(container);
        } catch (err) {
            el.querySelector('#cd-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

// ── Badge count (exporté pour app.js) ────────────────────────────────────────

export async function loadConflictsBadge() {
    try {
        const { data } = await api.get('/reservations', { params: { status: 'en_conflit', per_page: 1 } });
        const count = data.meta?.total ?? 0;
        const badge = document.getElementById('conflicts-badge');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'inline';
        } else {
            badge.style.display = 'none';
        }
    } catch { /* silencieux */ }
}