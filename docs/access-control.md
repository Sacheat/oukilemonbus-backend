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
- Création par un admin (`POST /api/users`) : le compte reçoit le même email de vérification qu'à l'inscription (file d'attente, lien vers `{FRONTEND_URL}/verify-email`) ; une panne d'envoi ne fait pas échouer la création
- `users.send-password-reset` — `POST /api/users/{user}/password-reset-link` : envoie à l'email du compte le même lien que « Mot de passe oublié » (`{FRONTEND_URL}/reset-password?token=…&email=…`, 60 min). L'admin ne fixe aucun mot de passe et le jeton n'apparaît jamais dans la réponse (202 `{ message }`). 422 sur `user` si le compte est désactivé (« Ce compte est désactivé : réactivez-le avant d'envoyer un lien. ») ou si un lien a été envoyé il y a moins de 60 s (`auth.passwords.users.throttle`). Route limitée à 6 requêtes par minute
- Un compte désactivé est bloqué par `EnsureUserIsActive` avant toute vérification de permission

**Toujours un administrateur actif**

La plateforme garde au moins un administrateur actif (rôle `admin` **et** `active = true`). `UserService` refuse, en 422 :

| Route | Cas refusé | Champ | Message |
|---|---|---|---|
| `PATCH /api/users/{user}/roles` | `roles` ne contient plus `admin` pour le dernier admin actif | `roles` | « Impossible de retirer le rôle administrateur du dernier administrateur actif. » |
| `PATCH /api/users/{user}/status` | `active=false` sur le dernier admin actif | `active` | « Impossible de désactiver le dernier administrateur actif. » |
| `PATCH /api/users/{user}/deactivate` | dernier admin actif | `user` (repris dans `message`) | « Impossible de désactiver le dernier administrateur actif. » |

Un admin déjà désactivé ne compte pas. Les lignes des admins actifs sont verrouillées pendant la vérification, pour que deux retraits simultanés ne passent pas tous les deux. Un admin ne peut par ailleurs pas retirer son propre rôle `admin` (« Vous ne pouvez pas retirer votre propre rôle administrateur. », vérifié avant).

**Liste des comptes (`GET /api/users`)**

Paramètres combinables, conservés dans les liens de pagination (15 par page, plus récents d'abord) :

- `search` : chaque mot doit apparaître dans le prénom, le nom ou l'email ;
- `role` : un rôle global (`admin`, `agent_communal`, `chauffeur`, `user`) ;
- `active` : `1`/`true` (comptes actifs) ou `0`/`false` (désactivés).

Une valeur invalide renvoie un 422. `GET /api/users/{user}` renvoie le compte avec `roles`, `organizations` (avec `membership`) et `driver`.

---

## Rôles

4 rôles globaux définis dans `RolesAndPermissionsSeeder` via Spatie Laravel Permission :

| Rôle | Description |
|---|---|
| `admin` | Toutes les permissions. Réservé aux développeurs et au support applicatif. |
| `agent_communal` | Toutes les permissions métier. Gère le flux opérationnel quotidien. |
| `user` | Rôle de tout nouvel inscrit : demande à rejoindre ou créer une organisation, réserve pour ses organisations. |
| `chauffeur` | Lecture seule de ses missions, indisponibilités et feuilles de route. Attribué automatiquement par `POST /api/drivers`. |

Les rôles globaux se modifient via `PATCH /api/users/{user}/roles` (permission `users.manage-roles`, admin uniquement).

### Responsable d'organisation : une relation, pas un rôle

Être responsable dépend de la relation avec **chaque** organisation (`user_organization.relationship_type`) :

- `responsable` ou `directeur` (`UserOrganization::MANAGER_TYPES`) : gère les réservations **et** les membres de cette organisation ;
- `prof`, `animateur`, `referent`, `membre` : simple membre, voit les réservations, décisions et documents de l'organisation, gère seulement ses propres réservations.

Un même utilisateur peut donc être responsable d'une ASBL et simple membre d'une école. Méthodes : `User::isMemberOf()`, `User::managesOrganization()`. L'ancien rôle global `responsable_organisation` a été supprimé (il donnait les droits de gestion sur toutes les organisations d'un membre).

**Distinction admin / agent_communal**

L'admin seul peut :
- `users.create` — création de comptes hors inscription
- `users.update-status` — modération des comptes (bloquer/débloquer)
- `users.deactivate` — désactivation définitive d'un compte
- `users.manage-roles` — attribution des rôles globaux
- `users.send-password-reset` — envoi d'un lien de réinitialisation du mot de passe à un compte
- `organizations.ban` — bannissement d'une organisation
- `notifications.monitor` — health check système (outil de support/debug)
- `tooling.access` — module Tooling : journal Backend Inspector et Dev Toolbox en lecture (voir [`tooling.md`](tooling.md))

---

## Permissions (56)

### users

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `users.view` | ✓ | ✓ | ✓ | ✓ |
| `users.create` | ✓ | — | — | — |
| `users.update` | ✓ | ✓ | — | — |
| `users.update-status` | ✓ | — | — | — |
| `users.deactivate` | ✓ | — | — | — |
| `users.manage-roles` | ✓ | — | — | — |
| `users.send-password-reset` | ✓ | — | — | — |

### locations

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `locations.view` | ✓ | ✓ | ✓ | ✓ |
| `locations.create` | ✓ | ✓ | ✓ | — |
| `locations.update` | ✓ | ✓ | — | — |

### municipalities

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `municipalities.view` | ✓ | ✓ | ✓ | ✓ |
| `municipalities.manage` | ✓ | ✓ | — | — |

### vehicles

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `vehicles.view` | ✓ | ✓ | — | ✓ |
| `vehicles.create` | ✓ | ✓ | — | — |
| `vehicles.update` | ✓ | ✓ | — | — |
| `vehicles.deactivate` | ✓ | ✓ | — | — |

### drivers

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `drivers.view` | ✓ | ✓ | — | ✓ |
| `drivers.create` | ✓ | ✓ | — | — |
| `drivers.update` | ✓ | ✓ | — | — |
| `drivers.deactivate` | ✓ | ✓ | — | — |

### org-registration-requests

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `org-registration-requests.view` | ✓ | ✓ | ✓ | ✓ |
| `org-registration-requests.create` | ✓ | ✓ | ✓ | ✓ |
| `org-registration-requests.review` | ✓ | ✓ | — | — |

### user-org-requests

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `user-org-requests.view` | ✓ | ✓ | ✓ | ✓ |
| `user-org-requests.create` | ✓ | ✓ | ✓ | ✓ |
| `user-org-requests.review` | ✓ | ✓ | — | — |

### organizations

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `organizations.view` | ✓ | ✓ | ✓ | ✓ |
| `organizations.create` | ✓ | ✓ | — | — |
| `organizations.update` | ✓ | ✓ | — | — |
| `organizations.ban` | ✓ | — | — | — |

### unavailabilities

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `unavailabilities.view` | ✓ | ✓ | — | ✓ |
| `unavailabilities.create` | ✓ | ✓ | — | — |
| `unavailabilities.update` | ✓ | ✓ | — | — |
| `unavailabilities.cancel` | ✓ | ✓ | — | — |

### reservations

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `reservations.view` | ✓ | ✓ | ✓ | ✓ |
| `reservations.create` | ✓ | ✓ | ✓ | — |
| `reservations.update` | ✓ | ✓ | ✓ | — |
| `reservations.approve` | ✓ | ✓ | — | — |
| `reservations.reject` | ✓ | ✓ | — | — |
| `reservations.cancel` | ✓ | ✓ | ✓ | — |
| `reservations.assign` | ✓ | ✓ | — | — |
| `reservations.confirm` | ✓ | ✓ | — | — |
| `reservations.manage-conflicts` | ✓ | ✓ | — | — |

### trip-reports

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `trip-reports.submit` | ✓ | — | — | ✓ |
| `trip-reports.manage` | ✓ | ✓ | — | — |

`submit` : le chauffeur affecté déclare le compte rendu de son trajet, de l'heure de départ jusqu'au lendemain 12:00 (attendu le jour même). `manage` : voir, saisir et corriger tout compte rendu, dès l'heure de départ et sans délai (voir `docs/reservations.md` §6.2).

### council-decisions

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `council-decisions.view` | ✓ | ✓ | ✓ | ✓ |
| `council-decisions.create` | ✓ | ✓ | — | — |
| `council-decisions.update` | ✓ | ✓ | — | — |

### documents

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `documents.view` | ✓ | ✓ | ✓ | ✓ |
| `documents.download` | ✓ | ✓ | ✓ | ✓ |
| `documents.generate` | ✓ | ✓ | — | — |
| `documents.update` | ✓ | ✓ | — | — |

### notifications

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `notifications.monitor` | ✓ | — | — | — |

### recurrences

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `recurrences.cancel` | ✓ | ✓ | ✓ | — |

### tooling

| Permission | admin | agent | user | chauffeur |
|---|:---:|:---:|:---:|:---:|
| `tooling.access` | ✓ | — | — | — |

---

## Contrôle d'accès contextuel (Policies)

Au-delà des permissions, les **Policies Laravel** affinent l'accès selon le contexte métier.

### Règles principales par ressource

**Réservations**
- Membre d'une organisation : voit (liste et détail) les réservations de ses organisations
- Modifier, annuler, confirmer les passagers : le demandeur ou un responsable de l'organisation
- `chauffeur` : voit aussi les réservations où il est assigné
- `agent_communal` / `admin` : voient et gèrent tout

**Membres d'une organisation**
- Liste : membres de l'organisation et internes
- Changer le type ou retirer un membre : responsables de l'organisation et agents ; l'organisation garde toujours au moins un responsable

**Documents**
- `file_path` n'est **jamais** exposé dans les réponses API (masqué dans `DocumentResource`)
- L'accès au téléchargement vérifie : permission `documents.download` + appartenance à la réservation concernée
- Un token signé est nécessaire pour les liens partagés hors session

**Indisponibilités**
- `chauffeur` : ne voit que ses propres indisponibilités (driver_id = son profil)

**Décisions du Collège**
- Externes : décisions liées aux réservations de leurs organisations (ou dont ils sont chauffeur)

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
| `POST /api/auth/forgot-password` | 6 req/min |
| `POST /api/auth/reset-password` | 6 req/min |
| `POST /api/auth/logout` | 30 req/min |
| `GET /api/notifications/health` | 30 req/min |
| `GET /api/planning/export` | 20 req/min |
| `GET /api/documents/{id}/signed-download` | 30 req/min |