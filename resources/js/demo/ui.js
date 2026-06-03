// ── Badge ────────────────────────────────────────────────────────────────────

const STATUS_CLASS = {
    en_attente: 'warning',
    approuve: 'ok',
    confirme: 'ok',
    refuse: 'danger',
    annule: 'muted',
    en_conflit: 'danger',
    en_attente_approbation: 'warning',
    approuvee: 'ok',
    refusee: 'danger',
};

const STATUS_LABEL = {
    en_attente: 'En attente',
    approuve: 'Approuvé',
    confirme: 'Confirmé',
    refuse: 'Refusé',
    annule: 'Annulé',
    en_conflit: 'En conflit',
    en_attente_approbation: 'En attente',
    approuvee: 'Approuvée',
    refusee: 'Refusée',
};

export function badge(value, overrideClass) {
    const cls = overrideClass ?? STATUS_CLASS[value] ?? '';
    const label = STATUS_LABEL[value] ?? value;
    return `<span class="badge ${cls}">${esc(label)}</span>`;
}

export function roleBadge(role) {
    const colors = {
        admin: 'brand',
        agent_communal: 'brand',
        responsable_organisation: 'ok',
        chauffeur: 'warning',
        user: '',
    };
    return `<span class="badge ${colors[role] ?? ''}">${esc(role)}</span>`;
}

// ── Date / Time ───────────────────────────────────────────────────────────────

export function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

export function fmtDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit', year: 'numeric' })
        + ' ' + d.toLocaleTimeString('fr-BE', { hour: '2-digit', minute: '2-digit' });
}

export function fmtTime(t) {
    if (!t) return '—';
    return String(t).slice(0, 5);
}

// ── Escape ────────────────────────────────────────────────────────────────────

export function esc(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── Notices ───────────────────────────────────────────────────────────────────

export function notice(msg, type = '') {
    return `<div class="notice ${type}">${esc(msg)}</div>`;
}

// Variante sans échappement — pour les messages contenant du HTML volontaire (listes d'erreurs, etc.)
export function noticeHtml(html, type = '') {
    return `<div class="notice ${type}">${html}</div>`;
}

export function loading() {
    return '<div class="loading">Chargement…</div>';
}

// ── Modal ─────────────────────────────────────────────────────────────────────

export function showModal(title, bodyHtml, onClose) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal">
            <div class="modal-header">
                <h3>${esc(title)}</h3>
                <button class="modal-close" type="button">&times;</button>
            </div>
            <div class="modal-body">${bodyHtml}</div>
        </div>
    `;
    document.body.appendChild(overlay);

    const close = () => { overlay.remove(); onClose?.(); };
    overlay.querySelector('.modal-close').addEventListener('click', close);
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

    return { el: overlay, close };
}

// ── Table ─────────────────────────────────────────────────────────────────────

export function table(headers, rows) {
    if (!rows.length) {
        return '<p class="muted" style="padding:16px 0">Aucun résultat.</p>';
    }
    const ths = headers.map(h => `<th>${esc(h)}</th>`).join('');
    const trs = rows.map(cells => `<tr>${cells.map(c => `<td>${c}</td>`).join('')}</tr>`).join('');
    return `<table><thead><tr>${ths}</tr></thead><tbody>${trs}</tbody></table>`;
}

// ── Toast ─────────────────────────────────────────────────────────────────────

let _toastEl = null;

export function toast(msg, type = '') {
    if (!_toastEl) {
        _toastEl = document.createElement('div');
        _toastEl.style.cssText =
            'position:fixed;bottom:20px;right:20px;z-index:200;display:grid;gap:8px;max-width:340px';
        document.body.appendChild(_toastEl);
    }
    const t = document.createElement('div');
    t.className = `notice ${type}`;
    t.style.cssText = 'margin:0;box-shadow:0 4px 12px rgba(0,0,0,.15)';
    t.textContent = msg;
    _toastEl.appendChild(t);
    setTimeout(() => t.remove(), 4000);
}

// ── Pagination link ───────────────────────────────────────────────────────────

export function paginationHtml(meta, onPage) {
    if (!meta || meta.last_page <= 1) return '';
    const prev = meta.current_page > 1
        ? `<button class="sm" data-page="${meta.current_page - 1}">‹ Précédent</button>` : '';
    const next = meta.current_page < meta.last_page
        ? `<button class="sm" data-page="${meta.current_page + 1}">Suivant ›</button>` : '';
    const info = `<span class="muted" style="font-size:12px">Page ${meta.current_page} / ${meta.last_page} — ${meta.total} résultats</span>`;
    const el = document.createElement('div');
    el.className = 'actions';
    el.style.marginTop = '12px';
    el.innerHTML = `${prev}${info}${next}`;
    el.querySelectorAll('[data-page]').forEach(btn =>
        btn.addEventListener('click', () => onPage(Number(btn.dataset.page)))
    );
    return el;
}
