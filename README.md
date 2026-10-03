# Bus Communal — Backend

API REST Laravel pour la gestion du bus communal de la Commune de Dinant.

Le backend couvre l'ensemble du cycle de vie d'une réservation : de la demande initiale à la génération documentaire, en passant par la validation administrative, les conflits de planning, les décisions du Collège communal et les notifications.

---

## Stack technique

| Couche | Technologie |
|---|---|
| Langage | PHP 8.4 |
| Framework | Laravel 13 |
| Base de données | MariaDB 11.4 |
| Authentification | Laravel Sanctum (SPA cookie) |
| Autorisations | Spatie Laravel Permission |
| Géocodage / distances | OpenRouteService API |
| Emails | Mailpit en dev (service Docker, interface http://localhost:8025) / SMTP configurable (Mailtrap, serveur réel) |
| Queue | Laravel Queue — 3 niveaux : `critical`, `default`, `low` |
| Tests | PHPUnit 12 — 470 tests, 2024 assertions |
| Frontend | SPA séparée (dépôt distinct) — consomme uniquement `/api` |

---

## Services Docker

```
docker compose up -d
```

| Service | Rôle | Port exposé |
|---|---|---|
| `app` | PHP-FPM 8.4 | — |
| `nginx` | Reverse proxy | `8080` |
| `queue` | Worker de queues | — |
| `scheduler` | Tâches planifiées de `routes/console.php` (`schedule:work`, en `www-data`) : feuilles de route 20:00, rappels 08:00, clôture des réservations expirées 00:15… | — |
| `mariadb` | Base de données | `3307` |
| `adminer` | Interface base de données | `8081` |
| `mailpit` | Serveur d'emails de dev : reçoit tous les emails envoyés (vérification, mot de passe oublié, notifications), sans quota ni envoi réel. Interface web pour les lire et cliquer leurs liens | `8025` (web), `1025` (SMTP) |

---

## Prérequis

- Docker + Docker Compose

Le backend n'a plus de dépendance Node : il n'expose aucune page (la route `/` renvoie un JSON descriptif).

---

## Installation

```bash
# 1. Copier la configuration
cp .env.example .env

# 2. Configurer les variables essentielles dans .env :
#    - ORS_API_KEY (OpenRouteService)
#    - MAIL_* : les valeurs de .env.example pointent vers Mailpit (MAIL_HOST=mailpit, MAIL_PORT=1025),
#      rien à changer en dev ; emails lisibles sur http://localhost:8025
#    - APP_KEY (généré à l'étape suivante)

# 3. Démarrer les conteneurs
docker compose up -d

# 4. Installer les dépendances PHP
docker compose exec app composer install

# 5. Générer la clé applicative
docker compose exec app php artisan key:generate

# 6. Migrations + seeders
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed   # rôles/permissions + comptes et données de test (APP_ENV=local)
```

Utilisateur des processus : php-fpm et le worker de file (`queue`) tournent en `www-data`, pour que les fichiers générés en tâche de fond (exports du planning, feuilles de route…) restent lisibles par l'API. Un `docker compose exec app php artisan …` tourne en root : pour une commande qui écrit dans `storage/` (génération de documents, cache des vues…), préférer `docker compose exec -u www-data app php artisan …`. Au démarrage, l'entrypoint remet `storage/` et `bootstrap/cache/` à `www-data`.

Comptes de test créés en local (mot de passe `password`) : `admin`, `agent`, `reviewer`, `responsable`, `user`, `chauffeur` `.test@bus-communal.local` (`responsable` a le rôle `user` et est responsable de l'ASBL Les Amis du Parc).
En production, ne lancer que `db:seed --class=RolesAndPermissionsSeeder`.

---

## Mise à jour après un merge

```bash
# 1. Rebuild si Dockerfile a changé
docker compose build app

# 2. Redémarrer
docker compose up -d

# 3. Nouvelles migrations
docker compose exec app php artisan migrate

# 4. Re-seeder les permissions si RolesAndPermissionsSeeder a changé
docker compose exec app php artisan db:seed --class=RolesAndPermissionsSeeder

# 5. Vider les caches
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan cache:clear
docker compose exec app php artisan view:clear
```

Les workers `queue` et `scheduler` gardent la configuration en mémoire : après un changement du `.env`,
lancer `docker compose restart queue scheduler`.

### Passer de Mailtrap à Mailpit (dev)

La sandbox Mailtrap gratuite est limitée à 50 emails par mois ; au-delà, chaque envoi échoue (SMTP 535) et les jobs
s'accumulent dans `failed_jobs`. Mailpit reçoit tout, sans limite :

```bash
# 1. Démarrer Mailpit
docker compose up -d mailpit

# 2. Dans .env, remplacer les lignes MAIL_* par :
#    MAIL_MAILER=smtp
#    MAIL_SCHEME=null
#    MAIL_HOST=mailpit
#    MAIL_PORT=1025
#    MAIL_USERNAME=null
#    MAIL_PASSWORD=null
#    MAIL_FROM_ADDRESS="no-reply@bus-communal.local"
#    MAIL_FROM_NAME="${APP_NAME}"

# 3. Recharger la configuration (php-fpm, puis les workers qui la gardent en mémoire)
docker compose exec -u www-data app php artisan optimize:clear
docker compose restart queue scheduler

# 4. Renvoyer les emails restés en échec (ou « Tout relancer » dans la page front Outils › File d'attente)
docker compose exec -u www-data app php artisan queue:retry all
```

Les emails arrivent sur http://localhost:8025. `php artisan app:health` (et `GET /admin/tooling/toolbox/health`)
contrôle la connexion SMTP (`mail`) : `warn` si le serveur est injoignable ou refuse l'authentification.
Les tests n'envoient jamais d'email (`MAIL_MAILER=array` dans `phpunit.xml`).

---

## Tests

```bash
# Suite complète
docker compose exec app php artisan test

# Fichier précis
docker compose exec app php artisan test tests/Feature/ReservationApiTest.php

# Filtre par nom de test
docker compose exec app php artisan test --filter=test_agent_can_approve_reservation
```

La suite couvre : structure des migrations, contraintes SQL, relations Eloquent, casts, policies, tous les endpoints API, conflits de réservation, résolution automatique, récurrences, notifications, documents et commandes artisan.

---

## Modules API

129 routes, toutes préfixées `/api`. Sauf mention contraire, elles exigent une session Sanctum (cookie SPA), un compte actif et un email vérifié, plus la permission indiquée dans `routes/api.php`.
La référence complète pour le frontend (paramètres, réponses, règles métier, types TypeScript) est le **Guide API** partagé avec l'équipe front : https://claude.ai/artifact/J8W8b7YRXNaYgxPGKqZwEX (accès sur partage).

| Module | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/forgot-password`, `POST /auth/reset-password` (invité) · `GET /auth/me`, `POST /auth/logout` |
| Vérification email | `GET /email/verify/{id}/{hash}` (lien signé, public) · `POST /email/verify/resend` |
| Utilisateurs | `GET /users` (`search`, `role`, `active`), `GET /users/{user}`, `POST /users`, `PATCH /users/{user}`, `PATCH /users/{user}/password`, `/status`, `/roles`, `/deactivate`, `POST /users/{user}/password-reset-link` |
| Lieux | `GET /locations/suggest`, `GET /locations`, `GET /locations/{location}`, `POST /locations`, `PATCH /locations/{location}` |
| Communes | `GET /municipalities`, `GET /municipalities/{municipality}`, `POST /municipalities`, `PATCH /municipalities/{municipality}`, `POST /{municipality}/sync-localities`, `POST /{municipality}/localities`, `PATCH /municipality-localities/{id}`, `/deactivate` |
| Organisations | `GET /organizations`, `GET /organizations/{organization}`, `POST /organizations`, `PATCH /organizations/{organization}`, `POST /{organization}/ban`, `POST /{organization}/unban` |
| Membres d'une organisation | `GET /organizations/{organization}/members`, `PATCH /members/{user}`, `DELETE /members/{user}`, `POST /{organization}/leave`, `PATCH /{organization}/default` |
| Demandes de création d'organisation | `GET /org-registration-requests`, `GET /{id}`, `POST /org-registration-requests`, `PATCH /{id}/approve`, `PATCH /{id}/reject`, `POST /{id}/proof`, `DELETE /{id}/proof` |
| Demandes d'adhésion | `GET /user-org-requests/available-organizations`, `GET /user-org-requests`, `GET /{id}`, `POST /user-org-requests`, `PATCH /{id}/approve`, `PATCH /{id}/reject`, `POST /{id}/proof`, `DELETE /{id}/proof` |
| Chauffeurs | `GET /drivers`, `GET /drivers/{driver}`, `POST /drivers`, `PATCH /drivers/{driver}`, `/activate`, `/deactivate` |
| Véhicules | `GET /vehicles`, `GET /vehicles/{vehicle}`, `POST /vehicles`, `PATCH /vehicles/{vehicle}`, `/activate`, `/deactivate` |
| Réservations | `GET /reservations` (filtres `status` ou `statuses[]`, `q`, `organization_id`, `date_from`, `date_to`, `council=a_decider`, `sort` ; chaque ligne porte `council_decision_status`), `GET /reservations/{reservation}` (avec `council_decision_status`), `POST /reservations/preview`, `POST /reservations`, `PATCH /reservations/{reservation}` (préavis de 15 jours levé pour un agent, et pour un externe si la date ne change pas ; voir docs/reservations.md §6.3), `/approve`, `/reject`, `/cancel`, `/assign`, `/confirm`, `/retry-assign`, `/confirm-passengers` |
| Conflits | `GET /reservations/{reservation}/conflicts`, `PATCH /reservations/{reservation}/conflicts/{conflict}/resolve` |
| Comptes rendus de trajet | `GET /trip-reports?reservation_ids[]=`, `GET /reservations/{reservation}/trip-report`, `PUT /reservations/{reservation}/trip-report` |
| Récurrences | `GET /recurrences`, `POST /recurrences/preview`, `POST /recurrences`, `GET /recurrences/{recurrence}`, `PATCH /recurrences/{recurrence}/cancel` |
| Planning | `GET /planning`, `GET /planning/export` (fichier), `POST /planning/exports` (asynchrone), `GET /planning/exports/{id}` |
| Décisions du Collège | `GET /council-decisions` (filtres `reservation_id`, `decision_status`), `GET /{councilDecision}`, `POST /council-decisions`, `PATCH /{councilDecision}`, `POST /{councilDecision}/generate-document` |
| Documents | `GET /documents`, `GET /documents/{document}`, `GET /documents/{document}/download`, `GET /documents/{document}/signed-download` (lien signé), `POST /documents/route-sheets/generate` |
| Feuilles de route (espace chauffeur) | `GET /route-sheets` (officielles, dernière version, paginées), `GET /route-sheets/driver?week=` (PDF de la semaine d'un chauffeur, à la demande) |
| Indisponibilités | `GET /unavailabilities`, `GET /{unavailability}`, `POST /unavailabilities`, `PATCH /{unavailability}`, `PATCH /{unavailability}/cancel`, `GET /{unavailability}/history`, `GET /{unavailability}/history/{history}` |
| Notifications | `GET /notifications`, `/summary`, `/unread-count`, `/health`, `PATCH /notifications/{id}/read`, `PATCH /notifications/read`, `/read-by-filter`, `/read-all` |
| Préférences de notification | `GET /notifications/preferences`, `PATCH /notifications/preferences` |
| Outils admin (module Tooling) | `GET /admin/tooling/inspector/{status,entries,entries/{id},flows,flows/{key},users/{user}}`, `DELETE /admin/tooling/inspector/entries`, `GET /admin/tooling/toolbox/{overview,permissions,users,api-catalog,health}` — permission `tooling.access` |

---

## Rôles et permissions

4 rôles globaux définis via Spatie Laravel Permission, 52 permissions granulaires au format `module.action` :

| Rôle | Description |
|---|---|
| `admin` | Accès complet à toutes les ressources |
| `agent_communal` | Gestion opérationnelle quotidienne |
| `user` | Création de demandes, consultation de ses réservations |
| `chauffeur` | Lecture planning et véhicules assignés |

Être **responsable d'une organisation** n'est pas un rôle : cela dépend de la relation avec chaque organisation (`responsable` ou `directeur`), voir [`docs/access-control.md`](docs/access-control.md).

---

## Architecture

```text
app/
  Console/Commands/          # 10 commandes artisan (backups, feuilles de route, notifications, santé)
  Enums/                     # Enums PHP typés (ReservationStatus, ReservationConflictType, etc.)
  Exceptions/
  Http/
    Controllers/Api/         # 24 contrôleurs REST
    Middleware/              # EnsureUserIsActive
    Requests/{Module}/       # Form Requests par module
    Resources/               # API Resources JSON
  Jobs/                      # Exports planning, feuilles de route, digests
  Models/                    # 27 modèles Eloquent
  Notifications/
  Policies/                  # 8 policies
  Services/                  # Logique métier par domaine (Reservations, Planning, Documents, Notifications…)
  Tooling/                   # Module isolé : Backend Inspector + Dev Toolbox (voir docs/tooling.md)
database/
  migrations/                # 43 migrations (+ 1 dans app/Tooling/database/migrations)
  seeders/                   # RolesAndPermissionsSeeder + jeux de test (Test*Seeder, RouteSheetDemoSeeder)
docs/                        # Documentation technique par module
routes/
  api.php                    # 117 routes API métier
  tooling.php                # 12 routes admin du module Tooling (chargées par ToolingServiceProvider)
  web.php                    # Route / (JSON descriptif), aucune page
  console.php                # Tâches planifiées
tests/
  Unit/                      # 12 fichiers
  Feature/                   # 36 fichiers (API, commandes, workflows, module Tooling)
```

---

## Fonctionnalités clés

**Réservations et conflits**
- Détection automatique de 12 types de conflits à la création (chevauchement, distance non calculée, quota dépassé, fenêtre impossible, etc.)
- Hard-block si chevauchement avec une réservation approuvée/confirmée (422)
- Passage en `EN_CONFLIT` si chevauchement avec une réservation en attente
- Résolution automatique des conflits de chevauchement à l'annulation de la réservation bloquante
- Résolution manuelle par un agent avec traçabilité

**Récurrences**
- Fréquences : hebdomadaire, bihebdomadaire, mensuel
- Prévisualisation avant création (`POST /recurrences/preview`)
- Réservées aux écoles communales et au personnel communal ; occurrences approuvées automatiquement
- Annulation en cascade

**Planning**
- Filtres multi-critères (statut, véhicule, chauffeur, organisation, période)
- Calcul de disponibilité chauffeur/véhicule en temps réel
- Export Excel synchrone et asynchrone (job queue)

**Documents**
- Génération PDF : feuilles de route (hebdomadaires/journalières), délibérations
- Versioning des documents (incrément automatique à la regénération)
- Téléchargement direct ou via token signé à durée limitée
- Journal d'accès (succès et échecs)

**Notifications**
- Canaux : in-app et email
- Priorités : `high` (queue `critical`), `normal`, `low`
- Digest configurable par utilisateur (horaire/quotidien)
- Quiet hours par utilisateur
- Anti-spam et déduplication (`dedupe_key`)
- Endpoint de santé : `GET /api/notifications/health`

**Backups**
- `php artisan backup:run --only={full|db|files}` — archive `.tar.gz`
- `php artisan backup:list` — liste les sauvegardes existantes
- Rétention configurable (`BACKUP_RETENTION_DAYS`)

**Infrastructure**
- Idempotence des requêtes critiques via `api_idempotency_keys`
- Journal de domaine immuable `domain_events` pour l'audit
- Sessions persistées en base de données
- Messages de l'API en français : `lang/fr` (généré par `laravel-lang/common`, noms de champs du projet dans `lang/fr/validation.php`) et `App\Exceptions\ApiErrorMessage` pour les erreurs HTTP
- Module Tooling (admin) : journal des requêtes API mutantes (Backend Inspector) et Dev Toolbox — voir [`docs/tooling.md`](docs/tooling.md)

---

## Commandes artisan utiles

```bash
# Migrations
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:fresh --seed

# Tests
docker compose exec app php artisan test

# Feuilles de route
docker compose exec app php artisan route-sheets:generate 2026-06-02 2026-06-06
docker compose exec app php artisan route-sheets:generate-daily 2026-06-06   # défaut : demain ; --force pour regénérer

# Backups
docker compose exec app php artisan backup:run --only=full   # full | db | files, --include-logs
docker compose exec app php artisan backup:list

# Santé
docker compose exec app php artisan app:health               # base, cache, queue, stockage, ORS, GeoNames, emails (SMTP)
docker compose exec app php artisan notifications:health

# Maintenance planifiée (lancées aussi par le scheduler)
docker compose exec app php artisan notifications:send-digests
docker compose exec app php artisan notifications:prune
docker compose exec app php artisan documents:prune-orphan-proofs --dry-run
docker compose exec app php artisan reservations:send-passenger-reminders --dry-run
docker compose exec -u www-data app php artisan reservations:close-expired --dry-run   # chaque nuit à 00:15

# Outils (module Tooling)
docker compose exec app php artisan dev:toolbox            # menu interactif (local/testing)
docker compose exec app php artisan tooling:inspector-prune  # purge du journal Inspector

# Caches
docker compose exec app php artisan config:clear && php artisan route:clear && php artisan cache:clear
```

---

## Configuration essentielle (.env)

| Variable | Description |
|---|---|
| `ORS_API_KEY` | Clé API OpenRouteService (géocodage + distances) |
| `ORS_BASE_URL` | URL de base ORS |
| `QUEUE_WORKER_QUEUES` | Queues du worker : `critical,default,low` |
| `SANCTUM_STATEFUL_DOMAINS` | Domaines frontend autorisés (SPA cookie) |
| `CORS_ALLOWED_ORIGINS` | Origines CORS autorisées |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` | Envoi des emails. Dev : `smtp`, `mailpit`, `1025` (Mailpit) ; Mailtrap ou un vrai serveur en remplaçant hôte, port et identifiants |
| `BACKUP_PATH` | Chemin de stockage des backups |
| `BACKUP_RETENTION_DAYS` | Durée de rétention des backups (défaut : 14) |
| `NOTIFICATIONS_OBS_MAX_FAILED_DELIVERIES_24H` | Seuil alerte santé notifications |
| `FRONTEND_URL` | URL de la SPA (liens envoyés par email) |
| `FRONTEND_EMAIL_VERIFICATION_PATH` | Chemin de la page de vérification d'email côté SPA (défaut : `/verify-email`) |
| `FRONTEND_PASSWORD_RESET_PATH` | Chemin de la page de réinitialisation du mot de passe côté SPA (défaut : `/reset-password`) |
| `BACKEND_INSPECTOR_ENABLED` | Active l'enregistrement du journal Inspector (défaut : `false`) |
| `BACKEND_INSPECTOR_RETENTION_DAYS` | Rétention du journal Inspector (défaut : 14) |
| `DEV_TOOLBOX_ENABLED` | Autorise `php artisan dev:toolbox` (local/testing uniquement) |

---

## Checklist production

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `SESSION_SECURE_COOKIE=true` (HTTPS uniquement)
- [ ] `SANCTUM_STATEFUL_DOMAINS` limité aux frontends réels
- [ ] `CORS_ALLOWED_ORIGINS` limité aux origines de confiance
- [ ] Provider email réel configuré avec retry
- [ ] Queue driver asynchrone (Redis recommandé)
- [ ] Workers supervisés (Supervisor ou équivalent)
- [ ] Scheduler configuré (`* * * * * php artisan schedule:run`)
- [ ] Rotation des secrets et gestion sécurisée des variables d'environnement
- [ ] Centralisation des logs et alertes sur erreurs critiques

---

## Documentation technique

| Document | Description |
|---|---|
| [`docs/modules.md`](docs/modules.md) | Vue d'ensemble des modules et matrice des permissions |
| [`docs/access-control.md`](docs/access-control.md) | Rôles, 52 permissions, policies, rate limiting |
| [`docs/reservations.md`](docs/reservations.md) | Statuts, règles métier, cycle de vie |
| [`docs/conflicts.md`](docs/conflicts.md) | 12 types de conflits, hard-block vs soft, résolution |
| [`docs/recurrences.md`](docs/recurrences.md) | Réservations récurrentes, fréquences, annulation cascade |
| [`docs/organizations.md`](docs/organizations.md) | Types, quotas, bannissement, scoring de confiance |
| [`docs/notifications.md`](docs/notifications.md) | Canaux, priorités, digest, quiet hours, santé système |
| [`docs/planning.md`](docs/planning.md) | Filtres, disponibilités, export |
| [`docs/documents.md`](docs/documents.md) | Stockage, accès, téléchargement sécurisé, tokens signés |
| [`docs/route_sheets.md`](docs/route_sheets.md) | Feuilles de route, génération, versioning |
| [`docs/deliberations.md`](docs/deliberations.md) | Délibérations du Collège communal |
| [`docs/operational-resources.md`](docs/operational-resources.md) | Chauffeurs, véhicules, indisponibilités |
| [`docs/logging.md`](docs/logging.md) | Stratégie de logs et audit par domaine |
| [`docs/testing.md`](docs/testing.md) | 470 tests, patterns, seeders |
| [`docs/backups.md`](docs/backups.md) | Stratégie et commandes de backup |
| [`docs/tooling.md`](docs/tooling.md) | Module Tooling : Backend Inspector, Dev Toolbox, API admin |
| [`docs/policies-rights-matrix.md`](docs/policies-rights-matrix.md) | Matrice détaillée des policies Laravel |
| [`oukilemonbus.dbml`](oukilemonbus.dbml) | Schéma complet de la base de données (DBML v7) |

---

## Licence

Projet distribué sous licence MIT.