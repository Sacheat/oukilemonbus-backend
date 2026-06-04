import { api, apiError } from '../api.js';
import { esc, fmtDateTime, notice, toast, showModal } from '../ui.js';
import { isInternal } from '../auth.js';

const TYPE_COLORS = {
    feuille_route:  '',
    deliberation:   'brand',
    attestation:    'brand',
    planning:       'warning',
    justificatif:   '',
};

function fmtSize(bytes) {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} o`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} Ko`;
    return `${(bytes / 1024 / 1024).toFixed(1)} Mo`;
}

export async function renderDocuments(el) {
    el.innerHTML = `
        <div class="split" style="margin-bottom:16px">
            <h1>Documents</h1>
            ${isInternal() ? `<button class="primary" id="btn-gen-rs">Générer feuille de route</button>` : ''}
        </div>
        <div class="panel" id="doc-panel"><div class="loading">Chargement…</div></div>
    `;

    if (isInternal()) {
        el.querySelector('#btn-gen-rs')?.addEventListener('click', () =>
            openRouteSheetModal(el.querySelector('#doc-panel'))
        );
    }

    await loadDocuments(el.querySelector('#doc-panel'));
}

async function loadDocuments(panel) {
    panel.innerHTML = '<div class="loading">Chargement…</div>';
    try {
        const { data } = await api.get('/documents');
        const items = data.data ?? [];

        if (!items.length) {
            panel.innerHTML = '<p class="muted">Aucun document.</p>';
            return;
        }

        panel.innerHTML = `
            <table>
                <thead><tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Nom</th>
                    <th>Taille</th>
                    <th>Réservation</th>
                    <th>Généré le</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                    ${items.map(d => {
                        const unavailable = d.is_available === false;
                        const rowStyle = unavailable ? 'opacity:0.45' : '';

                        const typeBadge = d.document_type_label
                            ? `<span class="badge ${TYPE_COLORS[d.document_type] ?? ''}">${esc(d.document_type_label)}</span>`
                            : '—';

                        const officialBadge = d.is_official
                            ? `<span class="badge ok" style="margin-left:4px" title="Document officiel">✓</span>`
                            : '';

                        const versionBadge = d.version > 1
                            ? `<span class="badge" style="margin-left:4px">v${d.version}</span>`
                            : '';

                        const modeBadge = d.generation_mode === 'manuel'
                            ? `<span class="badge warning" style="margin-left:4px" title="Généré manuellement">M</span>`
                            : '';

                        const resaLink = d.reservation_id
                            ? `<a href="#/reservations" style="font-size:12px">#${d.reservation_id}</a>`
                            : '<span class="muted">—</span>';

                        return `
                            <tr style="${rowStyle}">
                                <td><code>${d.id}</code></td>
                                <td>${typeBadge}${officialBadge}${versionBadge}${modeBadge}</td>
                                <td style="font-size:12px">${esc(d.file_name ?? '—')}</td>
                                <td style="font-size:12px;color:var(--muted)">${fmtSize(d.file_size)}</td>
                                <td>${resaLink}</td>
                                <td style="font-size:12px">${fmtDateTime(d.generated_at ?? d.created_at)}</td>
                                <td>
                                    ${unavailable
                                        ? `<span class="muted" style="font-size:12px">Indisponible</span>`
                                        : `<button class="sm" data-doc-id="${d.id}" data-doc-name="${esc(d.file_name ?? 'document')}">Télécharger</button>`
                                    }
                                </td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        `;

        panel.querySelectorAll('[data-doc-id]').forEach(btn => {
            btn.addEventListener('click', () => downloadDoc(btn.dataset.docId, btn.dataset.docName));
        });

    } catch (err) {
        panel.innerHTML = notice(apiError(err), 'error');
    }
}

async function downloadDoc(id, filename) {
    try {
        const response = await api.get(`/documents/${id}/download`, { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename || `document-${id}`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } catch (err) {
        toast(apiError(err), 'error');
    }
}

// ── Génération feuille de route ────────────────────────────────────────────────

function openRouteSheetModal(panel) {
    const today = new Date().toISOString().slice(0, 10);

    const body = `
        <div class="form-grid">
            <label class="full">Mode
                <select id="rs-mode">
                    <option value="period">Période (aller-retour)</option>
                    <option value="daily">Journalier (par véhicule)</option>
                </select>
            </label>

            <div id="rs-period-fields" style="display:contents">
                <label>Date de début
                    <input type="date" id="rs-start" value="${today}" required>
                </label>
                <label>Date de fin
                    <input type="date" id="rs-end" value="${today}" required>
                </label>
            </div>

            <div id="rs-daily-fields" style="display:none;grid-column:1/-1">
                <div class="form-grid">
                    <label>Date de service
                        <input type="date" id="rs-service-date" value="${today}">
                    </label>
                    <label>Véhicule
                        <select id="rs-vehicle">
                            <option value="">Chargement…</option>
                        </select>
                    </label>
                </div>
            </div>
        </div>
        <div id="rs-notice" style="margin-top:10px"></div>
    `;

    const { el: modal, close } = showModal('Générer feuille de route', body);

    // Charger les véhicules pour le mode journalier
    api.get('/vehicles').then(({ data }) => {
        const vehicles = data.data ?? [];
        const sel = modal.querySelector('#rs-vehicle');
        sel.innerHTML = vehicles.length
            ? vehicles.map(v => `<option value="${v.id}">${esc(v.licence_plate)} (${v.capacity} pl.)</option>`).join('')
            : '<option value="" disabled>Aucun véhicule</option>';
    }).catch(() => {});

    // Toggle mode
    modal.querySelector('#rs-mode').addEventListener('change', e => {
        const isPeriod = e.target.value === 'period';
        modal.querySelector('#rs-period-fields').style.display = isPeriod ? 'contents' : 'none';
        modal.querySelector('#rs-daily-fields').style.display  = isPeriod ? 'none' : 'block';
    });

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="rs-cancel">Annuler</button><button type="button" id="rs-gen" class="primary">Générer</button>`;
    modal.querySelector('.modal').appendChild(footer);

    modal.querySelector('#rs-cancel').addEventListener('click', close);
    modal.querySelector('#rs-gen').addEventListener('click', async () => {
        const mode = modal.querySelector('#rs-mode').value;
        const noticeEl = modal.querySelector('#rs-notice');
        noticeEl.innerHTML = '';

        const payload = mode === 'period'
            ? {
                start_date: modal.querySelector('#rs-start').value,
                end_date:   modal.querySelector('#rs-end').value,
              }
            : {
                service_date: modal.querySelector('#rs-service-date').value,
                vehicle_id:   parseInt(modal.querySelector('#rs-vehicle').value),
              };

        try {
            await api.post('/documents/route-sheets/generate', payload);
            close();
            toast('Feuille de route générée');
            await loadDocuments(panel);
        } catch (err) {
            noticeEl.innerHTML = notice(apiError(err), 'error');
        }
    });
}