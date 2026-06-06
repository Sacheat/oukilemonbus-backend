import { api, apiError } from '../api.js';
import { navigate } from '../router.js';
import { esc, notice } from '../ui.js';
import { isLoggedIn } from '../auth.js';

export async function renderRegister(el) {
    if (isLoggedIn()) {
        navigate('/dashboard');
        return;
    }

    el.innerHTML = `
        <div class="login-screen">
            <div class="login-card">
                <div class="login-title">Créer un compte</div>
                <p class="muted" style="margin:0 0 16px;font-size:13px">
                    Bus Communal de Dinant — inscription
                </p>

                <div id="reg-notice"></div>
                <div id="reg-form-wrap">
                    <form id="reg-form" novalidate style="display:grid;gap:12px">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                            <label>Prénom <span style="color:var(--danger)">*</span>
                                <input type="text" name="first_name" placeholder="Alice" autocomplete="given-name" required>
                                <div class="field-error" id="err-first_name"></div>
                            </label>
                            <label>Nom <span style="color:var(--danger)">*</span>
                                <input type="text" name="last_name" placeholder="Martin" autocomplete="family-name" required>
                                <div class="field-error" id="err-last_name"></div>
                            </label>
                        </div>
                        <label>Adresse email <span style="color:var(--danger)">*</span>
                            <input type="email" name="email" placeholder="alice@example.com" autocomplete="email" required>
                            <div class="field-error" id="err-email"></div>
                        </label>
                        <label>Téléphone
                            <input type="tel" name="phone" placeholder="082 12 34 56" autocomplete="tel">
                            <div class="field-error" id="err-phone"></div>
                        </label>
                        <label>Mot de passe <span style="color:var(--danger)">*</span>
                            <input type="password" name="password" autocomplete="new-password" required>
                            <div class="field-error" id="err-password"></div>
                        </label>
                        <label>Confirmer le mot de passe <span style="color:var(--danger)">*</span>
                            <input type="password" name="password_confirmation" autocomplete="new-password" required>
                        </label>

                        <button type="submit" class="primary" id="btn-register" style="margin-top:4px">
                            Créer mon compte
                        </button>
                    </form>
                </div>

                <div id="reg-success" style="display:none;text-align:center;padding:8px 0">
                    <div style="font-size:32px;margin-bottom:12px">📬</div>
                    <strong>Compte créé !</strong>
                    <p style="color:var(--muted);font-size:13px;margin:8px 0 16px">
                        Un email de vérification a été envoyé à votre adresse.<br>
                        Cliquez sur le lien pour activer votre compte.
                    </p>
                    <button id="btn-resend" class="sm">Renvoyer l'email</button>
                    <div id="resend-notice" style="margin-top:8px"></div>
                </div>

                <p style="text-align:center;font-size:12px;color:var(--muted);margin-top:16px">
                    Déjà un compte ? <a href="#/login">Se connecter</a>
                </p>
            </div>
        </div>
    `;

    el.querySelector('#reg-form').addEventListener('submit', async e => {
        e.preventDefault();
        clearErrors(el);
        el.querySelector('#reg-notice').innerHTML = '';

        const fd = new FormData(e.target);
        const body = Object.fromEntries(
            [...fd.entries()].filter(([, v]) => v !== '')
        );

        const btn = el.querySelector('#btn-register');
        btn.disabled = true;
        btn.textContent = 'Création…';

        try {
            await api.post('/auth/register', body);
            el.querySelector('#reg-form-wrap').style.display = 'none';
            el.querySelector('#reg-success').style.display = '';
        } catch (err) {
            if (err.response?.status === 422) {
                const errors = err.response.data.errors ?? {};
                Object.entries(errors).forEach(([field, msgs]) => {
                    const errEl = el.querySelector(`#err-${field}`);
                    if (errEl) errEl.textContent = Array.isArray(msgs) ? msgs[0] : msgs;
                });
                el.querySelector('#reg-notice').innerHTML = notice('Corrigez les erreurs.', 'error');
            } else {
                el.querySelector('#reg-notice').innerHTML = notice(apiError(err), 'error');
            }
            btn.disabled = false;
            btn.textContent = 'Créer mon compte';
        }
    });

    el.querySelector('#btn-resend').addEventListener('click', async () => {
        const resendNotice = el.querySelector('#resend-notice');
        const btn = el.querySelector('#btn-resend');
        btn.disabled = true;
        try {
            await api.post('/email/verify/resend');
            resendNotice.innerHTML = notice('Email renvoyé !');
        } catch (err) {
            resendNotice.innerHTML = notice(apiError(err), 'error');
        } finally {
            btn.disabled = false;
        }
    });
}

function clearErrors(el) {
    el.querySelectorAll('.field-error').forEach(e => { e.textContent = ''; });
}