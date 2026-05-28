# Bus Communal Backend

Backend Laravel pour la gestion des reservations du bus communal.

Le projet est actuellement centre sur le socle metier, la base de donnees et les premieres features API:
- modelisation du domaine
- migrations MySQL
- relations Eloquent
- contraintes SQL
- authentification et gestion utilisateurs
- gestion des lieux avec geocodage OpenRouteService
- autorisations Spatie
- authentification SPA avec Sanctum
- policies Laravel sur les ressources metier principales
- services metier par module
- suite de tests base de donnees et API

## Domaine couvert

Le schema actuel couvre notamment:
- utilisateurs et profils chauffeurs
- organisations et rattachements utilisateur/organisation
- reservations
- lieux et recurrences
- decisions du college
- documents generes
- notifications et traces d'envoi
- indisponibilites
- historiques de statut et d'affectation
- demandes d'inscription organisation et demandes d'acces organisation

## Stack

- PHP 8.4
- Laravel 13
- MySQL
- PHPUnit 12
- Laravel Sanctum
- Spatie Laravel Permission
- Vite

## Prerequis

- PHP 8.4
- Composer
- MySQL
- Node.js / npm
- Un environnement capable d'executer Laravel, par exemple Laragon

## Installation locale

Depuis la racine du projet:

```powershell
cd C:\laragon\www\bus-communal-backend
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Configurer ensuite la base principale dans `.env`, puis executer:

```powershell
php artisan migrate
php artisan db:seed
```

Pour le geocodage des lieux, renseigner aussi `ORS_API_KEY` dans `.env`.

Pour compiler les assets:

```powershell
npm run build
```

Pour le mode developpement:

```powershell
composer run dev
```

## Base de donnees

Le projet utilise deux bases distinctes:

- `bus_communal` pour le developpement local
- `bus_communal_test` pour les tests

La base de test est declaree dans `phpunit.xml`.

Important:
- ne pas lancer les tests contre la base principale
- la suite de tests reconstruit la base de test

## Tests

La suite actuelle est fortement orientee base de donnees:
- structure des migrations
- contraintes SQL
- relations Eloquent
- casts
- effets de suppression
- unicite
- coherence de persistance
- endpoints Auth/User
- endpoints Locations
- endpoints Organizations
- endpoints Reservations
- endpoints Council Decisions
- endpoints Unavailabilities
- endpoints Org Registration Requests
- endpoints User Org Requests
- permissions Spatie et acces Sanctum

Execution recommandee (projet dockerise):

```bash
docker compose up -d
docker compose exec app php artisan test
```

Lancer tous les tests:

```powershell
php artisan test
```

Si `php` n'est pas disponible dans le terminal:

```powershell
C:\laragon\bin\php\php-8.4.19-nts-Win32-vs17-x64\php.exe artisan test
```

Lancer un fichier precis:

```powershell
php artisan test tests/Feature/ReservationBusinessRulesTest.php
php artisan test tests/Feature/PlanningAndHistoryDatabaseRulesTest.php
php artisan test tests/Feature/AdditionalDatabaseIntegrityTest.php
php artisan test tests/Unit/ModelConfigurationTest.php
```

Format plus lisible:

```powershell
php artisan test --testdox
```

## Structure utile

```text
app/
  Enums/
  Http/
    Controllers/Api/
    Requests/{Module}/
    Resources/
  Models/
  Services/{Module}/
database/
  migrations/
  factories/
  seeders/
tests/
  Unit/
  Feature/
```

## Convention API

Pour chaque nouveau module, garder la meme structure:

- `app/Http/Requests/{Module}/Store...Request.php`
- `app/Http/Requests/{Module}/Update...Request.php`
- `app/Http/Controllers/Api/...Controller.php`
- `app/Http/Resources/...Resource.php`
- `app/Services/{Module}/...Service.php`
- routes nommees dans `routes/api.php`
- permissions Spatie au format `module.action`
- tests feature couvrant `401`, `403`, validation, creation, lecture et modification

Exemple pour un module `reservations`:

- permissions: `reservations.view`, `reservations.create`, `reservations.update`, `reservations.approve`, `reservations.cancel`
- routes: `api.reservations.index`, `api.reservations.store`, `api.reservations.show`, etc.
- service: `App\Services\Reservations\ReservationService`

Les regles purement metier restent dans les services ou actions. Les FormRequest valident l'entree HTTP et les permissions. Les contraintes SQL doivent rester reservees aux invariants de donnees qui ne dependent pas du contexte metier.

## Decisions d'architecture

- Les services metier ne doivent pas repondre directement en HTTP (`abort`, `response`, etc.). Ils doivent lever des exceptions metier ou d'autorisation.
- Les ecritures metier composees (mise a jour + historique + logs) doivent etre executees dans une transaction SQL.
- Les FormRequest restent la source de verite de validation d'entree HTTP et doivent rester alignes avec les champs reellement traites par les services.
- Les policies centralisent l'autorisation contextuelle (role + perimetre organisationnel + statut metier).

## Etat actuel du projet

Ce qui est deja solide:
- le schema metier
- les relations Eloquent
- les contraintes SQL importantes
- les enums PHP et casts Eloquent
- l'authentification utilisateur
- les roles et permissions Spatie
- le socle Sanctum pour une SPA React separee
- les features Auth / Users
- les features Locations / Municipalities
- les features Organizations / OrgRegistrationRequests / UserOrgRequests
- la feature Reservations
- la feature Council Decisions
- les features Unavailabilities et historiques
- la strategie de tests base de donnees et API

Ce qui reste a construire:
- generation metier de documents et workflows complets
- configuration de production: CORS, domaines Sanctum, cookies secure, queue, mail
- notifications metier applicatives
- synchronisation continue de la documentation technique (`README`, `DBML`, matrices de droits)

## Limites connues

- La disponibilite chauffeur/vehicule et certains controles de chevauchement utilisent encore des parcours en memoire sur des collections; c'est acceptable au volume actuel mais a optimiser pour des charges plus importantes.
- La suite de tests est orientee forte valeur metier/API, mais ne couvre pas encore tous les cas de performance et de concurrence.
- Le pipeline de generation documentaire est en progression, avec des workflows encore partiellement manuels selon les cas.

## Hardening production

Checklist minimale avant mise en production:

- Configurer explicitement `SANCTUM_STATEFUL_DOMAINS` pour les domaines frontend reels.
- Activer les cookies securises (`SESSION_SECURE_COOKIE=true`) et verifier `SameSite` selon le mode de deploiement.
- Restreindre `CORS_ALLOWED_ORIGINS` aux seules origines de confiance.
- Basculer la queue en asynchrone (ex: Redis) et superviser les workers.
- Configurer un provider mail reel + strategie de retry.
- Mettre en place la centralisation des logs et alertes sur erreurs critiques.
- Verifier la rotation des secrets et la gestion des variables d'environnement.

## Modules API disponibles

- `auth`
- `users`
- `locations`
- `municipalities` et `municipality-localities`
- `vehicles`
- `drivers`
- `organizations`
- `org-registration-requests`
- `user-org-requests`
- `reservations`
- `council-decisions`
- `unavailabilities`
- `unavailabilities history`

## Commandes utiles

```powershell
php artisan migrate
php artisan migrate:fresh
php artisan db:seed
php artisan test
composer run dev
npm run build
```

## Notes

- Les anciens fichiers `tests/Unit/ExampleTest.php` et `tests/Feature/ExampleTest.php` sont des placeholders temporaires conserves pour des raisons de workspace local.
- Les routes API sont definies dans `routes/api.php`.
- Le frontend React doit appeler `GET /sanctum/csrf-cookie` avant les requetes d'authentification par cookie Sanctum.

## Licence

Projet distribue sous licence MIT.rnrn<!-- DOCUMENTATION_TECHNIQUE_START -->

## Documentation technique

Le projet contient plusieurs fichiers de documentation technique dans le dossier `docs/`.

Ces documents servent à expliquer l'architecture, les modules principaux, les règles métier, les accès, les tests et l'intégration future avec le frontend React.

### Vue d'ensemble

| Document | Description |
|---|---|
| [`docs/modules.md`](docs/modules.md) | Vue d'ensemble des modules backend de l'application. |
| [`docs/access-control.md`](docs/access-control.md) | Rôles, permissions, règles d'accès et sécurité des données. |
| [`docs/testing.md`](docs/testing.md) | Stratégie de tests automatisés et commandes utiles. |
| [`docs/frontend-integration.md`](docs/frontend-integration.md) | Guide d'intégration pour le futur frontend React. |

### Modules métier

| Document | Description |
|---|---|
| [`docs/reservations.md`](docs/reservations.md) | Fonctionnement du module réservations, statuts, règles métier et conflits. |
| [`docs/planning.md`](docs/planning.md) | API planning, filtres, export, disponibilités et affichage côté front. |
| [`docs/operational-resources.md`](docs/operational-resources.md) | Chauffeurs, véhicules, lieux, indisponibilités, conflits et affectations. |
| [`docs/documents.md`](docs/documents.md) | Gestion documentaire, stockage, accès, téléchargement et sécurité. |
| [`docs/route_sheets.md`](docs/route_sheets.md) | Feuilles de route journalières par véhicule, génération, versioning et archivage. |
| [`docs/deliberations.md`](docs/deliberations.md) | Délibérations liées aux décisions administratives du Collège. |
| [`docs/logging.md`](docs/logging.md) | Stratégie de logs, canaux, données autorisées/interdites et bonnes pratiques. |

### Modules à documenter plus tard

Certains modules devront être documentés lorsqu'ils seront stabilisés :

- notifications ;
- backups ;
- éventuelles évolutions du frontend ;
- optimisations avancées de tournées.

### Commandes de test utiles

Lancer tous les tests :

```bash
php artisan test

Tester uniquement le module planning :

php artisan test --filter=PlanningApiTest

Tester le calcul d'occupation du bus :

php artisan test --filter=ReservationOccupationServiceTest

Tester les logs :

php artisan test --filter=DomainLoggerTest
<!-- DOCUMENTATION_TECHNIQUE_END -->rn
