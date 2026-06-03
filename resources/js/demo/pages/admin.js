import { api, apiError } from '../api.js';
import { esc, badge, fmtDate, fmtDateTime, notice, toast, showModal } from '../ui.js';

export async function renderAdmin(el) {
    el.innerHTML = `
        <h1>Gestion</h1>
        <div class="tabs">
            <div class="tab active" data-tab="drivers">Chauffeurs</div>
            <div class="tab" data-tab="vehicles">Véhicules</div>
            <div class="tab" data-tab="unavailabilities">Indisponibilités</div>
        </div>
        <div id="tab-drivers" class="tab-panel active"></div>
        <div id="tab-vehicles" class="tab-panel"></div>
        <div id="tab-unavailabilities" class="tab-panel"></div>
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
    // Others load on tab click to avoid unnecessary requests
    el.querySelector('[data-tab="vehicles"]').addEventListener('click', () =>
        loadVehicles(el.querySelector('#tab-vehicles'))
    );
    el.querySelector('[data-tab="unavailabilities"]').addEventListener('click', () =>
        loadUnavailabilities(el.querySelector('#tab-unavailabilities'))
    );
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
                                    <td>${esc(d.user?.first_name)} ${esc(d.user?.last_name)}</td>
                                    <td>${esc(d.license_number ?? '—')}</td>
                                    <td>${d.is_active ? badge('actif', 'ok') : badge('inactif', 'muted')}</td>
                                    <td>
                                        ${d.is_active
                                            ? `<button class="sm danger" data-deactivate="${d.id}">Désactiver</button>`
                                            : `<button class="sm" data-activate="${d.id}">Activer</button>`
                                        }
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `}
            </div>
        `;

        container.querySelector('#btn-new-driver')?.addEventListener('click', () => openDriverModal(container));
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
                user_id: el.querySelector('#d-user-id').value,
                license_number: el.querySelector('#d-license').value,
                license_expiry_date: el.querySelector('#d-license-exp').value,
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
                                    <td>${v.capacity} places</td>
                                    <td>${v.is_active ? badge('actif', 'ok') : badge('inactif', 'muted')}</td>
                                    <td>
                                        ${v.is_active
                                            ? `<button class="sm danger" data-deact-v="${v.id}">Désactiver</button>`
                                            : `<button class="sm" data-act-v="${v.id}">Activer</button>`
                                        }
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `}
            </div>
        `;

        container.querySelector('#btn-new-vehicle')?.addEventListener('click', () => openVehicleModal(container));
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
                            ${items.map(u => `
                                <tr>
                                    <td><code>${u.id}</code></td>
                                    <td>${u.driver
                                        ? esc(u.driver.user?.first_name + ' ' + u.driver.user?.last_name)
                                        : esc(u.vehicle?.name ?? '—')
                                    }</td>
                                    <td>${fmtDate(u.start_date)}</td>
                                    <td>${fmtDate(u.end_date)}</td>
                                    <td>${esc(u.reason ?? '—')}</td>
                                    <td>${u.is_active ? badge('actif', 'ok') : badge('annulé', 'muted')}</td>
                                    <td>
                                        ${u.is_active
                                            ? `<button class="sm danger" data-cancel-unav="${u.id}">Annuler</button>`
                                            : ''
                                        }
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `}
            </div>
        `;

        container.querySelector('#btn-new-unav')?.addEventListener('click', () =>
            openUnavailabilityModal(container, drivers, vehicles)
        );
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
                        ${drivers.map(d => `<option value="driver:${d.id}">${esc(d.user?.first_name)} ${esc(d.user?.last_name)}</option>`).join('')}
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
            ? drivers.map(d => `<option value="driver:${d.id}">${esc(d.user?.first_name)} ${esc(d.user?.last_name)}</option>`).join('')
            : vehicles.map(v => `<option value="vehicle:${v.id}">${esc(v.name)}</option>`).join('');
    });

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="unav-cancel">Annuler</button><button type="button" id="unav-save" class="primary">Créer</button>`;
    el.querySelector('.modal').appendChild(footer);

    el.querySelector('#unav-cancel').addEventListener('click', close);
    el.querySelector('#unav-save').addEventListener('click', async () => {
        const [type, id] = el.querySelector('#unav-resource').value.split(':');
        const body = {
            start_date: el.querySelector('#unav-start').value,
            end_date: el.querySelector('#unav-end').value,
            reason: el.querySelector('#unav-reason').value,
        };
        if (type === 'driver') body.driver_id = id;
        else body.vehicle_id = id;

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