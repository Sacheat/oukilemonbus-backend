# Organisations

Ce document décrit le module organisations : types, comportements associés, gestion du quota, système de bannissement, justificatifs et scoring de confiance.

---

## Types d'organisation

Définis dans l'enum `App\Enums\OrganizationType` :

| Valeur | Label | Comportement par défaut |
|---|---|---|
| `ecole_communale` | École communale | Prioritaire, quota exempt, auto-approuvé, récurrences autorisées, exempte de décision du Collège, exempte des restrictions horaires |
| `personnel_communal` | Personnel communal | Configurable |
| `cpas` | CPAS | Configurable |
| `asbl` | ASBL | Quota annuel, décision du Collège requise |
| `externe` | Externe | Quota annuel, décision du Collège requise |

Les comportements (priorité, auto-approbation, exemptions) sont configurés dans `config/reservations.php` et non codés en dur, ce qui permet de les ajuster sans modifier le code.

---

## Quota annuel

Chaque organisation non exempte a un quota de réservations par année civile :

- `annual_quota` : nombre maximum de réservations autorisées (défaut : 5)
- `reservations_used_this_year` : compteur incrémenté à chaque réservation créée
- `quota_reset_year` : horodatage du dernier reset (reset automatique au 1er janvier via le scheduler)

Le dépassement de quota génère un conflit de type `quota_exceeded` (soft conflict, non bloquant au niveau DB).

---

## Bannissement

Un administrateur peut bannir une organisation (`organizations.ban` — permission admin uniquement).

| Champ | Type | Description |
|---|---|---|
| `is_banned` | boolean | true si l'organisation est bannie |
| `banned_reason` | varchar(500) nullable | Raison du bannissement |

Endpoint :
```
PATCH /api/organizations/{id}/ban
PATCH /api/organizations/{id}/unban
```

Effet : les réservations d'une organisation bannie sont bloquées au niveau applicatif.

---

## Justificatif (proof_document_id)

Les organisations peuvent avoir un document justificatif associé (statuts ASBL, délibération de création, etc.) via le champ `proof_document_id` qui est une FK vers la table `documents`.

Ce champ est partagé avec les demandes d'inscription (`org_registration_requests.proof_document_id`) et les demandes d'adhésion (`user_org_requests.proof_document_id`).

Upload du justificatif :
```
POST /api/org-registration-requests/{id}/proof
POST /api/user-org-requests/{id}/proof
```

---

## Rattachement utilisateur / organisation

La table pivot `user_organization` lie les utilisateurs aux organisations avec un rôle (`relationship_type`) :

| Rôle | Description |
|---|---|
| `prof` | Professeur |
| `directeur` | Directeur |
| `animateur` | Animateur |
| `responsable` | Responsable |
| `referent` | Référent |

Le champ `is_default` marque l'organisation affichée par défaut pour cet utilisateur à la connexion. Un utilisateur peut appartenir à plusieurs organisations mais une seule est `is_default = true`.

---

## Scoring de confiance

Le scoring de confiance est calculé **à la volée** par l'API lors du traitement des demandes d'inscription (`OrgRegistrationConfidenceScoreService`) et des demandes d'adhésion (`UserOrgRequestConfidenceScoreService`).

**Il n'est pas stocké en JSON dans une colonne dédiée.** Seul le score final (`confidence_note`, 0-100) est persisté. Le détail des critères est retourné dans `confidence_details` dans la réponse API mais n'est jamais sauvegardé.

Critères évalués (exemples) :
- Adresse de l'organisation vérifiée et dans la commune active
- Email officiel fourni
- Numéro d'entreprise fourni (ASBL)
- Justification cohérente avec le rôle demandé
- Téléphone fourni
- Contact nommé

---

## API

```
GET    /api/organizations                     Liste (filtrée selon le rôle)
GET    /api/organizations/{id}               Détail
POST   /api/organizations                    Créer (agent_communal, admin)
PATCH  /api/organizations/{id}               Mettre à jour
PATCH  /api/organizations/{id}/ban           Bannir (admin)
PATCH  /api/organizations/{id}/unban         Débannir (admin)
```

---

## Schéma de la table `organizations`

| Colonne | Type | Description |
|---|---|---|
| `id` | bigint PK | |
| `name` | varchar(50) | Nom |
| `type` | varchar(50) | Valeur de `OrganizationType` |
| `official_email` | varchar(255) nullable | Email officiel |
| `phone_contact` | varchar(20) nullable | Téléphone |
| `name_contact` | varchar(255) nullable | Nom du contact |
| `location_id` | bigint FK nullable | Siège social |
| `is_priority` | boolean | Prioritaire dans l'ordonnancement |
| `is_banned` | boolean | Bannie |
| `banned_reason` | varchar(500) nullable | Raison du bannissement |
| `proof_document_id` | bigint FK nullable | Justificatif |
| `annual_quota` | int | Quota annuel (défaut : 5) |
| `reservations_used_this_year` | int | Compteur utilisé (reset 1er jan.) |
| `quota_reset_year` | timestamp | Horodatage du dernier reset |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Contrainte CHECK : `annual_quota >= 0 AND reservations_used_this_year >= 0`

---

## Services impliqués

| Service | Rôle |
|---|---|
| `OrganizationService` | CRUD, ban/unban, gestion quota |
| `OrgRegistrationRequestService` | Instruction des demandes d'inscription |
| `UserOrgRequestService` | Instruction des demandes d'adhésion |
| `OrgRegistrationConfidenceScoreService` | Calcul scoring demandes d'inscription |
| `UserOrgRequestConfidenceScoreService` | Calcul scoring demandes d'adhésion |
| `MunicipalityService` | Fourni au scoring pour vérifier si l'adresse est dans la commune |