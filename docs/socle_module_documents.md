# Socle du module Documents — OuKiLéMonBus

## 1. Objectif général

Cette branche `feature/documents` pose le socle technique du module **Documents**.

Le but n’est pas encore de générer automatiquement tous les PDF finaux, mais de créer une base propre pour :

- enregistrer les documents générés ou officiels ;
- stocker les fichiers dans un espace privé ;
- contrôler qui peut voir ou télécharger chaque document ;
- exposer une API sécurisée ;
- préparer la génération automatique future des documents communaux :
  - délibérations ;
  - feuilles de route ;
  - plannings ;
  - attestations.

Le module a été pensé comme un **coffre documentaire officiel** : un document n’est pas seulement un fichier, c’est une trace administrative liée à un contexte métier.

---

## 2. État actuel de la branche

Branche concernée :

```text
feature/documents
```

PR créée en draft :

```text
[DRAFT] feat: socle du module documents
```

Commits principaux :

```text
feat: ajout des métadonnées de génération des documents
feat: ajout des permissions et policies documents
feat: ajout des services documents
feat: ajout des endpoints API documents
```

Tous les tests passent localement :

```text
285 tests passed
```

---

## 3. Fichiers ajoutés ou modifiés

### Base de données / modèle

```text
database/migrations/2026_05_11_103151_add_generation_fields_to_documents_table.php
app/Models/Document.php
tests/Unit/ModelConfigurationTest.php
```

### Permissions / sécurité

```text
database/seeders/RolesAndPermissionsSeeder.php
app/Policies/DocumentPolicy.php
```

### Resource API

```text
app/Http/Resources/DocumentResource.php
```

### Services métier

```text
app/Services/Documents/DocumentAccessService.php
app/Services/Documents/DocumentStorageService.php
app/Services/Documents/DocumentService.php
```

### API

```text
app/Http/Controllers/Api/DocumentController.php
routes/api.php
```

### Tests

```text
tests/Feature/DocumentApiTest.php
```

---

## 4. Extension de la table `documents`

La table `documents` existait déjà. Une migration complémentaire a été ajoutée pour préparer la génération automatique de documents officiels.

### Nouveaux champs ajoutés

```text
checksum
generation_context
template_key
template_version
```

### Rôle des champs

| Champ | Rôle |
|---|---|
| `checksum` | Empreinte technique du fichier généré, calculée en SHA-256 |
| `generation_context` | Snapshot JSON des données utilisées pour générer le document |
| `template_key` | Identifiant logique du modèle utilisé |
| `template_version` | Version du template utilisé |

### Exemple de `generation_context`

```json
{
  "generated_for": "council_decision",
  "reservation_id": 42,
  "organization_name": "ASBL Solidarité et Alternative Dinantaise",
  "organization_type": "asbl",
  "trip_date": "2026-06-16",
  "pickup_location": "Dinant",
  "destination": "Namur",
  "decision_status": "approuve",
  "template_key": "deliberations.external",
  "seal_included": true,
  "signatures_included": true
}
```

---

## 5. Choix de conception : pourquoi `generation_context` ?

Un document officiel doit rester compréhensible même si les données métier changent plus tard.

Exemple :

- une organisation change de nom ;
- une adresse est corrigée ;
- un utilisateur est désactivé ;
- une réservation est modifiée ou archivée.

Le PDF déjà généré ne doit pas devenir incohérent ou dépendre uniquement des données actuelles de la base.

Le champ `generation_context` permet de conserver les données utilisées **au moment de la génération**.

Ce n’est pas un remplacement des relations DB, mais une trace de génération.

---

## 6. Choix de conception : type de document vs template

Le projet utilise déjà l’enum :

```text
App\Enums\DocumentType
```

Types actuels :

```text
feuille_route
attestation
deliberation
planning
```

Nous avons décidé de garder ces types comme **grandes familles officielles**.

Nous n’avons pas créé une table dynamique `document_types`, même si cela aurait pu permettre à un admin d’ajouter des types via l’interface.

### Pourquoi ?

Créer des types dynamiques aurait fortement complexifié le projet :

- gestion des templates associés ;
- champs dynamiques requis ;
- règles métier propres à chaque type ;
- générateurs spécifiques ;
- permissions spécifiques ;
- validations ;
- interface admin ;
- tests supplémentaires.

Or ce n’est pas demandé dans le cahier des charges.

### Compromis retenu

```text
document_type = famille officielle
template_key = modèle exact utilisé
template_version = version du modèle
generation_context = données dynamiques figées
```

Exemples :

```text
document_type    = deliberation
template_key     = deliberations.school
template_version = 1.0
```

```text
document_type    = deliberation
template_key     = deliberations.external
template_version = 1.0
```

```text
document_type    = feuille_route
template_key     = route_sheets.default
template_version = 1.0
```

Cela permet de gérer plusieurs variantes sans rendre le système instable.

---

## 7. Mise à jour du modèle `Document`

Le modèle `Document` a été mis à jour pour inclure les nouveaux champs dans le `Fillable` :

```php
'checksum',
'generation_context',
'template_key',
'template_version',
```

Et le cast suivant a été ajouté :

```php
'generation_context' => 'array',
```

Cela permet de manipuler le champ JSON comme un tableau PHP.

Exemple :

```php
$document->generation_context['organization_name'];
```

---

## 8. Permissions ajoutées

Des permissions Spatie ont été ajoutées :

```text
documents.view
documents.download
documents.generate
documents.update
```

### Répartition logique

| Rôle | Permissions |
|---|---|
| `admin` | toutes les permissions |
| `agent_communal` | view, download, generate, update |
| `user` | view, download |
| `chauffeur` | view, download |

Il n’existe actuellement pas de rôle `college` dans le seeder. Nous n’avons donc pas ajouté de logique spéciale `college` dans le module Documents.

---

## 9. Sécurité : deux niveaux de contrôle

Le module utilise deux niveaux de sécurité.

### 1. Middleware de route

Les routes API sont protégées par les permissions Spatie.

Exemples :

```php
middleware('permission:documents.view')
middleware('permission:documents.download')
```

Ce niveau répond à la question :

```text
L’utilisateur a-t-il globalement le droit d’accéder à ce type d’action ?
```

### 2. Policy Laravel

La `DocumentPolicy` vérifie l’accès précis au document.

Ce niveau répond à la question :

```text
L’utilisateur peut-il voir ou télécharger CE document précis ?
```

---

## 10. `DocumentResource`

Fichier :

```text
app/Http/Resources/DocumentResource.php
```

### Rôle

Contrôler les données exposées par l’API.

### Point important

Le champ suivant n’est jamais exposé :

```text
file_path
```

Pourquoi ?

Parce que `file_path` contient le chemin interne du fichier dans le stockage privé.

L’API expose les métadonnées utiles :

```text
id
reservation_id
user_id
document_type
document_type_label
file_name
mime_type
file_size
checksum
generation_mode
generation_context
template_key
template_version
generated_at
is_official
version
is_available
created_at
updated_at
```

Le téléchargement passe toujours par une route sécurisée :

```text
GET /api/documents/{document}/download
```

---

## 11. `DocumentPolicy`

Fichier :

```text
app/Policies/DocumentPolicy.php
```

### Particularité

La Policy ne contient pas directement toute la logique métier.

Elle délègue à :

```text
DocumentAccessService
```

Cela permet d’éviter de dupliquer les règles d’accès entre :

- la Policy ;
- le Service ;
- les futures listes filtrées.

### Méthodes principales

```php
viewAny(User $user): bool
view(User $user, Document $document): bool
download(User $user, Document $document): bool
generate(User $user): bool
update(User $user, Document $document): bool
```

---

## 12. `DocumentAccessService`

Fichier :

```text
app/Services/Documents/DocumentAccessService.php
```

### Rôle

Centraliser les règles d’accès aux documents.

Ce service est la source principale pour savoir :

```text
qui peut voir quoi
qui peut télécharger quoi
qui peut générer
qui peut mettre à jour
```

### Méthodes principales

```php
visibleQueryFor(User $user): Builder
canViewAny(User $user): bool
canView(User $user, Document $document): bool
canDownload(User $user, Document $document): bool
canGenerate(User $user): bool
canUpdate(User $user, Document $document): bool
```

### Règles actuelles

Un utilisateur peut voir un document si :

- il a la permission `documents.view` ;
- le document est disponible, sauf s’il est interne ;
- il est directement lié au document via `user_id` ;
- il est le demandeur de la réservation liée ;
- il est responsable de l’organisation liée à la réservation ;
- il est chauffeur assigné à la réservation liée.

Les rôles internes sont :

```text
admin
agent_communal
```

Les rôles internes peuvent voir les documents indisponibles.

---

## 13. Pourquoi un `DocumentAccessService` ?

Au départ, la logique d’accès pouvait être placée dans `DocumentPolicy`.

Mais il fallait aussi filtrer les documents dans :

```php
DocumentService::listVisibleForUser()
```

Sans service dédié, on aurait eu une duplication :

```text
DocumentPolicy
DocumentService
```

Cela aurait créé un risque :

```text
une règle modifiée à un endroit mais oubliée à l’autre
```

Le choix retenu :

```text
DocumentAccessService = source unique des règles d’accès
DocumentPolicy = délégation Laravel
DocumentService = utilise le service d’accès pour les listes
```

---

## 14. `DocumentStorageService`

Fichier :

```text
app/Services/Documents/DocumentStorageService.php
```

### Rôle

Gérer uniquement le fichier physique.

Il ne décide pas :

- qui peut voir le fichier ;
- quand générer un document ;
- quel template utiliser ;
- si le document est officiel.

Il gère :

- le stockage dans le disque privé Laravel ;
- l’existence du fichier ;
- le checksum ;
- le téléchargement ;
- la suppression physique.

### Méthodes principales

```php
storeGeneratedFile(string $directory, string $fileName, string $content): array
exists(string $filePath): bool
delete(string $filePath): bool
checksum(string $filePath): ?string
download(Document $document): StreamedResponse
```

### Stockage privé

Les fichiers sont stockés via :

```php
Storage::disk('local')
```

On stocke des chemins relatifs, pas des chemins absolus Windows.

Exemple :

```text
documents/reservations/12/feuille-route-v1.pdf
documents/council-decisions/5/deliberation-v1.pdf
documents/planning/2026/week-24/planning.xlsx
```

À éviter :

```text
C:\laragon\www\...
```

---

## 15. Subtilité : fichier orphelin en cas d’échec DB

La création d’un document implique deux opérations :

1. écrire le fichier dans le stockage privé ;
2. créer l’enregistrement `Document` en base de données.

La transaction SQL protège seulement la base de données.

Elle ne peut pas annuler automatiquement un fichier déjà écrit sur le disque.

### Problème possible

```text
Fichier stocké
Erreur lors du Document::create()
Rollback DB
Fichier orphelin restant sur le disque
```

### Solution retenue

Dans `DocumentService::registerGeneratedDocument()`, on mémorise le chemin du fichier stocké.

En cas d’exception, on supprime le fichier via :

```php
$this->storageService->delete($storedFilePath);
```

Puis on relance l’exception.

Cela permet d’éviter les fichiers orphelins.

---

## 16. `DocumentService`

Fichier :

```text
app/Services/Documents/DocumentService.php
```

### Rôle

Orchestrer la logique métier documentaire.

Il ne gère pas directement les accès ni le stockage physique.

Il délègue :

```text
DocumentAccessService → accès / visibilité
DocumentStorageService → fichier physique
```

### Méthodes principales

```php
listVisibleForUser(User $user): Collection
registerGeneratedDocument(DocumentType $documentType, string $directory, string $fileName, string $content, array $data = []): Document
download(Document $document): StreamedResponse
markUnavailable(Document $document): Document
```

### Exemple de rôle de `registerGeneratedDocument()`

Cette méthode :

1. stocke le fichier via `DocumentStorageService` ;
2. calcule les infos du fichier :
   - chemin ;
   - nom ;
   - MIME type ;
   - taille ;
   - checksum ;
3. crée l’entrée `Document` en base ;
4. associe les métadonnées :
   - type ;
   - contexte ;
   - template ;
   - version ;
   - disponibilité ;
   - officialité.

---

## 17. `DocumentController`

Fichier :

```text
app/Http/Controllers/Api/DocumentController.php
```

### Routes exposées

```text
GET /api/documents
GET /api/documents/{document}
GET /api/documents/{document}/download
```

### Méthodes

```php
index(Request $request): AnonymousResourceCollection
show(Document $document): DocumentResource
download(Document $document): StreamedResponse
```

### Utilisation de `Gate::authorize()`

Le contrôleur utilise :

```php
Gate::authorize(...)
```

au lieu de :

```php
$this->authorize(...)
```

Pourquoi ?

Parce que le contrôleur de base du projet ne fournit pas directement la méthode `$this->authorize()`.

`Gate::authorize()` est une manière officielle de déclencher les Policies Laravel sans modifier le contrôleur parent global.

Cela évite d’introduire une modification transversale dans tout le projet.

---

## 18. Routes API ajoutées

Dans `routes/api.php`, ajout de :

```php
Route::middleware('permission:documents.view')->group(function (): void {
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
});

Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
    ->middleware('permission:documents.download')
    ->name('documents.download');

Route::get('/documents/{document}', [DocumentController::class, 'show'])
    ->middleware('permission:documents.view')
    ->name('documents.show');
```

### Remarque sur l’ordre

La route :

```text
/documents/{document}/download
```

est placée avant :

```text
/documents/{document}
```

Par prudence, afin d’éviter toute ambiguïté de matching.

---

## 19. Tests ajoutés

Fichier :

```text
tests/Feature/DocumentApiTest.php
```

### Ce qui est testé

- un admin peut lister les documents ;
- `file_path` n’est pas exposé ;
- un utilisateur lié au document peut voir son document ;
- un utilisateur non concerné ne peut pas voir le document ;
- un utilisateur normal ne peut pas voir un document indisponible ;
- un admin peut voir un document indisponible ;
- un utilisateur peut télécharger son document disponible ;
- un téléchargement échoue si le fichier physique est absent ;
- un utilisateur normal ne liste que ses documents visibles.

### Intérêt

Les tests valident le cœur du module :

```text
sécurité
visibilité
resource API
stockage
téléchargement
non-exposition du chemin interne
```

---

## 20. Architecture actuelle résumée

```text
DocumentResource
→ Définit ce que l’API renvoie.

DocumentPolicy
→ Délègue les décisions d’autorisation à DocumentAccessService.

DocumentAccessService
→ Centralise les règles d’accès métier.

DocumentStorageService
→ Gère le stockage privé, le checksum, le téléchargement et la suppression physique.

DocumentService
→ Orchestre la création, la consultation, la disponibilité et le téléchargement.

DocumentController
→ Expose les endpoints API sécurisés.
```

---

## 21. Flux : lister les documents

```text
GET /api/documents
        ↓
middleware permission:documents.view
        ↓
DocumentController@index
        ↓
Gate::authorize('viewAny', Document::class)
        ↓
DocumentService::listVisibleForUser($user)
        ↓
DocumentAccessService::visibleQueryFor($user)
        ↓
DocumentResource::collection(...)
```

---

## 22. Flux : consulter un document

```text
GET /api/documents/{document}
        ↓
middleware permission:documents.view
        ↓
DocumentController@show
        ↓
Gate::authorize('view', $document)
        ↓
DocumentPolicy
        ↓
DocumentAccessService::canView(...)
        ↓
DocumentResource
```

---

## 23. Flux : télécharger un document

```text
GET /api/documents/{document}/download
        ↓
middleware permission:documents.download
        ↓
DocumentController@download
        ↓
Gate::authorize('download', $document)
        ↓
DocumentPolicy
        ↓
DocumentAccessService::canDownload(...)
        ↓
DocumentService::download($document)
        ↓
vérifie is_available
        ↓
vérifie que le fichier existe
        ↓
DocumentStorageService::download($document)
```

---

## 24. Ce qui n’est pas encore fait

Cette branche ne génère pas encore automatiquement les documents.

Non inclus pour l’instant :

- génération PDF des délibérations ;
- génération des feuilles de route ;
- génération du planning Excel ;
- templates Blade PDF ;
- contexte builder spécifique aux délibérations ;
- branchement automatique dans `CouncilDecisionService` ;
- branchement automatique dans `ReservationService` ;
- insertion du cachet et des signatures ;
- tracking des téléchargements (`last_downloaded_at`, `download_count`) ;
- scheduler d’archivage/suppression.

---

## 25. Améliorations futures prévues

### Génération des délibérations

Prévoir :

```text
DeliberationContextBuilder
DeliberationGenerator
resources/views/documents/deliberations/school.blade.php
resources/views/documents/deliberations/external.blade.php
```

### Génération des feuilles de route

Prévoir :

```text
RouteSheetContextBuilder
RouteSheetGenerator
resources/views/documents/route-sheets/default.blade.php
```

### Planning hebdomadaire

Prévoir :

```text
WeeklyPlanningContextBuilder
WeeklyPlanningGenerator
```

Possiblement format Excel.

### Tracking des téléchargements

À ajouter plus tard si besoin :

```text
last_downloaded_at
download_count
```

Nous avons volontairement reporté ces champs pour ne pas complexifier tant que la fonctionnalité de suivi n’est pas réellement utilisée.

### Champ `stored_at`

La question a été discutée.

Pour l’instant, non ajouté car :

```text
generated_at = date de production du document
created_at = date d’enregistrement en base
checksum = preuve technique du fichier
generation_context = trace métier
```

`stored_at` reste une amélioration possible si un besoin d’audit plus fin apparaît.

---

## 26. Prochaine étape recommandée

La prochaine étape logique est de préparer la génération automatique des documents officiels, en commençant par les délibérations.

Ordre recommandé :

```text
1. DeliberationContextBuilder
2. DeliberationGenerator
3. Templates Blade
4. Tests du générateur
5. Branchement contrôlé avec CouncilDecisionService
```

Il est conseillé de ne pas brancher trop vite dans `CouncilDecisionService` sans coordination avec Sacha, car ce module a déjà été travaillé.

---

## 27. Résumé final

Le module Documents est désormais structuré comme un socle durable.

Il permet déjà :

- de référencer des documents ;
- de gérer leurs métadonnées de génération ;
- de contrôler l’accès ;
- de stocker les fichiers dans un espace privé ;
- de télécharger les fichiers de manière sécurisée ;
- d’exposer une API propre ;
- de tester les principaux cas d’usage.

La génération automatique des documents officiels pourra maintenant s’ajouter sans casser l’architecture existante.


# Mise à jour — Génération documentaire des délibérations

## 1. Objectif de cette mise à jour

Cette mise à jour complète le précédent document `socle_module_documents.md`.

Le socle Documents permettait déjà :

- d’enregistrer des documents en base ;
- de stocker les fichiers dans un stockage privé ;
- de gérer les permissions ;
- de contrôler les accès ;
- d’exposer une API documents ;
- de télécharger les documents de manière sécurisée.

La nouvelle étape ajoute une première chaîne complète de génération documentaire pour les **délibérations du Collège communal**.

Le projet sait maintenant :

```text
CouncilDecision
    ↓
DeliberationContextBuilder
    ↓
DeliberationGenerator
    ↓
Template Blade HTML
    ↓
Dompdf
    ↓
PDF binaire
    ↓
DeliberationDocumentService
    ↓
DocumentService
    ↓
Document stocké + fichier enregistré
```

Important : la génération est prête techniquement, mais elle n’est pas encore branchée automatiquement dans `CouncilDecisionService`.

---

## 2. Nouvelle dépendance PDF ajoutée

Pour générer des PDF depuis du HTML Blade, le package suivant a été ajouté :

```text
barryvdh/laravel-dompdf
```

Ce package installe notamment :

```text
barryvdh/laravel-dompdf
dompdf/dompdf
dompdf/php-font-lib
dompdf/php-svg-lib
```

Les fichiers modifiés sont :

```text
composer.json
composer.lock
```

Le dossier suivant ne doit pas être versionné :

```text
vendor/
```

Chaque développeur récupère la dépendance avec :

```bash
composer install
```

L’intérêt de Dompdf ici est simple :

```text
Vue Blade
    ↓
HTML
    ↓
PDF
```

C’est une solution adaptée à un projet Laravel scolaire, sans devoir installer Chromium, wkhtmltopdf ou un serveur externe.

---

## 3. Template Blade de délibération

Un template Blade a été créé :

```text
resources/views/documents/deliberations/external.blade.php
```

Ce fichier représente le modèle HTML d’une délibération pour les organisations externes, ASBL ou demandes soumises au Collège communal.

Il contient une première structure administrative inspirée des documents fournis :

- en-tête Ville de Dinant ;
- extrait du registre aux délibérations ;
- mention du Collège communal ;
- titre de la demande ;
- séance ;
- présents ;
- considérants ;
- décision ;
- articles ;
- destinataires ;
- signatures ;
- emplacement pour cachet communal ;
- emplacement pour QR code.

Ce template n’est pas considéré comme un fichier temporaire.

Il est conçu comme la future base du rendu PDF :

```text
external.blade.php
    ↓
HTML
    ↓
Dompdf
    ↓
PDF
```

Cela permet d’ajuster la mise en page HTML progressivement, puis de produire un PDF final.

---

## 4. Choix de conception : commencer par Blade/HTML avant le PDF

Nous avons volontairement commencé par un template Blade HTML avant de produire directement un PDF.

Pourquoi ?

Un PDF est plus difficile à déboguer :

- le contenu binaire est moins lisible ;
- les erreurs de mise en page sont plus difficiles à isoler ;
- les tests sont plus lourds ;
- les ajustements CSS doivent souvent être faits progressivement.

Avec Blade/HTML, on peut tester facilement que le rendu contient bien les données attendues :

- nom de l’organisation ;
- date de la décision ;
- référence de décision ;
- date du trajet ;
- lieu de départ ;
- destination ;
- motif de la réservation.

Ensuite, le même HTML sert de base au PDF.

Ce n’est donc pas du code jetable.

---

## 5. `DeliberationContextBuilder`

Fichier :

```text
app/Services/Documents/ContextBuilders/DeliberationContextBuilder.php
```

### Rôle

Cette classe prépare les données métier nécessaires à la génération d’une délibération.

Elle reçoit une instance de :

```text
CouncilDecision
```

Puis elle charge les relations nécessaires :

```php
$councilDecision->loadMissing([
    'recordedByUser',
    'reservation.requestedByUser',
    'reservation.organization.location',
    'reservation.pickupLocation',
    'reservation.destinationLocation',
    'reservation.driver.user',
    'reservation.vehicle',
]);
```

Elle retourne ensuite un tableau structuré.

### Responsabilité

Cette classe ne fait pas de rendu HTML.

Elle ne génère pas de PDF.

Elle ne crée pas de document en base.

Elle prépare seulement les données.

---

## 6. Structure du contexte généré

Le contexte retourné est organisé en blocs :

```text
document
decision
reservation
organization
locations
assignment
```

### Exemple de structure

```php
[
    'document' => [
        'type' => 'deliberation',
        'template_key' => 'deliberations.external',
        'template_version' => '1.0',
        'generated_at' => now()->toISOString(),
    ],

    'decision' => [
        'id' => ...,
        'status' => ...,
        'status_label' => ...,
        'date' => ...,
        'reference' => ...,
        'validated_at' => ...,
        'notes' => ...,
        'recorded_by' => ...,
    ],

    'reservation' => [
        'id' => ...,
        'trip_date' => ...,
        'departure_time' => ...,
        'return_time' => ...,
        'trip_purpose' => ...,
        'activity_type' => ...,
        'nb_children' => ...,
        'nb_adults' => ...,
        'total_passengers' => ...,
        'distance_km' => ...,
        'estimated_duration_min' => ...,
        'status' => ...,
        'approval_type' => ...,
        'requested_by' => ...,
    ],

    'organization' => [
        'id' => ...,
        'name' => ...,
        'type' => ...,
        'type_label' => ...,
        'official_email' => ...,
        'phone_contact' => ...,
        'name_contact' => ...,
    ],

    'locations' => [
        'organization' => ...,
        'pickup' => ...,
        'destination' => ...,
    ],

    'assignment' => [
        'driver' => ...,
        'vehicle' => ...,
    ],
]
```

---

## 7. Pourquoi utiliser un contexte structuré ?

Le template Blade ne doit pas manipuler directement les modèles Eloquent dans tous les sens.

On évite ceci dans le template :

```php
$reservation->organization->location->formatted_address
```

On préfère fournir directement :

```php
data_get($context, 'locations.organization.formatted_address')
```

Cela rend le template :

- plus lisible ;
- plus stable ;
- moins dépendant des relations Eloquent ;
- plus simple à tester ;
- plus facile à convertir en PDF.

---

## 8. Comportement défensif du builder

Le builder est conçu pour ne pas planter si certaines relations sont absentes.

Par exemple :

- une réservation peut ne pas encore avoir de chauffeur ;
- une réservation peut ne pas encore avoir de véhicule ;
- certains champs peuvent être null ;
- certaines relations peuvent ne pas être chargées.

Le code utilise donc des accès défensifs du type :

```php
$reservation?->driver?->user
$reservation?->vehicle
$organization?->location
```

Cela évite de casser la génération si le document est préparé à un moment où tout n’est pas encore assigné.

---

## 9. `DeliberationGenerator`

Fichier :

```text
app/Services/Documents/Generators/DeliberationGenerator.php
```

### Rôle

Le générateur transforme un contexte déjà préparé en rendu documentaire.

Il ne connaît pas directement :

- `CouncilDecision` ;
- `Reservation` ;
- `Organization` ;
- `Location`.

Il reçoit seulement un tableau de contexte.

Cela permet de garder une séparation claire :

```text
DeliberationContextBuilder
    → prépare les données

DeliberationGenerator
    → génère le rendu HTML/PDF
```

---

## 10. Méthodes de `DeliberationGenerator`

### `generateHtml(array $context): string`

Cette méthode génère le HTML depuis le template Blade :

```php
public function generateHtml(array $context): string
{
    return view(self::VIEW, [
        'context' => $context,
    ])->render();
}
```

Elle utilise la vue :

```php
private const VIEW = 'documents.deliberations.external';
```

Ce qui correspond au fichier :

```text
resources/views/documents/deliberations/external.blade.php
```

---

### `generatePdf(array $context): string`

Cette méthode transforme le HTML en PDF :

```php
public function generatePdf(array $context): string
{
    $html = $this->generateHtml($context);

    return Pdf::loadHTML($html)
        ->setPaper('a4', 'portrait')
        ->output();
}
```

Elle utilise l’import suivant :

```php
use Barryvdh\DomPDF\Facade\Pdf;
```

Le PDF généré est un contenu binaire sous forme de chaîne.

---

### `defaultFileName(array $context): string`

Cette méthode génère un nom de fichier HTML sécurisé.

Exemple :

```text
deliberation-coll-2026-001-42.html
```

---

### `defaultPdfFileName(array $context): string`

Cette méthode génère un nom de fichier PDF sécurisé.

Exemple :

```text
deliberation-coll-2026-001-42.pdf
```

---

## 11. Nettoyage des noms de fichiers

Les méthodes de nom de fichier utilisent une méthode privée :

```php
private function safeFileName(string $fileName): string
```

Elle permet de nettoyer les caractères problématiques :

- espaces ;
- slashs ;
- accents problématiques ;
- caractères spéciaux ;
- répétitions de tirets.

Exemple :

```text
COLL 2026/001
```

devient :

```text
coll-2026-001
```

Cela évite de produire des noms de fichiers dangereux ou invalides pour le stockage.

---

## 12. `DeliberationDocumentService`

Fichier :

```text
app/Services/Documents/DeliberationDocumentService.php
```

### Rôle

Ce service crée réellement un document officiel de délibération.

Il orchestre les trois briques principales :

```text
DeliberationContextBuilder
DeliberationGenerator
DocumentService
```

Sa méthode principale est :

```php
generateFor(CouncilDecision $councilDecision): Document
```

---

## 13. Flux interne de `DeliberationDocumentService`

### Étape 1 — Construire le contexte

```php
$context = $this->contextBuilder->build($councilDecision);
```

Le contexte contient :

- les informations de décision ;
- la réservation ;
- l’organisation ;
- les lieux ;
- l’éventuel chauffeur ;
- l’éventuel véhicule ;
- les métadonnées documentaires.

---

### Étape 2 — Générer le PDF

```php
$pdfContent = $this->generator->generatePdf($context);
```

Cette ligne produit le PDF binaire.

---

### Étape 3 — Générer le nom de fichier

```php
$fileName = $this->generator->defaultPdfFileName($context);
```

Exemple :

```text
deliberation-coll-2026-001-42.pdf
```

---

### Étape 4 — Enregistrer via `DocumentService`

```php
return $this->documentService->registerGeneratedDocument(
    documentType: DocumentType::DELIBERATION,
    directory: $this->directoryFor($councilDecision),
    fileName: $fileName,
    content: $pdfContent,
    data: [
        'reservation_id' => $councilDecision->reservation_id,
        'user_id' => $councilDecision->recorded_by_user_id,
        'generation_mode' => 'auto',
        'generation_context' => $context,
        'template_key' => data_get($context, 'document.template_key'),
        'template_version' => data_get($context, 'document.template_version'),
        'generated_at' => now(),
        'is_official' => true,
        'version' => $this->nextVersionFor($councilDecision),
        'is_available' => true,
    ]
);
```

Cette étape :

- stocke le fichier PDF ;
- calcule sa taille ;
- calcule son checksum ;
- crée une ligne `documents` ;
- conserve le contexte de génération.

---

## 14. Répertoire de stockage

Les PDF de délibération sont stockés dans :

```text
documents/council-decisions/{id}
```

Exemple :

```text
documents/council-decisions/12/deliberation-coll-2026-001-42.pdf
```

Le chemin complet reste privé.

Il est stocké en base dans :

```text
file_path
```

Mais il n’est pas exposé dans l’API publique.

Le téléchargement passe toujours par :

```text
GET /api/documents/{document}/download
```

---

## 15. Gestion des versions

Le service calcule automatiquement la prochaine version d’une délibération avec :

```php
nextVersionFor(CouncilDecision $councilDecision)
```

Le principe :

```text
première génération     → version 1
première régénération   → version 2
deuxième régénération   → version 3
```

La version est calculée à partir des documents existants pour :

```text
reservation_id = réservation concernée
document_type = deliberation
```

Cela évite d’écraser silencieusement une ancienne délibération.

---

## 16. Pourquoi ne pas brancher automatiquement dans `CouncilDecisionService` ?

À ce stade, `DeliberationDocumentService` est prêt, mais il n’est pas encore appelé automatiquement depuis :

```text
CouncilDecisionService
```

Ce choix est volontaire.

### Raisons

`CouncilDecisionService` contient déjà une logique métier importante :

- création de décision ;
- modification de décision ;
- rejet de réservation si décision défavorable ;
- résolution de conflits si décision favorable ;
- vérification qu’une décision du Collège est requise.

Brancher directement la génération PDF dedans changerait le comportement métier du module.

Comme Sacha a travaillé sur cette partie, il est préférable de valider ensemble le bon moment de déclenchement.

---

## 17. Options futures de déclenchement

La génération pourra être déclenchée de plusieurs manières.

### Option A — Génération automatique à la création

La délibération serait générée dès la création d’une décision.

Avantage :

```text
automatique
```

Inconvénient :

```text
risque de générer trop tôt si la décision doit encore être modifiée
```

---

### Option B — Génération automatique seulement si décision approuvée

La délibération serait générée uniquement si :

```text
decision_status = approuvé
```

Avantage :

```text
plus cohérent métier
```

Inconvénient :

```text
il faut vérifier précisément les statuts et leur cycle de vie
```

---

### Option C — Génération manuelle via endpoint API

Exemple possible :

```text
POST /api/council-decisions/{councilDecision}/generate-document
```

ou :

```text
POST /api/documents/deliberations/{councilDecision}/generate
```

Avantage :

```text
plus prudent
testable
moins intrusif
permet un bouton front
```

Inconvénient :

```text
nécessite une action utilisateur
```

---

### Option D — Bouton front

Un agent communal pourrait cliquer sur :

```text
Générer la délibération
```

Avantage :

```text
contrôle humain
```

Inconvénient :

```text
demande une intégration front
```

---

## 18. Recommandation actuelle

La recommandation actuelle est de commencer par une génération manuelle via endpoint API.

Pourquoi ?

- cela ne modifie pas encore le comportement automatique de `CouncilDecisionService` ;
- cela permet de tester le PDF ;
- cela permet à l’équipe de valider le rendu ;
- cela laisse le choix d’automatiser plus tard ;
- cela limite les conflits avec le travail de Sacha.

---

## 19. Tests ajoutés ou complétés

### `DeliberationContextBuilderTest`

Ce test vérifie que le contexte est construit correctement.

Il contrôle notamment :

- la décision ;
- la réservation ;
- l’organisation ;
- les lieux ;
- le chauffeur ;
- le véhicule ;
- le nombre total de passagers ;
- le comportement défensif quand certaines relations sont absentes.

---

### `DeliberationGeneratorTest`

Ce test vérifie :

- la génération HTML ;
- la présence des informations attendues dans le HTML ;
- la génération PDF ;
- le fait que le PDF commence par `%PDF` ;
- le nom de fichier HTML sécurisé ;
- le nom de fichier PDF sécurisé.

---

### `DeliberationDocumentServiceTest`

Ce test vérifie que le service orchestre correctement :

- le builder ;
- le générateur PDF ;
- le service documentaire ;
- le type `DocumentType::DELIBERATION` ;
- le contexte stocké ;
- le template utilisé ;
- la version ;
- le caractère officiel ;
- la disponibilité.

---

## 20. État actuel des tests

Tous les tests passent localement.

```text
300 tests passed
1077 assertions
```

Cela valide :

- le socle Documents ;
- les endpoints API documents ;
- la génération HTML ;
- la génération PDF ;
- le service documentaire de délibération ;
- les règles d’accès ;
- le stockage ;
- les tests existants du projet.

---

## 21. Ce qui est maintenant fonctionnel

Le module sait maintenant :

- construire un contexte de délibération depuis une décision du Collège ;
- générer un HTML de délibération ;
- convertir ce HTML en PDF ;
- générer un nom de fichier PDF sécurisé ;
- enregistrer ce PDF via le module Documents ;
- stocker le contexte de génération ;
- versionner les documents de type délibération ;
- conserver le fichier dans un stockage privé ;
- télécharger le document via l’API Documents existante.

---

## 22. Ce qui n’est pas encore fait

Même si la génération PDF est prête, certains éléments ne sont pas encore faits.

Non encore branché :

- appel automatique depuis `CouncilDecisionService::create()` ;
- appel automatique depuis `CouncilDecisionService::update()` ;
- route API dédiée à la génération manuelle ;
- bouton front ;
- notification liée à la génération ;
- envoi automatique du document ;
- suivi des téléchargements ;
- images réelles de cachet/signature ;
- QR code officiel réel ;
- comparaison visuelle complète avec les documents originaux.

---

## 23. Points à discuter avec Sacha

Questions importantes :

1. À quel moment une délibération doit-elle être générée ?
2. Faut-il générer une délibération pour toutes les décisions ou uniquement les décisions approuvées ?
3. Une délibération refusée doit-elle avoir un modèle différent ?
4. Faut-il prévoir un bouton manuel côté front ?
5. Faut-il empêcher la génération si une version existe déjà ?
6. Faut-il marquer les anciennes versions comme indisponibles lors d’une régénération ?
7. Faut-il intégrer un cachet/signature réels ?
8. Qui a le droit de générer officiellement une délibération ?
9. Le document généré doit-il être envoyé automatiquement ?
10. Le rendu actuel doit-il être rapproché davantage des originaux fournis ?

---

## 24. Résumé final

Le module Documents est passé d’un simple socle de stockage/API à une première chaîne complète de génération documentaire.

État actuel :

```text
CouncilDecision
    ↓
DeliberationContextBuilder
    ↓
DeliberationGenerator
    ↓
Blade HTML
    ↓
Dompdf PDF
    ↓
DeliberationDocumentService
    ↓
DocumentService
    ↓
Storage privé + Document DB
```

La génération PDF de délibération est prête techniquement.

Le déclenchement automatique reste à valider avec l’équipe avant intégration dans le workflow métier.
