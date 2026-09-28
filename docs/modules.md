# Modules backend — Vue d'ensemble

Ce document liste l'ensemble des modules du backend, leur périmètre fonctionnel et leur état actuel.

**État global du projet (septembre 2026) :** backend stable, 439 tests, 1924 assertions, 0 échec.

---

## Modules en production

### Auth
- Inscription, connexion, déconnexion, profil (`GET /api/auth/me`)
- Vérification email (`GET /email/verify/{id}/{hash}`, `POST /email/verify/resend`)
- Authentification SPA via Laravel Sanctum (cookie de session)
- Rate limiting sur les routes sensibles (register: 10/min, login: 20/min)

### Utilisateurs
- CRUD complet avec permissions Spatie
- Désactivation de compte, changement de statut, modification de mot de passe
- Filtres par rôle, statut, organisation

### Lieux (Locations)
- Géocodage via OpenRouteService à la création
- Suggestion d'adresses (`GET /api/locations/suggest`)
- Re-géocodage optionnel à la mise à jour si l'adresse change
- Fallback Haversine si ORS indisponible (coordonnées existantes)

### Communes (Municipalities)
- Configuration dynamique de la commune active pour le scoring
- Synchronisation des sous-localités depuis GeoNames (commune, province, pays)
- Upsert idempotent pour éviter les doublons GeoNames
- Une seule commune active à la fois

### Organisations
- 5 types : `ecole_communale`, `personnel_communal`, `cpas`, `asbl`, `externe`
- Gestion du quota annuel avec reset automatique au 1er janvier
- Système de bannissement (`is_banned`, `banned_reason`) — permission `organizations.ban` (admin uniquement)
- Justificatif lié via `proof_document_id` (FK vers `documents`)
- Voir [`organizations.md`](organizations.md)

### Demandes d'inscription organisation (OrgRegistrationRequests)
- Soumission par un utilisateur, instruction par un agent
- Score de confiance automatique (0-100) basé sur plusieurs critères métier
- Pièce jointe justificative via `proof_document_id`
- Approbation crée l'organisation + rattache le demandeur

### Demandes d'adhésion organisation (UserOrgRequests)
- Un utilisateur demande à rejoindre une organisation existante avec un rôle
- Score de confiance automatique
- Approbation attache l'utilisateur à l'organisation (`is_default` si premier rattachement)

### Chauffeurs (Drivers)
- Profil lié à un utilisateur (1:1)
- Numéro et date d'expiration du permis
- Activation/désactivation

### Véhicules (Vehicles)
- Immatriculation unique, capacité (chauffeur compris)
- Activation/désactivation

### Indisponibilités (Unavailabilities)
- Types : `driver` ou `vehicle`
- Historique des modifications (`unavailabilities_history`)
- Le chauffeur ne voit que ses propres indisponibilités

### Réservations (Reservations)
- Cycle de vie complet : création → validation → confirmation → annulation/refus
- 6 statuts : `en_attente`, `approuve`, `confirme`, `en_conflit`, `refuse`, `annule`
- 2 modes d'approbation : automatique (écoles communales) et manuel
- Détection de 12 types de conflits à la création
- Historique de statut et d'affectation tracé
- Confirmation des passagers réels (`passengers_confirmed_at`)
- Voir [`reservations.md`](reservations.md) et [`conflicts.md`](conflicts.md)

### Récurrences (Recurrences)
- Fréquences : hebdomadaire, bihebdomadaire, mensuel
- Prévisualisation avant création
- Auto-confirmation des occurrences pour les écoles communales
- Annulation en cascade de toutes les occurrences futures
- Voir [`recurrences.md`](recurrences.md)

### Planning
- Lecture filtrée des réservations sur une période (max 31 jours)
- Calcul de disponibilité chauffeur/véhicule en temps réel
- Export Excel synchrone et asynchrone (job queue)
- Voir [`planning.md`](planning.md)

### Conflits de réservation (ReservationConflicts)
- 12 types de conflits détectés automatiquement
- Hard-block si physiquement impossible, soft conflict sinon
- Résolution automatique au déclenchement de certains événements
- Résolution manuelle par un agent
- Voir [`conflicts.md`](conflicts.md)

### Décisions du Collège (CouncilDecisions)
- 1 décision maximum par réservation
- Statuts : `en_attente`, `approuve`, `refuse`
- Génération automatique de la délibération PDF à la validation
- Voir [`deliberations.md`](deliberations.md)

### Documents
- Types : `feuille_route`, `attestation`, `deliberation`, `planning`, `justificatif`
- Génération PDF via Dompdf (délibérations, feuilles de route)
- Versioning automatique (incrément à chaque regénération)
- Téléchargement direct ou via token signé à durée limitée
- Journal d'accès (`document_access_logs`)
- `file_path` jamais exposé en API
- Voir [`documents.md`](documents.md)

### Feuilles de route (RouteSheets)
- 1 document par véhicule par journée
- Génération manuelle (API) ou automatique (scheduler à 18h pour J+1)
- Voir [`route_sheets.md`](route_sheets.md)

### Notifications
- Canaux : in-app (table `notifications`) et email
- Priorités : `critical`, `high`, `normal`, `low`
- Digest configurable (daily/weekly) par utilisateur
- Quiet hours par utilisateur
- Déduplication par `dedupe_key`
- Endpoint de santé avec seuils configurables
- Voir [`notifications.md`](notifications.md)

### Backups
- Commandes : `backup:run --only={full|db|files}`, `backup:list`
- Archives `.tar.gz` avec métadonnées
- Rétention configurable
- Voir [`backups.md`](backups.md)

### Infrastructure technique
- **Idempotence** : `api_idempotency_keys` pour les requêtes POST/PATCH critiques
- **Audit** : `domain_events` — journal immuable de tous les événements métier
- **Sessions** : persistées en base (table `sessions`)
- **Module Tooling** : Backend Inspector (journal des requêtes API mutantes) et Dev Toolbox — voir [`tooling.md`](tooling.md)

---

## Permissions (51 au total)

| Module | Permissions |
|---|---|
| users | view, create, update, update-status, deactivate |
| locations | view, create, update |
| municipalities | view, manage |
| vehicles | view, create, update, deactivate |
| drivers | view, create, update, deactivate |
| org-registration-requests | view, create, review |
| user-org-requests | view, create, review |
| organizations | view, create, update, ban |
| unavailabilities | view, create, update, cancel |
| reservations | view, create, update, approve, reject, cancel, assign, confirm, manage-conflicts |
| council-decisions | view, create, update |
| documents | view, download, generate, update |
| notifications | monitor |
| recurrences | cancel |
| tooling | access (admin uniquement — module Tooling) |

---

## Matrice des rôles

| Permission | admin | agent_communal | responsable_org | user | chauffeur |
|---|:---:|:---:|:---:|:---:|:---:|
| users.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| users.create | ✓ | — | — | — | — |
| users.update | ✓ | ✓ | — | — | — |
| users.update-status | ✓ | — | — | — | — |
| users.deactivate | ✓ | — | — | — | — |
| locations.create/update | ✓ | ✓ | ✓ | ✓ | — |
| municipalities.manage | ✓ | ✓ | — | — | — |
| vehicles/drivers CRUD | ✓ | ✓ | — | — | — |
| organizations.ban | ✓ | — | — | — | — |
| org-registration-requests.review | ✓ | ✓ | — | — | — |
| user-org-requests.review | ✓ | ✓ | — | — | — |
| reservations.approve/reject/assign | ✓ | ✓ | — | — | — |
| reservations.manage-conflicts | ✓ | ✓ | — | — | — |
| documents.generate | ✓ | ✓ | — | — | — |
| notifications.monitor | ✓ | — | — | — | — |
| recurrences.cancel | ✓ | ✓ | ✓ | ✓ | — |

---

## Documentation par module

| Document | Module(s) couverts |
|---|---|
| [`reservations.md`](reservations.md) | Réservations — statuts, règles, workflow |
| [`conflicts.md`](conflicts.md) | Conflits — types, détection, résolution |
| [`recurrences.md`](recurrences.md) | Récurrences — création, prévisualisation, annulation |
| [`planning.md`](planning.md) | Planning — filtres, disponibilités, export |
| [`deliberations.md`](deliberations.md) | Décisions du Collège — génération délibération |
| [`documents.md`](documents.md) | Documents — stockage, accès, sécurité |
| [`route_sheets.md`](route_sheets.md) | Feuilles de route — génération, versioning |
| [`notifications.md`](notifications.md) | Notifications — canaux, digest, santé |
| [`organizations.md`](organizations.md) | Organisations — types, quota, ban, scoring |
| [`operational-resources.md`](operational-resources.md) | Chauffeurs, véhicules, indisponibilités |
| [`access-control.md`](access-control.md) | Rôles, permissions, policies |
| [`logging.md`](logging.md) | Logs structurés, canaux, audit |
| [`backups.md`](backups.md) | Backups — commandes, format, rétention |
| [`testing.md`](testing.md) | Stratégie de tests |
| [`tooling.md`](tooling.md) | Module Tooling (Inspector, Toolbox) |