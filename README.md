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
- permissions Spatie et acces Sanctum

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

- permissions: `reservations.view`, `reservations.create`, `reservations.update`, `reservations.validate`, `reservations.cancel`
- routes: `api.reservations.index`, `api.reservations.store`, `api.reservations.show`, etc.
- service: `App\Services\Reservations\ReservationService`

Les regles purement metier restent dans les services ou actions. Les FormRequest valident l'entree HTTP et les permissions. Les contraintes SQL doivent rester reservees aux invariants de donnees qui ne dependent pas du contexte metier.

## Etat actuel du projet

Ce qui est deja solide:
- le schema metier
- les relations Eloquent
- les contraintes SQL importantes
- les enums PHP et casts Eloquent
- l'authentification utilisateur
- les roles et permissions Spatie
- le socle Sanctum pour une SPA React separee
- la feature Locations
- la strategie de tests base de donnees et API

Ce qui reste a construire:
- les autres controllers metier
- les autres services / actions / cas d'usage
- les autres FormRequest et Resources
- generation metier de documents et workflows complets
- configuration de production: CORS, domaines Sanctum, cookies secure, queue, mail

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

Projet distribue sous licence MIT.
