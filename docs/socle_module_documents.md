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
| `responsable_organisation` | view, download |
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
