import { api, apiError } from '../api.js';
import { esc, badge, fmtDate, notice } from '../ui.js';

export async function renderOrganizations(el) {
    el.innerHTML = '<h1>Organisations</h1><div class="panel"><div class="loading">Chargement…</div></div>';

    try {
        const { data } = await api.get('/organizations');
        const items = data.data ?? [];

        const panel = el.querySelector('.panel');

        if (!items.length) {
            panel.innerHTML = '<p class="muted">Aucune organisation.</p>';
            return;
        }

        panel.innerHTML = `
            <table>
                <thead><tr>
                    <th>#</th><th>Nom</th><th>Type</th><th>École communale</th><th>Membres</th><th>Réservations</th>
                </tr></thead>
                <tbody>
                    ${items.map(o => `
                        <tr>
                            <td><code>${o.id}</code></td>
                            <td><strong>${esc(o.name)}</strong></td>
                            <td>${esc(o.type ?? '—')}</td>
                            <td>${o.is_communal_school ? badge('oui', 'ok') : badge('non', '')}</td>
                            <td>${o.users_count ?? o.members_count ?? '—'}</td>
                            <td>${o.reservations_count ?? '—'}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        `;

    } catch (err) {
        el.querySelector('.panel').innerHTML = notice(apiError(err), 'error');
    }
}