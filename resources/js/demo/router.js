const routes = [];

export function route(pattern, handler) {
    // Convert :param to named capture groups
    const regex = new RegExp(
        '^' + pattern.replace(/:([^/]+)/g, '(?<$1>[^/]+)') + '$'
    );
    routes.push({ regex, handler });
}

export function navigate(path) {
    window.location.hash = `#${path}`;
}

function currentPath() {
    const hash = window.location.hash.slice(1);
    return hash.split('?')[0] || '/login';
}

export function currentQuery() {
    const hash = window.location.hash.slice(1);
    const q = hash.split('?')[1] ?? '';
    return Object.fromEntries(new URLSearchParams(q));
}

function match(path) {
    for (const r of routes) {
        const m = path.match(r.regex);
        if (m) return { handler: r.handler, params: m.groups ?? {} };
    }
    return null;
}

export function start(dispatch) {
    window.addEventListener('hashchange', () => dispatch(currentPath()));
    dispatch(currentPath());
}