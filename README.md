# Bus Communal Backend

Backend Laravel pour la gestion des reservations du bus communal.

Le projet est actuellement centre sur le socle metier et la base de donnees:
- modelisation du domaine
- migrations MySQL
- relations Eloquent
- contraintes SQL
- suite de tests base de donnees

La couche applicative HTTP est encore volontairement minimale.

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
```

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
  Models/
database/
  migrations/
  factories/
  seeders/
tests/
  Unit/
  Feature/
```

## Etat actuel du projet

Ce qui est deja solide:
- le schema metier
- les relations Eloquent
- les contraintes SQL importantes
- la strategie de tests base de donnees

Ce qui reste a construire:
- vraie API HTTP
- controllers metier
- services / actions / cas d'usage
- validation applicative
- authentification / autorisation
- generation metier de documents et workflows complets

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
- La cible actuelle est d'avoir un socle base de donnees fiable avant de monter la couche applicative.

## Licence

Projet distribue sous licence MIT.
