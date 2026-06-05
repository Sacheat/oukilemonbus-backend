import { isLoggedIn, getUser, logout, login, DEMO_ACTORS } from './auth.js';
import { navigate } from './router.js';
import { esc, roleBadge } from './ui.js';
import { api } from './api.js';

import { renderLogin } from './pages/login.js';
import { renderRegister } from './pages/register.js';
import { renderDashboard } from './pages/dashboard.js';
import { renderReservations } from './pages/reservations.js';
import { renderPlanning } from './pages/planning.js';
import { renderRequests } from './pages/requests.js';
import { renderAdmin } from './pages/admin.js';
import { renderOrganizations } from './pages/organizations.js';
import { renderDocuments } from './pages/documents.js';
import { renderNotifications } from './pages/notifications.js';
import { renderConflicts, loadConflictsBadge } from './pages/conflicts.js';

// ── Nav config ────────────────────────────────────────────────────────────────

const NAV = [
    { path: '/dashboard',     label: 'Dashboard',     roles: null },
    { path: '/reservations',  label: 'Réservations',  roles: ['admin', 'agent_communal', 'responsable_organisation', 'user'] },
    { path: '/planning',      label: 'Planning',      roles: ['admin', 'agent_communal', 'chauffeur'] },
    { path: '/organizations', label: 'Organisations', roles: ['admin', 'agent_communal'] },
    { path: '/requests',      label: 'Demandes',      roles: ['admin', 'agent_communal', 'responsable_organisation', 'user'] },
    { path: '/admin',         label: 'Gestion',       roles: ['admin', 'agent_communal'] },
    { path: '/conflicts',     label: 'Conflits',      roles: ['admin', 'agent_communal'] },
    { path: '/documents',     label: 'Documents',     roles: ['admin', 'agent_communal'] },
    { path: '/notifications', label: 'Notifications', roles: null },
];

// ── Route table ───────────────────────────────────────────────────────────────
// Ordered: more specific paths first

const ROUTES = [
    ['/login',              null,               renderLogin],
    ['/register',           null,               renderRegister],
    ['/reservations/create',['admin','agent_communal','responsable_organisation','user'], renderReservations],
    ['/reservations',       ['admin','agent_communal','responsable_organisation','user'], renderReservations],
    ['/planning',           ['admin','agent_communal','chauffeur'],    renderPlanning],
    ['/organizations',      ['admin','agent_communal'],                renderOrganizations],
    ['/requests',           ['admin','agent_communal','responsable_organisation','user'], renderRequests],
    ['/admin',              ['admin','agent_communal'],                renderAdmin],
    ['/conflicts',          ['admin','agent_communal'],                renderConflicts],
    ['/documents',          ['admin','agent_communal'],                renderDocuments],
    ['/notifications',      null,               renderNotifications],
    ['/dashboard',          null,               renderDashboard],
];

// ── Shell ─────────────────────────────────────────────────────────────────────

let _shellAuth = null; // tracks whether shell is rendered for auth or guest

function renderShell(path) {
    const app = document.getElementById('app');
    const user = getUser();
    const authState = user ? 'auth' : 'guest';

    if (_shellAuth !== authState) {
        _shellAuth = authState;

        if (!user) {
            app.innerHTML = `<div id="content"></div>`;
            return;
        }

        const roles = user.roles ?? [];
        app.innerHTML = `
            <header>
                <div class="bar">
                    <div>
                        <div class="brand">Bus Communal — démo v2</div>
                        <nav id="main-nav"></nav>
                    </div>
                    <div class="user-bar">
                        ${roles.map(r => roleBadge(r)).join('')}
                        <strong>${esc(user.first_name)} ${esc(user.last_name)}</strong>
                        <div class="switcher-wrap" id="switcher-wrap">
                            <button id="btn-switch" class="sm">Changer de rôle ▾</button>
                            <div class="switcher-dropdown" id="switcher-dropdown">
                                ${DEMO_ACTORS.map((a, i) => `
                                    <button class="switcher-item${user.email === a.email ? ' active' : ''}" data-idx="${i}" type="button">
                                        <div>
                                            <div class="switcher-item-label">${esc(a.label)}</div>
                                            <div class="switcher-item-desc">${esc(a.desc)}</div>
                                        </div>
                                        ${roleBadge(a.role)}
                                    </button>
                                `).join('')}
                            </div>
                        </div>
                        <button id="btn-logout" class="sm">Déconnexion</button>
                    </div>
                </div>
            </header>
            <main id="content"></main>
        `;

        // Toggle dropdown
        document.getElementById('btn-switch').addEventListener('click', e => {
            e.stopPropagation();
            document.getElementById('switcher-dropdown').classList.toggle('open');
        });

        // Close on outside click
        document.addEventListener('click', () => {
            document.getElementById('switcher-dropdown')?.classList.remove('open');
        });

        // Switch actor
        app.querySelectorAll('.switcher-item').forEach(btn => {
            btn.addEventListener('click', async () => {
                const actor = DEMO_ACTORS[btn.dataset.idx];
                if (actor.email === getUser()?.email) {
                    document.getElementById('switcher-dropdown').classList.remove('open');
                    return;
                }
                btn.disabled = true;
                btn.textContent = 'Connexion…';
                try {
                    await logout();
                    await login(actor.email, 'password');
                    _shellAuth = null;
                    // If already on /dashboard, hashchange won't fire — dispatch manually
                    if (currentPath() === '/dashboard') {
                        dispatch('/dashboard');
                    } else {
                        navigate('/dashboard');
                    }
                } catch {
                    btn.disabled = false;
                    navigate('/login');
                }
            });
        });

        document.getElementById('btn-logout').addEventListener('click', async () => {
            stopBadgePolling();
            await logout();
            _shellAuth = null;
            navigate('/login');
        });
    }

    // Update active nav link on every navigation
    const nav = document.getElementById('main-nav');
    if (nav && user) {
        const roles = user.roles ?? [];
        nav.innerHTML = NAV
            .filter(item => !item.roles || item.roles.some(r => roles.includes(r)))
            .map(({ path: p, label }) => {
                const active = path === p || path.startsWith(p + '/');
                const badgeHtml = p === '/notifications'
                    ? ' <span id="notif-badge" style="display:none;background:var(--danger);color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:10px;vertical-align:middle"></span>'
                    : p === '/conflicts'
                    ? ' <span id="conflicts-badge" style="display:none;background:var(--danger);color:#fff;font-size:10px;font-weight:700;padding:1px 5px;border-radius:10px;vertical-align:middle"></span>'
                    : '';
                return `<a href="#${p}"${active ? ' class="active"' : ''}>${esc(label)}${badgeHtml}</a>`;
            })
            .join('');

        loadUnreadBadge();
        loadConflictsBadge();
        startBadgePolling();
    }
}

// ── Unread badge ──────────────────────────────────────────────────────────────

let _badgeInterval = null;

function startBadgePolling() {
    stopBadgePolling();
    _badgeInterval = setInterval(() => {
        loadUnreadBadge();
        loadConflictsBadge();
    }, 30_000);
}

function stopBadgePolling() {
    if (_badgeInterval !== null) {
        clearInterval(_badgeInterval);
        _badgeInterval = null;
    }
}

async function loadUnreadBadge() {
    if (!isLoggedIn()) return;
    try {
        const { data } = await api.get('/notifications/unread-count');
        const count = data.data?.unread_count ?? 0;
        const badge = document.getElementById('notif-badge');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'inline';
        } else {
            badge.style.display = 'none';
        }
    } catch { /* silencieux */ }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

async function dispatch(path) {
    const guestPaths = ['/login', '/register'];
    // Redirect unauthenticated users to login (except guest pages)
    if (!isLoggedIn() && !guestPaths.includes(path)) {
        navigate('/login');
        return;
    }
    // Redirect authenticated users away from guest pages
    if (isLoggedIn() && guestPaths.includes(path)) {
        navigate('/dashboard');
        return;
    }

    renderShell(path);

    const content = document.getElementById('content');
    if (!content) return;

    content.innerHTML = '<div class="loading">Chargement…</div>';

    // Match route
    const user = getUser();
    const userRoles = user?.roles ?? [];

    const matched = ROUTES.find(([p]) => path === p || path.startsWith(p + '/'));
    if (!matched) {
        content.innerHTML = '<div class="notice">Page introuvable.</div>';
        return;
    }

    const [, requiredRoles, handler] = matched;

    // Client-side role guard (API will enforce server-side anyway)
    if (requiredRoles && !requiredRoles.some(r => userRoles.includes(r))) {
        content.innerHTML = '<div class="notice error">Accès refusé — votre rôle ne permet pas cette page.</div>';
        return;
    }

    try {
        await handler(content, {});
    } catch (err) {
        if (err.response?.status === 403) {
            content.innerHTML = '<div class="notice error">Accès refusé (403) — permission insuffisante.</div>';
        } else if (err.response?.status !== 401) {
            content.innerHTML = `<div class="notice error">${err.response?.data?.message || err.message}</div>`;
        }
    }
}

// ── Boot ──────────────────────────────────────────────────────────────────────

function currentPath() {
    const hash = window.location.hash.slice(1).split('?')[0];
    return hash || '/login';
}

window.addEventListener('hashchange', () => dispatch(currentPath()));
dispatch(currentPath());