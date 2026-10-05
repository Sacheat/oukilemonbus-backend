# Documentation technique — Module Documents

## 1. Objectif du module

Le module Documents permet de gérer les documents administratifs générés ou stockés par l'application OuKiLéMonBus.

Il sert notamment à :

- enregistrer les documents générés ;
- associer des documents à des réservations ;
- associer des documents à des décisions administratives ;
- permettre le téléchargement contrôlé des fichiers ;
- gérer les droits d'accès selon le rôle utilisateur ;
- conserver une trace des documents produits.

Les documents sont importants car ils représentent une partie officielle du fonctionnement du service du bus communal.

---

## 2. Rôle du module dans l'application

Le module Documents est utilisé par plusieurs autres modules :

```text
Documents
├── Réservations
├── Délibérations
├── Feuilles de route
├── Planning
├── Chauffeurs
├── Organisations
├── Logs
└── Notifications futures
Il sert de point commun pour tout ce qui concerne les fichiers produits par l'application.

3. Types de documents concernés

Le projet gère ou prévoit plusieurs types de documents.

Exemples :

délibérations d'accord ;
délibérations de refus ;
feuilles de route ;
documents liés à une réservation ;
exports administratifs éventuels ;
documents futurs liés aux notifications ou aux archives.
4. Principe général

Un document n'est pas seulement un fichier PDF ou Excel.

Dans l'application, un document contient aussi des métadonnées.

Exemples de métadonnées :

type de document ;
nom du fichier ;
chemin de stockage interne ;
utilisateur créateur ;
réservation liée ;
décision liée ;
date de génération ;
contexte de génération ;
version éventuelle ;
statut d'accès ;
informations de téléchargement.

Ces métadonnées permettent de retrouver, filtrer et sécuriser les documents.

5. Stockage des fichiers

Les fichiers générés sont stockés côté serveur.

Le chemin réel du fichier ne doit pas être exposé directement au front.

Le backend peut connaître un chemin interne comme :

storage/app/...

mais l'API ne doit pas renvoyer ce chemin privé.

À la place, l'API doit exposer des informations sûres comme :

le nom du document ;
le type ;
la date de génération ;
une URL ou route de téléchargement contrôlée ;
éventuellement des métadonnées non sensibles.
6. Pourquoi ne pas exposer file_path

Le champ file_path représente un chemin interne du serveur.

L'exposer dans une réponse API serait une mauvaise pratique, car cela donne au client des informations sur l'organisation interne du stockage.

Le bon comportement est :

Backend :
- connaît le chemin réel du fichier ;
- vérifie les droits ;
- lit le fichier ;
- renvoie le téléchargement.

Frontend :
- ne connaît pas le chemin réel ;
- appelle simplement une route de téléchargement.

Exemple attendu côté API :

{
  "id": 12,
  "type": "route_sheet",
  "filename": "route-sheet-2026-06-18-vehicle-1-v001.pdf",
  "download_url": "/api/documents/12/download"
}
7. Ressource API des documents

La ressource API des documents doit masquer les données sensibles.

Son rôle est de transformer le modèle Document en réponse JSON propre.

Elle peut renvoyer :

l'identifiant du document ;
son type ;
son nom ;
sa date de création ;
son statut ;
son URL de téléchargement ;
éventuellement son contexte si l'utilisateur a les droits.

Elle ne doit pas renvoyer directement :

le chemin privé du fichier ;
des données internes inutiles au front ;
des informations sensibles sans contrôle de rôle.
7 bis. Liste paginée : GET /api/documents

Permission `documents.view`, tous rôles. Les règles d'accès (`DocumentAccessService::visibleQueryFor`) s'appliquent
avant les filtres : un filtre ne peut jamais élargir ce que l'utilisateur voit.

Réponse paginée Laravel : `data` (DocumentResource, avec `reservation` et `user` chargés), `links`, `meta`
(`current_page`, `last_page`, `per_page`, `total`). Ordre : `generated_at` décroissant, puis `created_at`, puis `id`.

| Paramètre | Règle |
|---|---|
| `page` | numéro de page |
| `per_page` | 1 à 100, 15 par défaut (422 au-delà) |
| `document_type[]` | valeurs de `DocumentType` : `feuille_route`, `attestation`, `deliberation`, `planning`, `justificatif` |
| `exclude_type[]` | mêmes valeurs ; masque ces types (ex. `exclude_type[]=justificatif`) |
| `date_from`, `date_to` | `AAAA-MM-JJ`, jours à Bruxelles, bornes incluses, sur `generated_at` sinon `created_at` ; `date_to` ≥ `date_from` |
| `q` | 100 caractères max : nom de fichier (contient), numéro exact de réservation (entier), objet du trajet (`trip_purpose`, contient). Les `%` et `_` sont littéraux |
| `reservation_id` | documents de cette réservation, y compris les feuilles de route qui la couvrent (table pivot) |

Les filtres se cumulent (ET). Les valeurs inconnues renvoient 422.

Changements de contrat pour le front (GET /documents renvoyait auparavant la liste complète, sans `meta`) :
- la réponse reste `{ data: [...] }` mais ne contient plus que 15 documents par défaut : tout écran qui lisait
  « tous les documents » doit paginer (`meta.last_page`) ou passer `per_page` (100 max) ;
- `meta` et `links` apparaissent désormais ; un écran qui comptait `data.length` doit lire `meta.total` ;
- tout filtrage fait côté client (type, date, recherche) ne porte plus que sur la page chargée : à remplacer par
  les paramètres ci-dessus, par exemple `exclude_type[]=justificatif` pour masquer les justificatifs ;
- l'ordre est inchangé.

8. Contrôle d'accès aux documents

L'accès aux documents doit être strictement contrôlé.

Tous les utilisateurs ne doivent pas voir tous les documents.

Le service responsable de cette logique est le service d'accès documentaire.

Il doit répondre à une question simple :

Cet utilisateur peut-il consulter ou télécharger ce document ?
9. Règles générales d'accès
Admin et agent communal

Les rôles internes peuvent généralement accéder à l'ensemble des documents administratifs.

Ils doivent pouvoir :

consulter les documents ;
télécharger les documents ;
vérifier les documents liés aux réservations ;
gérer les documents officiels.
Chauffeur

Un chauffeur peut accéder uniquement aux documents qui le concernent.

Exemple :

feuille de route liée à un véhicule/jour où il a au moins une mission.

Il ne doit pas avoir accès à tous les documents administratifs.

Responsable d'organisation

Un responsable d'organisation peut accéder aux documents liés à son organisation si la règle métier le prévoit.

Attention : il ne doit pas voir un document global contenant des informations d'autres organisations.

Demandeur

Un demandeur peut accéder aux documents liés à ses propres demandes, selon les règles validées.

10. Cas particulier des feuilles de route

Les feuilles de route sont un cas sensible.

Une feuille de route peut contenir plusieurs missions d'une même journée pour un même véhicule.

Le chauffeur peut y avoir accès si :

il est chauffeur d'au moins une mission liée à cette feuille ;
la feuille concerne le véhicule et la journée de sa mission.

Le choix métier actuel vise une feuille de route journalière par véhicule.

Cela limite mieux l'exposition des données qu'une feuille globale sur toute une période.

11. Cas particulier des délibérations

Les délibérations sont des documents administratifs liés aux décisions du Collège.

Elles peuvent concerner :

une approbation ;
un refus.

L'accès à ces documents doit être contrôlé selon :

le rôle utilisateur ;
la réservation liée ;
l'organisation concernée ;
le niveau de sensibilité du document.

Une délibération ne doit pas être accessible publiquement sans contrôle.

12. Relations entre documents et réservations

Un document peut être lié à une ou plusieurs réservations.

Cas simple :

Document
↓
Reservation

Exemple : une délibération liée à une réservation.

Cas multiple :

Document
↓
document_reservation
↓
Reservations

Exemple : une feuille de route liée à plusieurs réservations du même véhicule et du même jour.

Cette relation multiple est importante pour les feuilles de route.

13. Table pivot document_reservation

La table pivot permet de lier un document à plusieurs réservations.

Elle sert notamment pour les feuilles de route.

Exemple :

Feuille de route du 18/06/2026 pour le véhicule 1
├── Réservation 12
├── Réservation 14
└── Réservation 18

Cette liaison permet ensuite :

de vérifier quels trajets apparaissent dans le document ;
de savoir quels chauffeurs sont concernés ;
de contrôler l'accès ;
de conserver un historique documentaire.
14. Génération des documents

La génération des documents est gérée par des services dédiés.

Principe :

Controller ou Commande Artisan
↓
Service documentaire
↓
Builder de contexte
↓
Générateur PDF ou export
↓
Stockage du fichier
↓
Création de l'entrée Document
↓
Association éventuelle aux réservations

Cette séparation évite de mettre toute la logique dans les contrôleurs.

15. Contexte de génération

Avant de générer un document, le backend construit un contexte.

Ce contexte rassemble les données nécessaires au template.

Exemples :

réservation ;
organisation ;
utilisateur demandeur ;
chauffeur ;
véhicule ;
lieux ;
date ;
horaires ;
nombre de passagers ;
informations légales ;
informations de transporteur ;
kilométrage ;
itinéraire.

Ce contexte est stocké dans la colonne `generation_context` (JSON) de la table `documents`. Cela permet de retrouver l'état exact des données au moment de la génération.

Les documents enregistrent également : `template_key` (identifiant du template utilisé), `template_version` (version du template) et `checksum` (empreinte SHA du fichier généré pour vérifier l'intégrité).

16. Templates de documents

Les documents PDF sont générés à partir de templates.

Les templates permettent de séparer :

la logique métier ;
les données ;
la présentation du document.

Exemples de templates :

resources/views/documents/deliberations/...
resources/views/documents/route_sheets/...

Les templates doivent rester lisibles et ne pas contenir trop de logique métier.

17. Versioning des documents

Certains documents doivent être versionnés.

Le versioning évite d'écraser un document déjà généré.

Exemple :

route-sheet-2026-06-18-vehicle-1-v001.pdf
route-sheet-2026-06-18-vehicle-1-v002.pdf

Cette logique est importante pour :

conserver l'historique ;
éviter la perte d'un document officiel ;
garder des traces en cas de modification ;
distinguer une version automatique d'une régénération forcée.
18. Snapshot historique

Un document généré représente un snapshot à un moment donné.

Si une réservation est modifiée après génération, l'ancien document ne change pas automatiquement.

Il reste la trace de ce qui existait au moment de sa génération.

Une nouvelle génération peut produire une nouvelle version.

Ce principe est important pour la traçabilité.

19. Génération manuelle et automatique

Certains documents peuvent être générés manuellement.

Exemple :

Un agent communal clique sur générer une feuille de route.

D'autres peuvent être générés automatiquement.

Exemple :

Tous les jours à 18h00, génération des feuilles de route du lendemain.

La génération automatique doit éviter de créer des doublons inutiles.

La génération manuelle peut prévoir une option de régénération forcée si nécessaire.

20. Téléchargement des documents

Le téléchargement doit passer par une route contrôlée.

Principe :

Utilisateur demande le téléchargement
↓
Le backend vérifie l'accès
↓
Le backend retrouve le fichier
↓
Le backend renvoie le fichier

Le front ne doit pas lire le fichier directement dans le stockage.

21. Sécurité du téléchargement

Avant de télécharger un document, le backend doit vérifier :

que l'utilisateur est authentifié ;
qu'il a le rôle ou la relation nécessaire ;
que le document existe ;
que le fichier existe encore physiquement ;
que le document n'est pas interdit d'accès.

Si l'une de ces conditions échoue, le backend doit refuser l'accès.

22. Logs liés aux documents

Certaines actions documentaires peuvent être loggées.

Exemples :

document généré ;
téléchargement effectué ;
échec de génération ;
accès refusé ;
document introuvable ;
régénération forcée.

Ces logs peuvent aider à comprendre ce qui s'est passé en cas de problème.

23. Lien avec les notifications

Le module Notifications est pleinement implémenté. Les utilisateurs sont notifiés (in-app et/ou email selon leurs préférences) lorsqu'un document les concernant est disponible.

Exemples :

une délibération est générée ;
une feuille de route est disponible ;
une demande est refusée.

Les documents peuvent être envoyés en pièce jointe directe (si < seuil de taille) ou via un lien signé temporaire (`document_access_tokens`). Voir `docs/notifications.md`.

24. Tests du module Documents

Les tests doivent vérifier :

la génération correcte des documents ;
l'absence d'exposition du chemin interne ;
les droits d'accès ;
le téléchargement ;
le versioning ;
l'association aux réservations ;
la génération des feuilles de route ;
la génération des délibérations.

Les tests documentaires sont importants car les documents ont une valeur administrative.

25. Limites actuelles
Export et formats

Certains exports peuvent être compatibles Excel sans être de vrais fichiers .xlsx.

Modèles de refus

Les modèles de délibération de refus doivent être confirmés selon les documents disponibles.

Accès aux documents globaux

Un document contenant plusieurs réservations doit être traité avec prudence.

Exemple :

Une feuille globale ne doit pas exposer les trajets de toutes les organisations à un simple demandeur.
Archivage

Les règles d'archivage doivent être confirmées pour chaque type de document.

Les feuilles de route doivent être conservées selon les obligations métier ou légales retenues.

26. Améliorations futures

Améliorations possibles :

vrai système d'archivage avec statut actif/remplacé ;
chiffrement ou protection renforcée des fichiers sensibles ;
recherche documentaire avancée (véhicule, organisation) ;
filtres par véhicule ;
filtres par organisation ;
génération automatique de certains documents ;
notifications lors de la disponibilité d'un document ;
audit des téléchargements ;
vraie gestion de durée légale de conservation par type documentaire.
27. Résumé final

Le module Documents est un module transversal de l'application.

Il permet de générer, stocker, lier et télécharger les documents administratifs du bus communal.

Il est utilisé par les réservations, les délibérations, les feuilles de route, le planning et les futures notifications.

Les points essentiels sont la sécurité d'accès, le masquage des chemins internes, le versioning, la traçabilité et l'association correcte aux réservations.
