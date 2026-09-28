# Module Tooling — Backend Inspector & Dev Toolbox

Outils d'administration et de débogage, **isolés du cœur applicatif**.

```text
app/Tooling/
├── ToolingServiceProvider.php     # point d'entrée unique (routes, middleware, migrations, commandes, scheduler)
├── database/migrations/           # table inspector_entries
├── Inspector/
│   ├── Console/PruneInspectorEntriesCommand.php
│   ├── Http/{Controllers,Middleware,Requests,Resources}/
│   ├── Models/InspectorEntry.php
│   └── Services/{InspectorRecorder,FlowRegistry,UserInspectorService}.php
└── Toolbox/
    ├── Console/DevToolboxCommand.php
    ├── Http/Controllers/ToolboxController.php
    └── Services/…
config/tooling/inspector.php       # activation, rétention, masquage, route_map, flows
config/tooling/toolbox.php         # sections de la toolbox, garde d'environnement
routes/tooling.php                 # API admin
```

**Séparation** : aucune classe du cœur (`app/Http`, `app/Services`, …) ne référence le module.
Retirer `ToolingServiceProvider` de `bootstrap/providers.php` désactive entièrement les routes,
le middleware, les commandes et la planification.
Le module dépend du cœur (modèle `User`, `SystemHealthService`), jamais l'inverse.

---

## Accès

Toutes les routes sont sous `/api/admin/tooling` et passent par :
`auth:sanctum` → `active` → `verified` → `permission:tooling.access`.

`tooling.access` est attribuée au rôle **admin** uniquement (`RolesAndPermissionsSeeder`).

---

## Backend Inspector

Journal persistant de chaque requête API **mutante** (`POST`, `PUT`, `PATCH`, `DELETE`), quel que soit
son statut HTTP (succès, 4xx, 5xx). Les lectures (`GET`) et les routes du module lui-même ne sont pas enregistrées.

| Champ | Contenu |
|---|---|
| `user_id`, `user_email` | Acteur (email copié : reste lisible si l'utilisateur est supprimé) |
| `method`, `path`, `route_name`, `route_params` | Requête |
| `flow_key` | Flow métier documenté (via `route_map`), `null` si non mappé |
| `http_status`, `outcome` | `success` (< 400) ou `error` |
| `duration_ms` | Durée de traitement |
| `request_payload` | Payload **expurgé** (voir ci-dessous), tronqué au-delà de 16 Ko |
| `response_summary` | `resource_id`, `resource_status`, `message`, `errors` de validation |
| `meta` | IP, user-agent, `Idempotency-Key` |

**Masquage** : toute clé contenant `password`, `token`, `secret`, `signature`, `api_key` ou `authorization`
(insensible à la casse, à tous les niveaux d'imbrication) est remplacée par `[masqué]`.
Les fichiers sont remplacés par `[fichier : nom, taille]`. Liste configurable dans `redacted_keys`.

**Robustesse** : un échec d'écriture est journalisé (`Log::warning`) et n'affecte jamais la réponse API.

**Activation et rétention**

| Variable | Défaut | Rôle |
|---|---|---|
| `BACKEND_INSPECTOR_ENABLED` | `false` | Active l'enregistrement (activable en production) |
| `BACKEND_INSPECTOR_RETENTION_DAYS` | `14` | Purge quotidienne à 03:30 (`tooling:inspector-prune`) |

La lecture du journal reste possible quand l'enregistrement est désactivé.

### Endpoints

| Méthode | Route | Description |
|---|---|---|
| GET | `/inspector/status` | Activation, rétention, nombre d'entrées, bornes de dates |
| GET | `/inspector/entries` | Liste paginée (plus récentes d'abord) |
| GET | `/inspector/entries/{id}` | Détail, avec la fiche complète du flow |
| DELETE | `/inspector/entries` | Purge manuelle ; `before` (date) optionnel → `{ data: { deleted } }` |
| GET | `/inspector/flows` | Catalogue des flows + `route_map` |
| GET | `/inspector/flows/{flowKey}` | Fiche d'un flow (404 si inconnu) |
| GET | `/inspector/users/{user}` | Rôles, permissions, capacités, organisation, stats d'entrées |

Filtres de `/inspector/entries` : `user_id`, `user_email` (partiel), `flow_key`, `route_name`, `method`,
`outcome` (`success`|`error`), `http_status`, `from`, `to` (dates), `per_page` (1–100, défaut 25).

Une fiche de flow décrit : `title`, `category`, `goal`, `route`, `api_controller`, `request`, `service`,
`related_services`, `tables`, `effects`.

---

## Dev Toolbox

### API (lecture seule, tous environnements)

| Méthode | Route | Description |
|---|---|---|
| GET | `/toolbox/overview` | Statistiques base de données, règles métier actives, architecture |
| GET | `/toolbox/permissions` | Matrice rôles × permissions |
| GET | `/toolbox/users` | Utilisateurs groupés par rôle |
| GET | `/toolbox/api-catalog` | Routes API internes (hors tooling) + APIs externes |
| GET | `/toolbox/health` | Santé système (`SystemHealthService` : DB, cache, queue, stockage, ORS, GeoNames…) — throttle 10/min |

Aucune route n'exécute d'action.

### CLI `php artisan dev:toolbox`

Menu interactif : préparation de présentation, règles métier, tests, commandes personnalisées,
`schedule:list`, inspection d'un utilisateur, génération d'un token Sanctum temporaire,
réparation des permissions, nettoyage des caches, validation des emails de test, **reset complet de la base**.

Bloqué sauf si `DEV_TOOLBOX_ENABLED=true` **et** `APP_ENV` ∈ `env_guard` (`local`, `testing`).

---

## Commandes

```bash
php artisan dev:toolbox                       # menu interactif (local/testing)
php artisan tooling:inspector-prune           # purge selon la rétention configurée
php artisan tooling:inspector-prune --days=3  # rétention ponctuelle
```

## Tests

`tests/Feature/Tooling/InspectorApiTest.php` et `ToolboxApiTest.php` : contrôle d'accès par rôle,
enregistrement et masquage, routes ignorées, désactivation, filtres, purge, garde de la CLI.
