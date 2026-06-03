import { isLoggedIn, getUser, logout, login, DEMO_ACTORS } from './auth.js';
import { navigate } from './router.js';
import { esc, roleBadge } from './ui.js';

import { renderLogin } from './pages/login.js';
import { renderDashboard } from './pages/dashboard.js';
import { renderReservations } from './pages/reservations.js';
import { renderPlanning } from './pages/planning.js';
import { renderRequests } from './pages/requests.js';
import { renderAdmin } from './pages/admin.js';
import { renderOrganizations } from './pages/organizations.js';
import { renderDocuments } from './pages/documents.js';
import { renderNotifications } from './pages/notifications.js';

// ── Nav config ────────────────────────────────────────────────────────────────

const NAV = [
    { path: '/dashboard',     label: 'Dashboard',     roles: null },
    { path: '/reservations',  label: 'Réservations',  roles: ['admin', 'agent_communal', 'responsable_organisation', 'user'] },
    { path: '/planning',      label: 'Planning',      roles: ['admin', 'agent_communal', 'chauffeur'] },
    { path: '/organizations', label: 'Organisations', roles: ['admin', 'agent_communal'] },
    { path: '/requests',      label: 'Demandes',      roles: ['admin', 'agent_communal', 'responsable_organisation', 'user'] },
    { path: '/admin',         label: 'Gestion',       roles: ['admin', 'agent_communal'] },
    { path: '/documents',     label: 'Documents',     roles: ['admin', 'agent_communal'] },
    { path: '/notifications', label: 'Notifications', roles: null },
];

// ── Route table ───────────────────────────────────────────────────────────────
// Ordered: more specific paths first

const ROUTES = [
    ['/login',              null,               renderLogin],
    ['/reservations/create',['admin','agent_communal','responsable_organisation','user'], renderReservations],
    ['/reservations',       ['admin','agent_communal','responsable_organisation','user'], renderReservations],
    ['/planning',           ['admin','agent_communal','chauffeur'],    renderPlanning],
    ['/organizations',      ['admin','agent_communal'],                renderOrganizations],
    ['/requests',           ['admin','agent_communal','responsable_organisation','user'], renderRequests],
    ['/admin',              ['admin','agent_communal'],                renderAdmin],
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
                return `<a href="#${p}"${active ? ' class="active"' : ''}>${esc(label)}</a>`;
            })
            .join('');
    }
}

// ── Dispatch ──────────────────────────────────────────────────────────────────

async function dispatch(path) {
    // Redirect unauthenticated users to login
    if (!isLoggedIn() && path !== '/login') {
        navigate('/login');
        return;
    }
    // Redirect authenticated users away from login
    if (isLoggedIn() && path === '/login') {
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