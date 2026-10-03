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
├── Support/{PayloadSanitizer,LocalDateBounds}.php   # masquage partagé, dates en heure de Bruxelles
└── Toolbox/
    ├── Console/DevToolboxCommand.php
    ├── Http/Controllers/{ToolboxController,FailedJobsController}.php
    └── Services/…                 # dont FailedJobsService
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
| `flow_key` | Flow métier documenté (via `route_map`) ; toute route mutante de l'API en a un (test `InspectorRouteMapTest`) |
| `http_status`, `outcome` | `success` (< 400) ou `error` |
| `duration_ms` | Durée de traitement |
| `request_payload` | Payload **expurgé** (voir ci-dessous), tronqué au-delà de 16 Ko |
| `response_summary` | `resource_id`, `resource_status`, `message`, `errors` de validation |
| `meta` | IP, user-agent, `Idempotency-Key` |

**Masquage** : toute clé contenant `password`, `token`, `secret`, `signature`, `api_key` ou `authorization`
(insensible à la casse, à tous les niveaux d'imbrication) est remplacée par `[masqué]`.
Les fichiers sont remplacés par `[fichier : nom, taille]`. Liste configurable dans `redacted_keys`.
Même masquage pour le détail des jobs en échec (`PayloadSanitizer`).

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
| DELETE | `/inspector/entries` | Purge manuelle ; `before` optionnel (voir « Dates ») → `{ data: { deleted } }` |
| GET | `/inspector/flows` | Catalogue des flows + `route_map` |
| GET | `/inspector/flows/{flowKey}` | Fiche d'un flow (404 si inconnu) |
| GET | `/inspector/users/{user}` | Rôles, permissions, capacités, organisation, stats d'entrées |

Filtres de `/inspector/entries` : `user_id`, `user_email` (partiel), `flow_key`, `route_name`, `method`,
`outcome` (`success`|`error`), `http_status`, `from`, `to` (voir « Dates »), `per_page` (1–100, défaut 25).

**Dates** (`from`, `to`, `before`, et `before` des jobs en échec) : une date seule `AAAA-MM-JJ` désigne un jour
**en heure de Bruxelles** (`tooling.inspector.local_timezone`), convertie en UTC avant la comparaison :

- `from=2026-10-03` : à partir du 3 octobre 00:00 à Bruxelles (2 octobre 22:00 UTC en heure d'été) ;
- `to=2026-10-03` : **toute** la journée du 3 octobre incluse (jusqu'au 4 octobre 00:00 à Bruxelles, exclu) ;
- `before=2026-10-03` : strictement avant le 3 octobre 00:00 à Bruxelles.

Un horodatage complet (`2026-10-03 14:00:00`, `2026-10-03T14:00:00Z`) est comparé tel quel aux colonnes,
qui sont en UTC.

Les flows couvrent toutes les routes mutantes de l'API (sauf `ignored_routes`). Une nouvelle route mutante sans
entrée dans `route_map` fait échouer `InspectorRouteMapTest`.

Une fiche de flow décrit : `title`, `category`, `goal`, `route`, `api_controller`, `request`, `service`,
`related_services`, `tables`, `effects`.

---

## Dev Toolbox

### API (tous environnements)

| Méthode | Route | Description |
|---|---|---|
| GET | `/toolbox/overview` | Statistiques base de données, règles métier actives, architecture |
| GET | `/toolbox/permissions` | Matrice rôles × permissions |
| GET | `/toolbox/users` | Utilisateurs groupés par rôle |
| GET | `/toolbox/api-catalog` | Routes API internes (hors tooling) + APIs externes |
| GET | `/toolbox/health` | Santé système (`SystemHealthService` : DB, cache, queue, stockage, ORS, GeoNames…) — throttle 10/min |

Ces routes sont en lecture seule. `architecture` compte les fichiers PHP de chaque dossier **récursivement**
(sous-dossiers compris : `app/Services/Reservations/…`).

### Jobs en échec de la file d'attente

`/toolbox/health` signale `queue: warn` quand `failed_jobs` n'est pas vide ; ces routes permettent de les traiter.
Ce sont les seules routes du module qui agissent.

| Méthode | Route | Description |
|---|---|---|
| GET | `/queue/failed-jobs` | Liste paginée, plus récents d'abord. `queue` (filtre), `per_page` (1–100, défaut 15) |
| GET | `/queue/failed-jobs/{uuid}` | Détail : exception complète (tronquée à 20 000 caractères) et payload expurgé ; 404 si inconnu |
| POST | `/queue/failed-jobs/{uuid}/retry` | Remet le job en file (`queue:retry`) → 202 `{ data: { retried } }` |
| POST | `/queue/failed-jobs/retry` | Remet tous les jobs en file → 202 `{ data: { retried } }` |
| DELETE | `/queue/failed-jobs/{uuid}` | Supprime le job (`queue:forget`) → `{ data: { deleted } }` |
| DELETE | `/queue/failed-jobs` | Supprime tout (`queue:flush`), ou seulement avant `before` (voir « Dates ») → `{ data: { deleted } }` |

Élément de liste : `id`, `uuid`, `queue`, `connection`, `job` (`displayName` du payload, ex.
`App\Notifications\Auth\QueuedResetPassword`), `exception_summary` (première ligne, 300 caractères au plus),
`failed_at` (ISO 8601 UTC). Le détail ajoute `exception` et `payload`.

**Le payload n'est jamais renvoyé brut** : la commande sérialisée (`data.command`, qui peut contenir un jeton de
réinitialisation ou des données personnelles) devient `[objet sérialisé masqué]`, et les clés sensibles du reste sont
masquées comme dans le journal. Une relance remet le job sur sa connexion et sa queue d'origine avec ses tentatives à
zéro ; un job qui échoue encore revient dans la liste.

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
enregistrement et masquage, routes ignorées, désactivation, filtres (dates en heure de Bruxelles, été et hiver), purge,
comptage récursif de l'architecture, garde de la CLI. `FailedJobsApiTest.php` : liste, détail masqué, relance,
suppression, accès. `InspectorRouteMapTest.php` : chaque route mutante de l'API a un flow connu.
