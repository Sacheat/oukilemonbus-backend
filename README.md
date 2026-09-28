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
| Emails | Mailtrap (dev) / SMTP configurable |
| Queue | Laravel Queue — 3 niveaux : `critical`, `default`, `low` |
| Tests | PHPUnit 12 — 439 tests, 1924 assertions |
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
| `mariadb` | Base de données | `3307` |
| `adminer` | Interface base de données | `8081` |

---

## Prérequis

- Docker + Docker Compose

Le backend n'a plus de dépendance Node : il n'expose aucune page (la route `/` renvoie un JSON descriptif).

---

## Installation

```bash
# 1. Copier la configuration
cp .env.example .env

# 2. Ajouter la variable de queue manquante dans .env
echo 'QUEUE_WORKER_QUEUES=critical,default,low' >> .env

# 3. Configurer les variables essentielles dans .env :
#    - ORS_API_KEY (OpenRouteService)
#    - MAIL_* (provider email)
#    - APP_KEY (généré à l'étape suivante)

# 4. Démarrer les conteneurs
docker compose up -d

# 5. Installer les dépendances PHP
docker compose exec app composer install

# 6. Générer la clé applicative
docker compose exec app php artisan key:generate

# 7. Migrations + seeders
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=RolesAndPermissionsSeeder
```

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

Tous les endpoints sont préfixés `/api/` et protégés par Sanctum sauf mention contraire.

| Module | Endpoints principaux |
|---|---|
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` |
| Vérification email | `GET /email/verify/{id}/{hash}`, `POST /email/verify/resend` |
| Utilisateurs | `GET/POST /users`, `PATCH /users/{id}`, `/deactivate`, `/password`, `/status` |
| Lieux | `GET /locations/suggest`, `GET/POST /locations`, `PATCH /locations/{id}` |
| Communes | `GET/POST /municipalities`, `PATCH /{id}`, `/sync-localities`, `/localities` |
| Organisations | `GET/POST /organizations`, `PATCH /{id}`, `/ban`, `/unban` |
| Chauffeurs | `GET/POST /drivers`, `PATCH /{id}`, `/activate`, `/deactivate` |
| Véhicules | `GET/POST /vehicles`, `PATCH /{id}`, `/activate`, `/deactivate` |
| Demandes inscription org | `GET/POST /org-registration-requests`, `/approve`, `/reject`, `/proof` |
| Demandes adhésion org | `GET/POST /user-org-requests`, `/approve`, `/reject`, `/proof` |
| Réservations | `GET/POST /reservations`, `PATCH /{id}`, `/approve`, `/confirm`, `/refuse`, `/cancel`, `/assign-driver`, `/assign-vehicle`, `/retry-auto-assign`, `/confirm-passengers` |
| Conflits | `GET /reservations/{id}/conflicts`, `PATCH /{conflictId}/resolve` |
| Récurrences | `GET/POST /recurrences`, `GET /recurrences/preview`, `PATCH /{id}/cancel` |
| Planning | `GET /planning`, `GET /planning/availability`, `GET /planning/export`, `POST /planning/export/request` |
| Décisions Collège | `GET/POST /council-decisions`, `PATCH /{id}`, `/generate-document` |
| Documents | `GET /documents`, `GET /documents/{id}`, `GET /documents/{id}/download`, `GET /documents/{id}/signed-download` |
| Feuilles de route | `POST /route-sheets/generate`, `POST /route-sheets/generate-daily` |
| Indisponibilités | `GET/POST /unavailabilities`, `PATCH /{id}`, `DELETE /{id}`, `GET /{id}/history` |
| Notifications | `GET /notifications`, `/summary`, `/unread-count`, `/health`, `PATCH /{id}/read`, `/read-all`, `/read-by-filter` |
| Préférences notif. | `GET/PATCH /notifications/preferences` |
| Outils admin (module Tooling) | `GET /admin/tooling/inspector/*`, `GET /admin/tooling/toolbox/*` — permission `tooling.access` |

---

## Rôles et permissions

5 rôles définis via Spatie Laravel Permission, 51 permissions granulaires au format `module.action` :

| Rôle | Description |
|---|---|
| `admin` | Accès complet à toutes les ressources |
| `agent_communal` | Gestion opérationnelle quotidienne |
| `responsable_organisation` | Accès restreint à son organisation |
| `user` | Création de demandes, consultation de ses réservations |
| `chauffeur` | Lecture planning et véhicules assignés |

---

## Architecture

```text
app/
  Console/Commands/          # Commandes artisan (backups, feuilles de route, santé notif.)
  Enums/                     # Enums PHP typés (ReservationStatus, ConflictType, etc.)
  Http/
    Controllers/Api/         # 22 contrôleurs REST
    Middleware/              # EnsureUserIsActive
    Requests/{Module}/       # FormRequests par module
    Resources/               # Resources API JSON
  Models/                    # 20+ modèles Eloquent
  Policies/                  # Policies par ressource
  Tooling/                   # Module isolé : Backend Inspector + Dev Toolbox (voir docs/tooling.md)
  Services/
    Auth/
    Backups/
    CouncilDecisions/
    Documents/
    Drivers/
    Locations/
    Logging/                 # DomainLogger — journal d'audit structuré
    Municipalities/
    Notifications/
    OrgRegistrationRequests/
    Organizations/
    Planning/
    Recurrences/
    Reservations/            # ReservationService, ConflictService, OccupationService...
    SystemHealthService.php
    Unavailabilities/
    UserOrgRequests/
    Vehicles/
database/
  migrations/                # 42 migrations
  seeders/
    RolesAndPermissionsSeeder.php
    TestUsersSeeder.php
    TestOrganizationsSeeder.php
    TestLocationsSeeder.php
    TestFleetSeeder.php
    TestMunicipalitySeeder.php
docs/                        # Documentation technique par module
routes/
  api.php                    # 126 routes API nommées
  web.php                    # Route / (JSON descriptif) — aucune page
  tooling.php                # API admin du module Tooling (chargée par ToolingServiceProvider)
  console.php                # Tâches planifiées
tests/
  Unit/                      # 12 fichiers — services, générateurs, contextes
  Feature/                   # 27 fichiers — API, commandes, workflows complets
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
- Prévisualisation avant création (`GET /recurrences/preview`)
- Auto-confirmation des occurrences pour les écoles communales
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
- Priorités : `critical`, `high`, `normal`, `low`
- Digest configurable par utilisateur (horaire/quotidien)
- Quiet hours par utilisateur
- Déduplication par `dedupe_key`
- Endpoint de santé : `GET /api/notifications/health`

**Backups**
- `php artisan backup:run {files|db|full}` — archive `.tar.gz`
- `php artisan backup:list` — liste les sauvegardes existantes
- Rétention configurable (`BACKUP_RETENTION_DAYS`)

**Infrastructure**
- Idempotence des requêtes critiques via `api_idempotency_keys`
- Journal de domaine immuable `domain_events` pour l'audit
- Sessions persistées en base de données
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
docker compose exec app php artisan route-sheets:generate --start=2026-06-02 --end=2026-06-06
docker compose exec app php artisan route-sheets:generate-daily --date=2026-06-06

# Backups
docker compose exec app php artisan backup:run full
docker compose exec app php artisan backup:list

# Santé notifications
docker compose exec app php artisan notifications:health

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
| `MAIL_MAILER` | Provider email (`log`, `smtp`, `mailtrap`) |
| `BACKUP_PATH` | Chemin de stockage des backups |
| `BACKUP_RETENTION_DAYS` | Durée de rétention des backups (défaut : 14) |
| `NOTIFICATIONS_OBS_MAX_FAILED_DELIVERIES_24H` | Seuil alerte santé notifications |
| `FRONTEND_URL` | URL de la SPA (liens envoyés par email) |
| `FRONTEND_EMAIL_VERIFICATION_PATH` | Chemin de la page de vérification d'email côté SPA (défaut : `/verify-email`) |
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
| [`docs/access-control.md`](docs/access-control.md) | Rôles, 51 permissions, policies, rate limiting |
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
| [`docs/testing.md`](docs/testing.md) | 439 tests, patterns, seeders |
| [`docs/backups.md`](docs/backups.md) | Stratégie et commandes de backup |
| [`docs/tooling.md`](docs/tooling.md) | Module Tooling : Backend Inspector, Dev Toolbox, API admin |
| [`docs/policies-rights-matrix.md`](docs/policies-rights-matrix.md) | Matrice détaillée des policies Laravel |
| [`oukilemonbus.dbml`](oukilemonbus.dbml) | Schéma complet de la base de données (DBML v7) |

---

## Licence

Projet distribué sous licence MIT.