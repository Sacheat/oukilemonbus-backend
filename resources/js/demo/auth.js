import { api } from './api.js';

export const DEMO_ACTORS = [
    { email: 'admin.test@bus-communal.local',       label: 'Alice Admin',      role: 'admin',                    desc: 'Accès total — gestion complète' },
    { email: 'agent.test@bus-communal.local',        label: 'Bruno Agent',      role: 'agent_communal',           desc: 'Gestion opérationnelle' },
    { email: 'chauffeur.test@bus-communal.local',    label: 'Chloe Chauffeur',  role: 'chauffeur',                desc: 'Missions et planning personnel' },
    { email: 'responsable.test@bus-communal.local',  label: 'Emma Responsable', role: 'responsable_organisation', desc: 'Gère une organisation' },
    { email: 'user.test@bus-communal.local',         label: 'David User',       role: 'user',                    desc: 'Utilisateur standard' },
];

export function getUser() {
    const raw = sessionStorage.getItem('demo_user');
    if (!raw || raw === 'undefined' || raw === 'null') return null;
    try {
        const user = JSON.parse(raw);
        return user && typeof user === 'object' ? user : null;
    } catch {
        sessionStorage.removeItem('demo_user');
        return null;
    }
}

export function isLoggedIn() {
    return getUser() !== null;
}

export function hasRole(...roles) {
    const user = getUser();
    if (!user) return false;
    return roles.some(r => (user.roles ?? []).includes(r));
}

export function isInternal() {
    return hasRole('admin', 'agent_communal');
}

export async function login(email, password) {
    // 1. Purge toute session serveur existante (évite que guest middleware bloque le login)
    try { await api.post('/auth/logout'); } catch {}

    // 2. Obtenir un cookie CSRF frais via fetch() natif — évite la confusion baseURL axios (/api + /sanctum = mauvaise URL)
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' });

    // 3. Login — axios lit XSRF-TOKEN dans document.cookie et l'envoie en X-XSRF-TOKEN automatiquement
    const { data } = await api.post('/auth/login', { email, password });
    const user = data.data ?? data;
    if (!user || typeof user !== 'object') throw new Error('Réponse auth invalide');
    sessionStorage.setItem('demo_user', JSON.stringify(user));
    return user;
}

export async function logout() {
    try { await api.post('/auth/logout'); } catch {}
    sessionStorage.removeItem('demo_user');
}

export async function refreshUser() {
    try {
        const { data } = await api.get('/auth/me');
        const user = data.data ?? data;
        if (user && typeof user === 'object') {
            sessionStorage.setItem('demo_user', JSON.stringify(user));
        }
        return user ?? null;
    } catch {
        return null;
    }
}