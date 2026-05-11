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
