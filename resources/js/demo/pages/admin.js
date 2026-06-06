import { api, apiError } from '../api.js';
import { esc, badge, fmtDate, fmtDateTime, notice, toast, showModal } from '../ui.js';
import { hasRole } from '../auth.js';

const isAdmin = () => hasRole('admin');

export async function renderAdmin(el) {
    const adminTabs = isAdmin()
        ? `<div class="tab" data-tab="users">Utilisateurs</div>
           <div class="tab" data-tab="monitoring">Monitoring</div>`
        : '';

    const adminPanels = isAdmin()
        ? `<div id="tab-users" class="tab-panel"></div>
           <div id="tab-monitoring" class="tab-panel"></div>`
        : '';

    el.innerHTML = `
        <h1>Gestion</h1>
        <div class="tabs">
            <div class="tab active" data-tab="drivers">Chauffeurs</div>
            <div class="tab" data-tab="vehicles">Véhicules</div>
            <div class="tab" data-tab="unavailabilities">Indisponibilités</div>
            ${adminTabs}
        </div>
        <div id="tab-drivers" class="tab-panel active"></div>
        <div id="tab-vehicles" class="tab-panel"></div>
        <div id="tab-unavailabilities" class="tab-panel"></div>
        ${adminPanels}
    `;

    el.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            el.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            el.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            tab.classList.add('active');
            el.querySelector(`#tab-${tab.dataset.tab}`).classList.add('active');
        });
    });

    await loadDrivers(el.querySelector('#tab-drivers'));

    el.querySelector('[data-tab="vehicles"]').addEventListener('click', () =>
        loadVehicles(el.querySelector('#tab-vehicles'))
    );
    el.querySelector('[data-tab="unavailabilities"]').addEventListener('click', () =>
        loadUnavailabilities(el.querySelector('#tab-unavailabilities'))
    );

    if (isAdmin()) {
        el.querySelector('[data-tab="users"]').addEventListener('click', () =>
            loadUsers(el.querySelector('#tab-users'))
        );
        el.querySelector('[data-tab="monitoring"]').addEventListener('click', () =>
            loadMonitoring(el.querySelector('#tab-monitoring'))
        );
    }
}

// ── Drivers ───────────────────────────────────────────────────────────────────

async function loadDrivers(container) {
    container.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const { data } = await api.get('/drivers');
        const items = data.data ?? [];

        container.innerHTML = `
            <div class="split" style="margin-bottom:12px">
                <h2>Chauffeurs (${items.length})</h2>
                <button class="primary" id="btn-new-driver">+ Nouveau chauffeur</button>
            </div>
            <div class="panel">
                ${items.length === 0 ? '<p class="muted">Aucun chauffeur.</p>' : `
                    <table>
                        <thead><tr>
                            <th>#</th><th>Nom</th><th>Permis</th><th>Statut</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                            ${items.map(d => `
                                <tr>
                                    <td><code>${d.id}</code></td>
                                    <td>${esc(d.user?.first_name ?? '')} ${esc(d.user?.last_name ?? '')}</td>
                                    <td>${esc(d.licence_number ?? '—')}</td>
                                    <td>${d.is_active ? badge('actif', 'ok') : badge('inactif', 'muted')}</td>
                                    <td><div class="actions">
                                        <button class="sm" data-edit-driver="${d.id}" data-driver='${JSON.stringify({id:d.id,licence_number:d.licence_number,licence_expiry_date:d.licence_expiry_date})}'>Modifier</button>
                                        ${d.is_active
                                            ? `<button class="sm danger" data-deactivate="${d.id}">Désactiver</button>`
                                            : `<button class="sm ok" data-activate="${d.id}">Activer</button>`
                                        }
                                    </div></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `}
            </div>
        `;

        container.querySelector('#btn-new-driver')?.addEventListener('click', () => openDriverModal(container));
        container.querySelectorAll('[data-edit-driver]').forEach(btn => {
            btn.addEventListener('click', () => {
                const driver = JSON.parse(btn.dataset.driver);
                openEditDriverModal(driver, container);
            });
        });
        container.querySelectorAll('[data-activate]').forEach(btn => {
            btn.addEventListener('click', async () => {
                try {
                    await api.patch(`/drivers/${btn.dataset.activate}/activate`);
                    toast('Chauffeur activé');
                    loadDrivers(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });
        container.querySelectorAll('[data-deactivate]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Désactiver ce chauffeur ?')) return;
                try {
                    await api.patch(`/drivers/${btn.dataset.deactivate}/deactivate`);
                    toast('Chauffeur désactivé');
                    loadDrivers(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}

function openDriverModal(container) {
    const { el, close } = showModal('Nouveau chauffeur', `
        <p class="muted" style="font-size:12px;margin-top:0">
            Le chauffeur doit être un utilisateur existant avec le rôle "chauffeur".
        </p>
        <div class="form-grid">
            <label class="full">ID Utilisateur
                <input type="number" id="d-user-id" placeholder="ID de l'utilisateur chauffeur" min="1">
            </label>
            <label>Numéro de permis
                <input type="text" id="d-license" placeholder="B-12345">
            </label>
            <label>Expiration permis
                <input type="date" id="d-license-exp">
            </label>
        </div>
        <div id="d-notice" style="margin-top:10px"></div>
    `);

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="d-cancel">Annuler</button><button type="button" id="d-save" class="primary">Créer</button>`;
    el.querySelector('.modal').appendChild(footer);

    el.querySelector('#d-cancel').addEventListener('click', close);
    el.querySelector('#d-save').addEventListener('click', async () => {
        try {
            await api.post('/drivers', {
                user_id: parseInt(el.querySelector('#d-user-id').value),
                licence_number: el.querySelector('#d-license').value || undefined,
                licence_expiry_date: el.querySelector('#d-license-exp').value || undefined,
            });
            close();
            toast('Chauffeur créé');
            loadDrivers(container);
        } catch (err) {
            el.querySelector('#d-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

// ── Vehicles ──────────────────────────────────────────────────────────────────

async function loadVehicles(container) {
    if (container.dataset.loaded) return;
    container.dataset.loaded = '1';
    container.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const { data } = await api.get('/vehicles');
        const items = data.data ?? [];

        container.innerHTML = `
            <div class="split" style="margin-bottom:12px">
                <h2>Véhicules (${items.length})</h2>
                <button class="primary" id="btn-new-vehicle">+ Nouveau véhicule</button>
            </div>
            <div class="panel">
                ${items.length === 0 ? '<p class="muted">Aucun véhicule.</p>' : `
                    <table>
                        <thead><tr>
                            <th>#</th><th>Immatriculation</th><th>Capacité</th><th>Statut</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                            ${items.map(v => `
                                <tr>
                                    <td><code>${v.id}</code></td>
                                    <td>${esc(v.licence_plate ?? '—')}</td>
                                    <td>${v.capacity} pl.</td>
                                    <td>${v.is_active ? badge('actif', 'ok') : badge('inactif', 'muted')}</td>
                                    <td><div class="actions">
                                        <button class="sm" data-edit-vehicle="${v.id}" data-vehicle='${JSON.stringify({id:v.id,licence_plate:v.licence_plate,capacity:v.capacity})}'>Modifier</button>
                                        ${v.is_active
                                            ? `<button class="sm danger" data-deact-v="${v.id}">Désactiver</button>`
                                            : `<button class="sm ok" data-act-v="${v.id}">Activer</button>`
                                        }
                                    </div></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `}
            </div>
        `;

        container.querySelector('#btn-new-vehicle')?.addEventListener('click', () => openVehicleModal(container));
        container.querySelectorAll('[data-edit-vehicle]').forEach(btn => {
            btn.addEventListener('click', () => {
                const vehicle = JSON.parse(btn.dataset.vehicle);
                openEditVehicleModal(vehicle, container);
            });
        });
        container.querySelectorAll('[data-act-v]').forEach(btn => {
            btn.addEventListener('click', async () => {
                try {
                    await api.patch(`/vehicles/${btn.dataset.actV}/activate`);
                    toast('Véhicule activé');
                    delete container.dataset.loaded;
                    loadVehicles(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });
        container.querySelectorAll('[data-deact-v]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Désactiver ce véhicule ?')) return;
                try {
                    await api.patch(`/vehicles/${btn.dataset.deactV}/deactivate`);
                    toast('Véhicule désactivé');
                    delete container.dataset.loaded;
                    loadVehicles(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}

function openVehicleModal(container) {
    const { el, close } = showModal('Nouveau véhicule', `
        <div class="form-grid">
            <label class="full">Nom
                <input type="text" id="v-name" placeholder="Bus n°1" required>
            </label>
            <label>Immatriculation
                <input type="text" id="v-plate" placeholder="1-ABC-123">
            </label>
            <label>Capacité (places)
                <input type="number" id="v-capacity" value="30" min="1" required>
            </label>
        </div>
        <div id="v-notice" style="margin-top:10px"></div>
    `);

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="v-cancel">Annuler</button><button type="button" id="v-save" class="primary">Créer</button>`;
    el.querySelector('.modal').appendChild(footer);

    el.querySelector('#v-cancel').addEventListener('click', close);
    el.querySelector('#v-save').addEventListener('click', async () => {
        try {
            await api.post('/vehicles', {
                licence_plate: el.querySelector('#v-plate').value,
                capacity: el.querySelector('#v-capacity').value,
            });
            close();
            toast('Véhicule créé');
            delete container.dataset.loaded;
            loadVehicles(container);
        } catch (err) {
            el.querySelector('#v-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

// ── Unavailabilities ──────────────────────────────────────────────────────────

async function loadUnavailabilities(container) {
    if (container.dataset.loaded) return;
    container.dataset.loaded = '1';
    container.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const [unavRes, driversRes, vehiclesRes] = await Promise.all([
            api.get('/unavailabilities'),
            api.get('/drivers'),
            api.get('/vehicles'),
        ]);
        const items = unavRes.data.data ?? [];
        const drivers = driversRes.data.data ?? [];
        const vehicles = vehiclesRes.data.data ?? [];

        container.innerHTML = `
            <div class="split" style="margin-bottom:12px">
                <h2>Indisponibilités (${items.length})</h2>
                <button class="primary" id="btn-new-unav">+ Nouvelle indisponibilité</button>
            </div>
            <div class="panel">
                ${items.length === 0 ? '<p class="muted">Aucune indisponibilité.</p>' : `
                    <table>
                        <thead><tr>
                            <th>#</th><th>Ressource</th><th>Du</th><th>Au</th><th>Motif</th><th>Statut</th><th>Actions</th>
                        </tr></thead>
                        <tbody>
                            ${items.map(u => {
                                const driver  = u.driver_id  ? drivers.find(d => d.id === u.driver_id)   : null;
                                const vehicle = u.vehicle_id ? vehicles.find(v => v.id === u.vehicle_id) : null;
                                const resource = driver
                                    ? esc((driver.user?.first_name ?? '') + ' ' + (driver.user?.last_name ?? ''))
                                    : vehicle ? esc(vehicle.licence_plate ?? '—') : '—';
                                return `
                                <tr>
                                    <td><code>${u.id}</code></td>
                                    <td>${resource}</td>
                                    <td>${fmtDate(u.start_datetime)}</td>
                                    <td>${fmtDate(u.end_datetime)}</td>
                                    <td>${esc(u.reason ?? u.reason_type ?? '—')}</td>
                                    <td>${u.is_active ? badge('actif', 'ok') : badge('annulé', 'muted')}</td>
                                    <td><div class="actions">
                                        ${u.is_active ? `
                                            <button class="sm" data-edit-unav="${u.id}" data-unav='${JSON.stringify({id:u.id,start_datetime:u.start_datetime,end_datetime:u.end_datetime,reason:u.reason})}'>Modifier</button>
                                            <button class="sm danger" data-cancel-unav="${u.id}">Annuler</button>
                                        ` : ''}
                                    </div></td>
                                </tr>
                            `}).join('')}
                        </tbody>
                    </table>
                `}
            </div>
        `;

        container.querySelector('#btn-new-unav')?.addEventListener('click', () =>
            openUnavailabilityModal(container, drivers, vehicles)
        );
        container.querySelectorAll('[data-edit-unav]').forEach(btn => {
            btn.addEventListener('click', () => {
                const unav = JSON.parse(btn.dataset.unav);
                openEditUnavailabilityModal(unav, container);
            });
        });
        container.querySelectorAll('[data-cancel-unav]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Annuler cette indisponibilité ?')) return;
                try {
                    await api.patch(`/unavailabilities/${btn.dataset.cancelUnav}/cancel`);
                    toast('Indisponibilité annulée');
                    delete container.dataset.loaded;
                    loadUnavailabilities(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}

function openUnavailabilityModal(container, drivers, vehicles) {
    const { el, close } = showModal('Nouvelle indisponibilité', `
        <div class="form-grid">
            <label>Type de ressource
                <select id="unav-type">
                    <option value="driver">Chauffeur</option>
                    <option value="vehicle">Véhicule</option>
                </select>
            </label>
            <label id="unav-resource-label">Chauffeur
                <select id="unav-resource">
                    <optgroup label="Chauffeurs" id="og-drivers">
                        ${drivers.map(d => `<option value="driver:${d.id}">${esc((d.user?.first_name ?? '') + ' ' + (d.user?.last_name ?? ''))}</option>`).join('')}
                    </optgroup>
                </select>
            </label>
            <label>Date de début
                <input type="date" id="unav-start" required>
            </label>
            <label>Date de fin
                <input type="date" id="unav-end" required>
            </label>
            <label class="full">Motif
                <textarea id="unav-reason" placeholder="Maladie, congé…"></textarea>
            </label>
        </div>
        <div id="unav-notice" style="margin-top:10px"></div>
    `);

    // Toggle driver/vehicle selector
    el.querySelector('#unav-type').addEventListener('change', e => {
        const sel = el.querySelector('#unav-resource');
        sel.innerHTML = e.target.value === 'driver'
            ? drivers.map(d => `<option value="driver:${d.id}">${esc((d.user?.first_name ?? '') + ' ' + (d.user?.last_name ?? ''))}</option>`).join('')
            : vehicles.map(v => `<option value="vehicle:${v.id}">${esc(v.licence_plate ?? v.id)}</option>`).join('');
    });

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="unav-cancel">Annuler</button><button type="button" id="unav-save" class="primary">Créer</button>`;
    el.querySelector('.modal').appendChild(footer);

    el.querySelector('#unav-cancel').addEventListener('click', close);
    el.querySelector('#unav-save').addEventListener('click', async () => {
        const [type, id] = el.querySelector('#unav-resource').value.split(':');
        const body = {
            reason_type: type,
            start_datetime: el.querySelector('#unav-start').value,
            end_datetime: el.querySelector('#unav-end').value,
            reason: el.querySelector('#unav-reason').value || null,
        };
        if (type === 'driver') body.driver_id = parseInt(id);
        else body.vehicle_id = parseInt(id);

        try {
            await api.post('/unavailabilities', body);
            close();
            toast('Indisponibilité créée');
            delete container.dataset.loaded;
            loadUnavailabilities(container);
        } catch (err) {
            el.querySelector('#unav-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

// ── Modals d'édition ──────────────────────────────────────────────────────────

function openEditDriverModal(driver, container) {
    const { el, close } = showModal(`Modifier chauffeur #${driver.id}`, `
        <div class="form-grid">
            <label>Numéro de permis
                <input type="text" id="ed-license" value="${esc(driver.licence_number ?? '')}" placeholder="B-12345">
            </label>
            <label>Expiration permis
                <input type="date" id="ed-license-exp" value="${driver.licence_expiry_date ?? ''}">
            </label>
        </div>
        <div id="ed-notice" style="margin-top:10px"></div>
    `);

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="ed-cancel">Annuler</button><button type="button" id="ed-save" class="primary">Enregistrer</button>`;
    el.querySelector('.modal').appendChild(footer);

    el.querySelector('#ed-cancel').addEventListener('click', close);
    el.querySelector('#ed-save').addEventListener('click', async () => {
        const body = {};
        const ln = el.querySelector('#ed-license').value.trim();
        const exp = el.querySelector('#ed-license-exp').value;
        if (ln)  body.licence_number      = ln;
        if (exp) body.licence_expiry_date = exp;

        try {
            await api.patch(`/drivers/${driver.id}`, body);
            close();
            toast('Chauffeur mis à jour');
            loadDrivers(container);
        } catch (err) {
            el.querySelector('#ed-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

function openEditVehicleModal(vehicle, container) {
    const { el, close } = showModal(`Modifier véhicule #${vehicle.id}`, `
        <div class="form-grid">
            <label>Immatriculation
                <input type="text" id="ev-plate" value="${esc(vehicle.licence_plate ?? '')}" placeholder="1-ABC-123">
            </label>
            <label>Capacité (places)
                <input type="number" id="ev-capacity" value="${vehicle.capacity ?? ''}" min="1">
            </label>
        </div>
        <div id="ev-notice" style="margin-top:10px"></div>
    `);

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="ev-cancel">Annuler</button><button type="button" id="ev-save" class="primary">Enregistrer</button>`;
    el.querySelector('.modal').appendChild(footer);

    el.querySelector('#ev-cancel').addEventListener('click', close);
    el.querySelector('#ev-save').addEventListener('click', async () => {
        const body = {};
        const plate = el.querySelector('#ev-plate').value.trim();
        const cap   = el.querySelector('#ev-capacity').value;
        if (plate) body.licence_plate = plate;
        if (cap)   body.capacity      = parseInt(cap);

        try {
            await api.patch(`/vehicles/${vehicle.id}`, body);
            close();
            toast('Véhicule mis à jour');
            delete container.dataset.loaded;
            loadVehicles(container);
        } catch (err) {
            el.querySelector('#ev-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

function openEditUnavailabilityModal(unav, container) {
    const startDate = unav.start_datetime ? unav.start_datetime.slice(0, 10) : '';
    const endDate   = unav.end_datetime   ? unav.end_datetime.slice(0, 10)   : '';

    const { el, close } = showModal(`Modifier indisponibilité #${unav.id}`, `
        <div class="form-grid">
            <label>Date de début
                <input type="date" id="eu-start" value="${startDate}" required>
            </label>
            <label>Date de fin
                <input type="date" id="eu-end" value="${endDate}" required>
            </label>
            <label class="full">Motif
                <textarea id="eu-reason" placeholder="Maladie, congé…">${esc(unav.reason ?? '')}</textarea>
            </label>
            <label class="full">Raison de la modification
                <input type="text" id="eu-change-reason" placeholder="Optionnel">
            </label>
        </div>
        <div id="eu-notice" style="margin-top:10px"></div>
    `);

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="eu-cancel">Annuler</button><button type="button" id="eu-save" class="primary">Enregistrer</button>`;
    el.querySelector('.modal').appendChild(footer);

    el.querySelector('#eu-cancel').addEventListener('click', close);
    el.querySelector('#eu-save').addEventListener('click', async () => {
        try {
            await api.patch(`/unavailabilities/${unav.id}`, {
                start_datetime: el.querySelector('#eu-start').value,
                end_datetime:   el.querySelector('#eu-end').value,
                reason:         el.querySelector('#eu-reason').value || null,
                change_reason:  el.querySelector('#eu-change-reason').value || null,
            });
            close();
            toast('Indisponibilité mise à jour');
            delete container.dataset.loaded;
            loadUnavailabilities(container);
        } catch (err) {
            el.querySelector('#eu-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}

// ── Utilisateurs (admin + agent_communal) ─────────────────────────────────────

async function loadUsers(container) {
    if (container.dataset.loaded) return;
    container.dataset.loaded = '1';
    container.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const { data } = await api.get('/users');
        const items = data.data ?? [];

        container.innerHTML = `
            <div class="split" style="margin-bottom:12px">
                <h2>Utilisateurs (${items.length})</h2>
            </div>
            <div class="panel">
                ${items.length === 0 ? '<p class="muted">Aucun utilisateur.</p>' : `
                    <table>
                        <thead><tr>
                            <th>#</th><th>Nom</th><th>Email</th><th>Rôles</th><th>Statut</th>
                            ${isAdmin() ? '<th>Actions</th>' : ''}
                        </tr></thead>
                        <tbody>
                            ${items.map(u => `
                                <tr style="${!u.active ? 'opacity:0.5' : ''}">
                                    <td><code>${u.id}</code></td>
                                    <td>${esc(u.full_name ?? `${u.first_name} ${u.last_name}`)}</td>
                                    <td style="font-size:12px">${esc(u.email)}</td>
                                    <td>${(u.roles ?? []).map(r => badge(r, r === 'admin' ? 'brand' : '')).join(' ')}</td>
                                    <td>${u.active ? badge('actif', 'ok') : badge('bloqué', 'danger')}</td>
                                    ${isAdmin() ? `
                                        <td><div class="actions">
                                            ${u.active
                                                ? `<button class="sm danger" data-block="${u.id}">Bloquer</button>`
                                                : `<button class="sm" data-unblock="${u.id}">Débloquer</button>`
                                            }
                                            <button class="sm danger" data-deactivate-user="${u.id}" title="Désactivation définitive irréversible">Désactiver</button>
                                        </div></td>
                                    ` : ''}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `}
            </div>
        `;

        container.querySelectorAll('[data-block]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Bloquer cet utilisateur ?')) return;
                try {
                    await api.patch(`/users/${btn.dataset.block}/status`, { active: false });
                    toast('Utilisateur bloqué');
                    delete container.dataset.loaded;
                    loadUsers(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });

        container.querySelectorAll('[data-unblock]').forEach(btn => {
            btn.addEventListener('click', async () => {
                try {
                    await api.patch(`/users/${btn.dataset.unblock}/status`, { active: true });
                    toast('Utilisateur débloqué');
                    delete container.dataset.loaded;
                    loadUsers(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });

        container.querySelectorAll('[data-deactivate-user]').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Désactiver définitivement cet utilisateur ? Cette action est irréversible.')) return;
                try {
                    await api.patch(`/users/${btn.dataset.deactivateUser}/deactivate`);
                    toast('Utilisateur désactivé définitivement');
                    delete container.dataset.loaded;
                    loadUsers(container);
                } catch (err) { toast(apiError(err), 'error'); }
            });
        });

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}

// ── Monitoring notifications (admin uniquement) ───────────────────────────────

async function loadMonitoring(container) {
    container.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const { data } = await api.get('/notifications/health');
        const isOk    = data.status === 'ok';
        const metrics = data.data ?? {};

        const metricRow = (label, value, alertFn) => {
            const isAlert = alertFn(value);
            return `
                <div class="panel" style="padding:14px 16px;display:flex;justify-content:space-between;align-items:center">
                    <span style="color:var(--muted);font-size:13px">${esc(label)}</span>
                    <strong style="font-size:20px;color:${isAlert ? 'var(--danger)' : 'var(--text)'}">${value ?? '—'}</strong>
                </div>
            `;
        };

        container.innerHTML = `
            <div class="split" style="margin-bottom:12px">
                <h2>Monitoring notifications</h2>
                <button id="btn-refresh-monitoring" class="sm">↻ Rafraîchir</button>
            </div>

            <div style="margin-bottom:16px">
                <div class="notice ${isOk ? '' : 'error'}" style="display:inline-flex;align-items:center;gap:8px;margin:0">
                    <strong>${isOk ? '✓ Système opérationnel' : '⚠ Alerte détectée'}</strong>
                </div>
            </div>

            <div class="grid" style="grid-template-columns:repeat(3,1fr);gap:12px">
                ${metricRow('Livraisons en attente', metrics.deliveries_pending_total, v => v > 50)}
                ${metricRow('Échecs dernières 24h', metrics.deliveries_failed_last_24h, v => v > 20)}
                ${metricRow('Haute priorité bloquée >5min', metrics.deliveries_high_pending_over_5m, v => v > 0)}
                ${metricRow('Digests prêts à envoyer', metrics.digests_pending_ready, v => v > 100)}
                ${metricRow('Âge livraison la plus ancienne (s)', metrics.oldest_pending_delivery_age_seconds, v => v > 300)}
            </div>
        `;

        container.querySelector('#btn-refresh-monitoring')?.addEventListener('click', () => {
            delete container.dataset.loaded;
            loadMonitoring(container);
        });

    } catch (err) {
        container.innerHTML = notice(apiError(err), 'error');
    }
}