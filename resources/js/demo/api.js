import axios from 'axios';

export const api = axios.create({
    baseURL: '/api',
    withCredentials: true, // needed for session cookie auth (Sanctum SPA)
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
});

api.interceptors.response.use(
    res => res,
    err => {
        if (err.response?.status === 401) {
            sessionStorage.removeItem('demo_user');
            window.location.hash = '#/login';
        }
        return Promise.reject(err);
    }
);

export function apiError(err) {
    if (err.response?.data?.errors) {
        return Object.values(err.response.data.errors).flat().join(' ');
    }
    return err.response?.data?.message || err.message || 'Erreur inconnue';
}