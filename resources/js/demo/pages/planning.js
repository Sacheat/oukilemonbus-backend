import { api, apiError } from '../api.js';
import { esc, badge, fmtTime, notice } from '../ui.js';

export async function renderPlanning(el) {
    const today = new Date();
    const monday = getMonday(today);

    el.innerHTML = `
        <div class="split">
            <h1>Planning</h1>
            <div class="actions">
                <button id="btn-prev">‹ Semaine préc.</button>
                <span id="week-label" style="font-size:13px;color:var(--muted)"></span>
                <button id="btn-next">Semaine suiv. ›</button>
            </div>
        </div>
        <div id="planning-content"><div class="loading">Chargement…</div></div>
    `;

    let currentMonday = monday;

    async function load(mon) {
        currentMonday = mon;
        const from = toIso(mon);
        const sun = new Date(mon);
        sun.setDate(sun.getDate() + 6);
        const to = toIso(sun);

        el.querySelector('#week-label').textContent = `${fmtShort(mon)} – ${fmtShort(sun)}`;
        el.querySelector('#planning-content').innerHTML = '<div class="loading">Chargement…</div>';

        try {
            const { data } = await api.get('/planning', { params: { date_from: from, date_to: to } });
            renderWeek(el.querySelector('#planning-content'), data.data ?? [], mon);
        } catch (err) {
            el.querySelector('#planning-content').innerHTML = notice(apiError(err), 'error');
        }
    }

    el.querySelector('#btn-prev').addEventListener('click', () => {
        const prev = new Date(currentMonday);
        prev.setDate(prev.getDate() - 7);
        load(prev);
    });
    el.querySelector('#btn-next').addEventListener('click', () => {
        const next = new Date(currentMonday);
        next.setDate(next.getDate() + 7);
        load(next);
    });

    await load(monday);
}

function renderWeek(container, reservations, monday) {
    const days = Array.from({ length: 7 }, (_, i) => {
        const d = new Date(monday);
        d.setDate(d.getDate() + i);
        return d;
    });

    const byDay = {};
    days.forEach(d => { byDay[toIso(d)] = []; });
    reservations.forEach(r => {
        const key = r.trip_date?.slice(0, 10);
        if (byDay[key]) byDay[key].push(r);
    });

    const DAY_NAMES = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

    const html = `
        <div class="grid" style="grid-template-columns:repeat(7,1fr);gap:8px">
            ${days.map((d, i) => {
                const key = toIso(d);
                const items = byDay[key] ?? [];
                const isToday = key === toIso(new Date());
                return `
                    <div class="panel" style="${isToday ? 'border-color:var(--brand)' : ''}">
                        <div style="font-weight:650;font-size:12px;margin-bottom:8px;color:${isToday ? 'var(--brand)' : 'var(--muted)'}">
                            ${DAY_NAMES[i]} ${d.getDate()}/${d.getMonth() + 1}
                        </div>
                        ${items.length === 0
                            ? '<div style="font-size:11px;color:var(--muted)">—</div>'
                            : items.map(r => renderSlot(r)).join('')
                        }
                    </div>
                `;
            }).join('')}
        </div>
    `;

    container.innerHTML = html;
}

function renderSlot(r) {
    return `
        <div style="background:var(--brand-soft);border-radius:5px;padding:6px 8px;margin-bottom:6px;font-size:11px;border-left:3px solid var(--brand)">
            <div style="font-weight:650">${fmtTime(r.departure_time)} – ${fmtTime(r.return_time)}</div>
            <div style="color:var(--muted);margin-top:2px">${esc(r.organization?.name ?? '—')}</div>
            <div style="margin-top:3px">${badge(r.status)}</div>
            ${r.driver ? `<div style="color:var(--muted);margin-top:2px">🚌 ${esc(r.driver.user?.first_name)} ${esc(r.driver.user?.last_name)}</div>` : ''}
        </div>
    `;
}

function getMonday(d) {
    const date = new Date(d);
    const day = date.getDay();
    const diff = date.getDate() - day + (day === 0 ? -6 : 1);
    date.setDate(diff);
    date.setHours(0, 0, 0, 0);
    return date;
}

function toIso(d) {
    return d.toISOString().slice(0, 10);
}

function fmtShort(d) {
    return d.toLocaleDateString('fr-BE', { day: '2-digit', month: '2-digit' });
}