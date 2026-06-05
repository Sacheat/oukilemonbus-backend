import { login, isLoggedIn, DEMO_ACTORS } from '../auth.js';
import { navigate, currentQuery } from '../router.js';
import { esc, roleBadge, notice } from '../ui.js';

export async function renderLogin(el) {
    // If already logged in, bounce to dashboard
    if (isLoggedIn()) {
        navigate('/dashboard');
        return;
    }

    const { verified } = currentQuery();

    el.innerHTML = `
        <div class="login-screen">
            <div class="login-card">
                <div class="login-title">Bus Communal</div>
                <p class="muted" style="margin:0 0 4px">Démo v2 — connexion via l'API réelle</p>

                <div id="login-notice">${verified ? notice('✓ Email vérifié ! Vous pouvez maintenant vous connecter.') : ''}</div>

                <div class="role-switcher" id="role-switcher">
                    ${DEMO_ACTORS.map((a, i) => `
                        <button class="role-btn" data-idx="${i}" type="button">
                            <div>
                                <div class="role-name">${esc(a.label)}</div>
                                <div class="role-label">${esc(a.desc)}</div>
                            </div>
                            ${roleBadge(a.role)}
                        </button>
                    `).join('')}
                </div>

                <details style="margin-top:16px">
                    <summary style="cursor:pointer;color:var(--muted);font-size:12px">Connexion manuelle</summary>
                    <form id="manual-login" style="margin-top:12px;display:grid;gap:10px">
                        <label>Email<input type="email" name="email" placeholder="email@example.com"></label>
                        <label>Mot de passe<input type="password" name="password" value="password"></label>
                        <button type="submit" class="primary">Se connecter</button>
                    </form>
                </details>

                <p style="text-align:center;font-size:12px;color:var(--muted);margin-top:16px">
                    Pas encore de compte ? <a href="#/register">S'inscrire</a>
                </p>
            </div>
        </div>
    `;

    el.querySelectorAll('.role-btn').forEach(btn => {
        btn.addEventListener('click', () => doLogin(btn.dataset.idx, el));
    });

    el.querySelector('#manual-login').addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        await doLogin(null, el, fd.get('email'), fd.get('password'));
    });
}

async function doLogin(idx, el, email, password) {
    const noticeEl = el.querySelector('#login-notice');
    noticeEl.innerHTML = '';

    let credentials;
    if (idx !== null) {
        const actor = DEMO_ACTORS[idx];
        credentials = { email: actor.email, password: 'password' };
    } else {
        credentials = { email, password };
    }

    const btns = el.querySelectorAll('button');
    btns.forEach(b => b.disabled = true);

    try {
        await login(credentials.email, credentials.password);
        navigate('/dashboard');
    } catch (err) {
        noticeEl.innerHTML = notice(err.response?.data?.message || 'Échec de connexion', 'error');
        btns.forEach(b => b.disabled = false);
    }
}