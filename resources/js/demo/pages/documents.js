import { api, apiError } from '../api.js';
import { esc, fmtDateTime, notice, toast, showModal } from '../ui.js';
import { isInternal } from '../auth.js';

export async function renderDocuments(el) {
    el.innerHTML = `
        <div class="split">
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
                    <th>#</th><th>Type</th><th>Nom</th><th>Généré le</th><th>Actions</th>
                </tr></thead>
                <tbody>
                    ${items.map(d => `
                        <tr>
                            <td><code>${d.id}</code></td>
                            <td><span class="badge">${esc(d.type ?? '—')}</span></td>
                            <td>${esc(d.name ?? d.filename ?? '—')}</td>
                            <td style="font-size:12px">${fmtDateTime(d.created_at)}</td>
                            <td>
                                <button class="sm" data-doc-id="${d.id}">Télécharger</button>
                            </td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;

        panel.querySelectorAll('[data-doc-id]').forEach(btn => {
            btn.addEventListener('click', () => downloadDoc(btn.dataset.docId));
        });

    } catch (err) {
        panel.innerHTML = notice(apiError(err), 'error');
    }
}

async function downloadDoc(id) {
    try {
        const response = await api.get(`/documents/${id}/download`, { responseType: 'blob' });
        const url = URL.createObjectURL(response.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = `document-${id}.pdf`;
        a.click();
        URL.revokeObjectURL(url);
    } catch (err) {
        toast(apiError(err), 'error');
    }
}

function openRouteSheetModal(panel) {
    const { el: modal, close } = showModal('Générer feuille de route', `
        <div class="form-grid">
            <label class="full">Date
                <input type="date" id="rs-date" value="${new Date().toISOString().slice(0,10)}" required>
            </label>
        </div>
        <div id="rs-notice" style="margin-top:10px"></div>
    `);

    const footer = document.createElement('div');
    footer.className = 'modal-footer';
    footer.innerHTML = `<button type="button" id="rs-cancel">Annuler</button><button type="button" id="rs-gen" class="primary">Générer</button>`;
    modal.querySelector('.modal').appendChild(footer);

    modal.querySelector('#rs-cancel').addEventListener('click', close);
    modal.querySelector('#rs-gen').addEventListener('click', async () => {
        try {
            await api.post('/documents/route-sheets/generate', {
                date: modal.querySelector('#rs-date').value,
            });
            close();
            toast('Feuille de route générée');
            await loadDocuments(panel);
        } catch (err) {
            modal.querySelector('#rs-notice').innerHTML = notice(apiError(err), 'error');
        }
    });
}