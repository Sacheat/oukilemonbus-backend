# Contrôle d'accès et permissions

Ce document décrit le système d'authentification, d'autorisation et de contrôle d'accès du backend OuKiLéMonBus.

---

## Authentification

Le backend utilise **Laravel Sanctum** en mode SPA cookie. Le frontend appelle `GET /sanctum/csrf-cookie` pour initialiser la session, puis s'authentifie via `POST /api/auth/login`.

Les routes API sensibles sont protégées par `middleware(['auth:sanctum', 'active'])`. Un utilisateur non authentifié reçoit `401 Unauthorized` ; un compte désactivé reçoit `403 Forbidden`.

La vérification email est requise pour toutes les routes métier (`middleware('verified')`). Seules `/auth/me`, `/auth/logout` et `/email/verify/resend` sont accessibles sans email vérifié.

**Cycle de vie d'un compte**

- `users.update-status` — suspend ou réactive un compte (`active` true/false)
- `users.deactivate` — désactivation définitive : passe `active` à `false` **et révoque tous les tokens Sanctum** du compte
- Un compte désactivé est bloqué par `EnsureUserIsActive` avant toute vérification de permission

---

## Rôles

5 rôles définis dans `RolesAndPermissionsSeeder` via Spatie Laravel Permission :

| Rôle | Description |
|---|---|
| `admin` | Toutes les permissions. Réservé aux développeurs et au support applicatif. |
| `agent_communal` | Toutes les permissions métier. Gère le flux opérationnel quotidien. |
| `responsable_organisation` | Accès restreint à son organisation et ses réservations. |
| `user` | Peut créer des demandes et consulter ses réservations. |
| `chauffeur` | Lecture seule de son planning et de ses véhicules. |

**Distinction admin / agent_communal**

L'admin seul peut :
- `users.create` — la création de comptes passe par l'inscription, pas par un agent
- `users.update-status` — modération des comptes (bloquer/débloquer)
- `users.deactivate` — désactivation définitive d'un compte
- `organizations.ban` — bannissement d'une organisation
- `notifications.monitor` — health check système (outil de support/debug)

---

## Permissions (50)

### users

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `users.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `users.create` | ✓ | — | — | — | — |
| `users.update` | ✓ | ✓ | — | — | — |
| `users.update-status` | ✓ | — | — | — | — |
| `users.deactivate` | ✓ | — | — | — | — |

### locations

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `locations.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `locations.create` | ✓ | ✓ | ✓ | ✓ | — |
| `locations.update` | ✓ | ✓ | — | — | — |

### municipalities

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `municipalities.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `municipalities.manage` | ✓ | ✓ | — | — | — |

### vehicles

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `vehicles.view` | ✓ | ✓ | — | — | ✓ |
| `vehicles.create` | ✓ | ✓ | — | — | — |
| `vehicles.update` | ✓ | ✓ | — | — | — |
| `vehicles.deactivate` | ✓ | ✓ | — | — | — |

### drivers

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `drivers.view` | ✓ | ✓ | — | — | ✓ |
| `drivers.create` | ✓ | ✓ | — | — | — |
| `drivers.update` | ✓ | ✓ | — | — | — |
| `drivers.deactivate` | ✓ | ✓ | — | — | — |

### org-registration-requests

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `org-registration-requests.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `org-registration-requests.create` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `org-registration-requests.review` | ✓ | ✓ | — | — | — |

### user-org-requests

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `user-org-requests.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `user-org-requests.create` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `user-org-requests.review` | ✓ | ✓ | — | — | — |

### organizations

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `organizations.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `organizations.create` | ✓ | ✓ | — | — | — |
| `organizations.update` | ✓ | ✓ | — | — | — |
| `organizations.ban` | ✓ | — | — | — | — |

### unavailabilities

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `unavailabilities.view` | ✓ | ✓ | — | — | ✓ |
| `unavailabilities.create` | ✓ | ✓ | — | — | — |
| `unavailabilities.update` | ✓ | ✓ | — | — | — |
| `unavailabilities.cancel` | ✓ | ✓ | — | — | — |

### reservations

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `reservations.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `reservations.create` | ✓ | ✓ | ✓ | ✓ | — |
| `reservations.update` | ✓ | ✓ | ✓ | ✓ | — |
| `reservations.approve` | ✓ | ✓ | — | — | — |
| `reservations.reject` | ✓ | ✓ | — | — | — |
| `reservations.cancel` | ✓ | ✓ | ✓ | ✓ | — |
| `reservations.assign` | ✓ | ✓ | — | — | — |
| `reservations.confirm` | ✓ | ✓ | — | — | — |
| `reservations.manage-conflicts` | ✓ | ✓ | — | — | — |

### council-decisions

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `council-decisions.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `council-decisions.create` | ✓ | ✓ | — | — | — |
| `council-decisions.update` | ✓ | ✓ | — | — | — |

### documents

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `documents.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `documents.download` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `documents.generate` | ✓ | ✓ | — | — | — |
| `documents.update` | ✓ | ✓ | — | — | — |

### notifications

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `notifications.monitor` | ✓ | — | — | — | — |

### recurrences

| Permission | admin | agent | responsable | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| `recurrences.cancel` | ✓ | ✓ | ✓ | ✓ | — |

---

## Contrôle d'accès contextuel (Policies)

Au-delà des permissions, les **Policies Laravel** affinent l'accès selon le contexte métier.

### Règles principales par ressource

**Réservations**
- `user` / `responsable_organisation` : ne voient que les réservations de leur organisation
- `chauffeur` : ne voit que les réservations où il est assigné
- `agent_communal` / `admin` : voient tout

**Documents**
- `file_path` n'est **jamais** exposé dans les réponses API (masqué dans `DocumentResource`)
- L'accès au téléchargement vérifie : permission `documents.download` + appartenance à la réservation concernée
- Un token signé est nécessaire pour les liens partagés hors session

**Indisponibilités**
- `chauffeur` : ne voit que ses propres indisponibilités (driver_id = son profil)

**Décisions du Collège**
- `user` / `responsable_organisation` : ne voient que les décisions liées à leurs réservations

**Organisations (ban)**
- `organizations.ban` est exclusif à l'`admin`
- Un agent communal ne peut pas bannir une organisation

---

## Implémentation

```
Requête HTTP
  → middleware('auth:sanctum')       # L'utilisateur est-il connecté ?          → 401 sinon
  → middleware('active')             # Le compte est-il actif ?                  → 403 sinon
  → middleware('verified')           # L'email est-il vérifié ? (routes métier)  → 403 sinon
  → middleware('permission:X.Y')     # A-t-il cette permission ?                 → 403 sinon
  → Policy::method($user, $model)    # L'accès contextuel est-il autorisé ?      → 403 sinon
  → FormRequest::authorize()         # Validation supplémentaire si besoin
```

### Dans une route

```php
Route::middleware('permission:reservations.approve')
    ->patch('/reservations/{reservation}/approve', [ReservationController::class, 'approve']);
```

### Dans un controller avec policy

```php
$this->authorize('approve', $reservation);
```

### Dans un service (Gate)

```php
if (! Gate::forUser($actor)->allows('createForOrganization', [Reservation::class, $organization])) {
    throw new AuthorizationException('Action non autorisee pour cette organisation.');
}
```

---

## Sécurité des cookies et sessions

| Variable | Recommandation production |
|---|---|
| `SESSION_HTTP_ONLY` | `true` — bloque l'accès JS au cookie |
| `SESSION_SAME_SITE` | `lax` |
| `SESSION_SECURE_COOKIE` | `true` (HTTPS uniquement) |
| `SANCTUM_STATEFUL_DOMAINS` | Liste stricte des frontends autorisés |
| `CORS_ALLOWED_ORIGINS` | Liste stricte des origines de confiance |

---

## Rate limiting (routes sensibles)

| Route | Limite |
|---|---|
| `POST /api/auth/register` | 10 req/min |
| `POST /api/auth/login` | 20 req/min |
| `POST /api/auth/logout` | 30 req/min |
| `GET /api/notifications/health` | 30 req/min |
| `GET /api/planning/export` | 20 req/min |
| `GET /api/documents/{id}/signed-download` | 30 req/min |