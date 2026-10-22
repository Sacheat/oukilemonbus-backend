# Stratégie de tests automatisés

**État actuel (juin 2026) : 422 tests — 1814 assertions — 0 échec**

---

## Principes

- **Feature tests** : flux HTTP complet → base de données. Chaque test simule une vraie requête API, vérifie la réponse JSON et l'état en base.
- **Unit tests** : logique isolée. Services, générateurs, context builders, calculs métier.
- **Isolation** : `DatabaseTransactions` (rollback après chaque test) ou `RefreshDatabase` selon le besoin.
- **Seeders de test** : données communes injectées via `TestUsersSeeder`, `TestOrganizationsSeeder`, `TestLocationsSeeder`, `TestFleetSeeder`, `TestMunicipalitySeeder`.

---

## Lancer les tests

```bash
# Suite complète (via Docker)
docker compose exec app php artisan test

# Un fichier précis
docker compose exec app php artisan test tests/Feature/ReservationApiTest.php

# Filtre par nom de test
docker compose exec app php artisan test --filter=test_agent_can_approve_reservation

# Mode verbose (testdox)
docker compose exec app php artisan test --testdox
```

---

## Fichiers de tests

### Unit (12 fichiers)

| Fichier | Ce qu'il teste |
|---|---|
| `DomainEnumsTest` | Comportements des enums PHP (labels, valeurs, helpers) |
| `ModelConfigurationTest` | Fillable arrays, casts Eloquent, relations déclarées |
| `MigrationStructureTest` | Présence et structure des fichiers de migration |
| `DomainLoggerTest` | Service DomainLogger — canaux, format, méthodes |
| `ReservationOccupationServiceTest` | Calcul de la fenêtre d'occupation (départ, retour + buffer) |
| `DeliberationContextBuilderTest` | Construction du contexte de délibération |
| `DeliberationDocumentServiceTest` | Génération et versioning des délibérations |
| `DeliberationGeneratorTest` | Rendu HTML et PDF, nom de fichier par défaut |
| `RouteSheetContextBuilderTest` | Construction du contexte feuille de route (période, journée) |
| `RouteSheetDocumentServiceTest` | Génération et versioning des feuilles de route |
| `RouteSheetGeneratorTest` | Rendu HTML et PDF, nom de fichier par défaut |
| `DocumentReservationRelationTest` | Relation pivot document ↔ réservations (ordre `position`) |

### Feature (27 fichiers)

| Fichier | Ce qu'il teste |
|---|---|
| `AuthUserApiTest` | Inscription, connexion, déconnexion, profil, rate limiting |
| `LocationApiTest` | Création avec géocodage ORS, mise à jour, suggestion |
| `VehicleApiTest` | CRUD, plaque dupliquée, activation/désactivation |
| `DriverApiTest` | CRUD, user dupliqué, activation/désactivation |
| `OrganizationApiTest` | CRUD, types, protection compteur quota |
| `UnavailabilityApiTest` | CRUD, validation partielle, restriction chauffeur |
| `OrgRegistrationRequestApiTest` | CRUD, scoring, pièce jointe, approbation |
| `UserOrgRequestApiTest` | CRUD, scoring, approbation, rattachement org |
| `MunicipalitySettingsApiTest` | CRUD, sync GeoNames commune/province/pays, scoring dynamique |
| `ReservationApiTest` | CRUD complet, transitions de statut, conflits |
| `ReservationBusinessRulesTest` | Règles autorisées au niveau DB (retour après 17h, distance > 50km) |
| `ReservationConflictResolutionTest` | Hard-block, soft conflict, résolution auto, résolution manuelle |
| `RecurrenceApiTest` | Création hebdo/bihebdo/mensuel, prévisualisation, auto-confirm école |
| `PlanningApiTest` | Filtres, disponibilités, export Excel, export asynchrone |
| `CouncilDecisionApiTest` | CRUD, visibilité par organisation, génération délibération |
| `CouncilDecisionGenerateDocumentApiTest` | Génération du PDF délibération, statut requis |
| `DocumentApiTest` | Listing, show (sans file_path), download, signed link |
| `RouteSheetDocumentApiTest` | Génération API hebdo et journalière, permissions |
| `NotificationApiTest` | CRUD, préférences, unread count, bulk mark-read, digest queue, health |
| `RequestNotificationsTest` | Envoi de notifications sur approbation/refus de demandes |
| `UnavailabilityHistoryApiTest` | Historique sur update/cancel, change_reason, accès cross |
| `BackupCommandTest` | Commandes backup files/db/full, list, type invalide |
| `GenerateRouteSheetCommandTest` | Commandes generate, generate-daily, --force, date invalide |
| `NotificationsHealthCommandTest` | Commande health : statut ok/alert selon seuils |
| `AdditionalDatabaseIntegrityTest` | Contraintes DB : version, file_size, attempt_id unique, confidence_note |
| `ModelDatabaseIntegrationTest` | Casts, relations, persistance notifications |
| `PlanningAndHistoryDatabaseRulesTest` | Contraintes DB : unicité chauffeur/plaque, décision collège, cascades |

---

## Patterns courants dans les tests

### Authentification

```php
Sanctum::actingAs($agent);
```

### Faking ORS

Les tests de réservation configurent `base_url` à `https://ors.test` et simulent tous les chemins. Les URLs réelles
(`api.heigit.org/pelias/v1/…` pour le géocodage, `api.heigit.org/openrouteservice/v2/directions/…` pour les
itinéraires, clé dans l'en-tête `Authorization`) sont vérifiées par `OrsEndpointsTest`.

```php
Http::fake([
    'https://ors.test/*' => Http::response([
        'routes' => [['summary' => ['distance' => 12000, 'duration' => 1800]]],
    ]),
]);
```

### Assertions DB

```php
$this->assertDatabaseHas('reservations', ['id' => $id, 'status' => 'en_conflit']);
$this->assertDatabaseMissing('reservation_conflicts', ['reservation_id' => $id, 'is_resolved' => false]);
```

### Injection directe d'un conflit pour tester la résolution sélective

```php
ReservationConflict::create([
    'reservation_id' => $reservationId,
    'type'          => ReservationConflictType::DISTANCE_NOT_CALCULATED->value,
    'message'       => 'Distance non calculee.',
    'is_resolved'   => false,
]);
```

---

## Données de test (seeders)

| Seeder | Ce qu'il crée |
|---|---|
| `RolesAndPermissionsSeeder` | 5 rôles, 50 permissions |
| `TestUsersSeeder` | admin, agent, responsable, user, chauffeur (emails prévisibles) |
| `TestOrganizationsSeeder` | Organisations de types variés avec quotas |
| `TestLocationsSeeder` | Lieux avec `place_id` stables (`seed-dinant-pickup`, etc.) |
| `TestFleetSeeder` | Véhicules et chauffeurs assignables |
| `TestMunicipalitySeeder` | Commune Dinant active avec localités |
| `DriverJourneyDemoSeeder` | Démo « Ma journée » (à lancer à la main, `db:seed --class=DriverJourneyDemoSeeder`) : 3 trajets confirmés aujourd'hui pour `chauffeur.test`, à partir de l'heure actuelle ; relancer remet la journée à zéro |
| `DemoDataSeeder` | Données de démonstration pour parcourir toute l'application à la main (à lancer en journée, `docker compose exec -u www-data app php artisan db:seed --class=DemoDataSeeder`) : environ 300 réservations « [Démo] », dates relatives à aujourd'hui. Couverture (un cas par état, nommé selon ce qu'il montre : files de la gestion, Collège, conflits, journée en cours, passé, série, demandes, indisponibilités, demandes d'aide, organisation bannie, quota presque atteint) et volume (trajets ordinaires sur la flotte `DEMO-…`, 6 semaines avant et après). Comptes `demo.*@bus-communal.local` (mot de passe `password`) ; `user.test` est ajouté aux organisations de démo, `chauffeur.test` reçoit des missions. Relancer remplace la démo ; `DEMO_CLEAR=1` la retire seulement, sans toucher au reste |

---

## Ce que les tests ne couvrent pas (intentionnel)

- **Performance et concurrence** : la suite est orientée correction métier, pas charge.
- **Frontend** : les tests sont purement API REST.
- **Playwright / E2E** : relèvent du dépôt frontend.