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

« Exempte de décision du Collège » veut dire que la décision n'est jamais **exigée** (approbation sans Collège, absente
de « Collège à décider »). Elle reste **possible** (`POST /council-decisions`) : utile pour une dérogation horaire, avec
les mêmes effets que pour les autres types (favorable : dérogations levées et délibération ; défavorable : réservation
refusée).

---

## Quota annuel

Chaque organisation non exempte (types de `config('reservations.quota_exempt_types')` : école communale, personnel
communal) a un quota de réservations par année civile (cahier des charges §5.6) :

- `annual_quota` : nombre maximum de réservations, **réglé par un agent ou un admin dans les deux sens** (0 = aucune
  réservation possible ; au-delà de 5 possible). `config('reservations.annual_quota')` (5) n'est que la valeur par
  défaut d'une nouvelle organisation.
- **Le quota se compte** (`ReservationQuotaService`) : les réservations `approuve` et `confirme` de l'année du trajet.
  La création d'une réservation au-delà est **bloquée (422)**. Puisqu'on compte par année, la remise à zéro au
  1er janvier se fait d'elle-même : aucune tâche planifiée.
- `reservations_used_this_year` et `quota_reset_year` (colonnes) ne sont plus utilisés. L'API renvoie `quota`
  `{ exempt, used, limit, remaining, year }`, calculé par la même règle (`ReservationQuotaService::summary`), dans la
  liste et le détail des organisations ; `reservations_used_this_year` y vaut `quota.used` (compatibilité). Une
  organisation imbriquée dans une réservation, le planning ou une demande ne renvoie pas `quota` (pas de requête par
  ligne).
- Autres limites (inchangées) : au plus `max_pending_requests` demandes en attente et `max_confirmed_reservations`
  réservations confirmées à venir (2 et 2 par défaut).

---

## Bannissement

Un administrateur peut bannir une organisation (`organizations.ban` — permission admin uniquement).

| Champ | Type | Description |
|---|---|---|
| `is_banned` | boolean | true si l'organisation est bannie |
| `banned_reason` | varchar(500) nullable | Raison du bannissement |

Endpoints :
```
POST /api/organizations/{id}/ban     { reason (obligatoire), cancel_upcoming (facultatif, false par défaut) }
POST /api/organizations/{id}/unban
```

Effets (`OrganizationService::ban`) :

```
organisation déjà bannie                         → 422
bannir                                           → plus aucune nouvelle réservation (ReservationPreviewService)
  si cancel_upcoming                             → chaque réservation à venir (en attente, en conflit, approuvée,
                                                   confirmée) est annulée par ReservationService::cancel, motif
                                                   « Organisation bannie : … » (demandeur et chauffeur prévenus) ;
                                                   celles qui ne peuvent pas l'être sont renvoyées dans meta.skipped
  sinon                                          → les réservations à venir restent (un agent les annule s'il le faut)
membres actifs prévenus                          → organization.banned (motif) / organization.unbanned
historique                                       → organization.banned / organization.unbanned
```

Réponse du bannissement : l'organisation + `meta.cancelled_reservation_ids` et `meta.skipped` (`{id, reason}`).

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

Le score (0-100) est calculé par `OrgRegistrationConfidenceScoreService` (demandes de création d'organisation) et `UserOrgRequestConfidenceScoreService` (demandes d'adhésion).

**C'est un barème de points écrit à la main, qui sert à trier la file des agents** : les dossiers complets et cohérents remontent. Il ne prouve rien : il n'ouvre pas le justificatif, ne vérifie pas que l'entreprise existe, et ne remplace pas la décision de l'agent.

### Demande de création d'organisation

Un dossier complet et cohérent fait **100**, quel que soit le type d'organisation.

| Critère | Points |
|---|---|
| Téléphone de contact, personne de contact, son rôle | +10, +10, +5 |
| Adresse renseignée, adresse vérifiée, adresse dans la commune | +5, +5, +10 |
| Justificatif fourni | +25 |
| Numéro BCE valide : 10 chiffres, commence par 0 ou 1, clé de contrôle correcte (97 − les 8 premiers chiffres modulo 97) | +25 |
| Domaine de l'email officiel qui reprend un mot significatif du nom (« info@amisduparc.be » pour « ASBL Les Amis du Parc ») | +5 |

Pénalités et avertissements :

| Situation | Effet |
|---|---|
| Numéro BCE saisi mais invalide (format ou clé) | −15 |
| ASBL sans numéro BCE | −10 |
| Ni téléphone ni personne de contact | −10 |
| Adresse hors de la commune | −5 |
| Nom de moins de 4 caractères | −5 |
| Adresse email grand public (gmail, hotmail, outlook, skynet…) | Avertissement sans retrait de points : beaucoup de petites ASBL légitimes les utilisent |
| Adresse absente | Avertissement sans point |

Contrôles de **l'état du moment**, évalués seulement pour une demande **en attente** (sur une demande traitée, l'organisation créée par son approbation les ferait déclencher) :

| Situation | Effet |
|---|---|
| Même numéro BCE (quelle que soit l'écriture : « 0123.456.749 », « BE 0123 456 749 ») qu'une autre demande en attente ou approuvée (une organisation n'a pas de colonne BCE : une demande approuvée correspond à une organisation créée) | −20 |
| Nom presque identique à une organisation ou à une autre demande en attente ou approuvée (casse, accents, ponctuation et mots génériques « ASBL », « école », « les », « du »… ignorés ; une coquille d'une lettre tolérée à partir de 6 lettres) | −10 |
| Email officiel déjà utilisé par une organisation | −15 |
| Autre demande en attente du même demandeur | −5 |
| Demandes de création refusées du même demandeur | −10 par refus, −20 au plus |
| Le demandeur a déjà une demande de création approuvée | +5 |

Le score est borné entre 0 et 100. L'email officiel (obligatoire) et l'état du compte du demandeur (actif, email vérifié : les routes l'exigent) ne rapportent aucun point : ils sont acquis à tout le monde et ne distinguent personne.

### Demande d'adhésion

Une demande complète et cohérente fait **100**, quel rôle est demandé.

| Critère | Points |
|---|---|
| Justificatif fourni | +35 |
| Justification fournie | +25 |
| Justification détaillée (au moins 30 caractères et 5 mots) | +15 |
| Cohérence rôle / justification : la justification contient au moins **un mot entier** du vocabulaire du rôle | +20 |
| Téléphone du demandeur renseigné | +5 |

Le vocabulaire (mots entiers, sans accent ni casse) exclut le **nom du rôle** : écrire « responsable » ne prouve rien pour le rôle « responsable », et « enfantine » ne contient pas le mot « enfant ». Exemples : `prof` → enseignant, classe, école, élèves, cours… ; `animateur` → animation, activité, atelier, enfants, stage… ; `directeur` → direction, établissement, école, équipe… ; `responsable` → coordination, gestion, équipe, comité, inscriptions… ; `referent` → contact, coordination, planning, suivi, liaison… (liste complète : `ROLE_VOCABULARY` dans `UserOrgRequestConfidenceScoreService`).

| Pénalité ou avertissement | Effet |
|---|---|
| Ni justification ni justificatif | −15 |
| Justification de moins de 15 caractères | −5 |
| Rôle sensible (directeur, responsable, référent) sans justificatif | −10 |
| Justification de moins de 4 mots (et d'au moins 15 caractères) | Avertissement sans point |

Contrôles de **l'état du moment**, évalués seulement pour une demande **en attente**, avertissements sans point :

| Situation | Avertissement |
|---|---|
| Responsable ou directeur demandé alors que l'organisation a déjà un responsable ou un directeur (autre que le demandeur) | « Rôle déjà tenu : l'organisation a déjà un responsable ou directeur : Prénom Nom (responsable). » : l'approbation ajouterait un second responsable |
| Même justification (casse, accents et ponctuation ignorés) qu'une autre demande du même demandeur sur les 90 derniers jours | « Même justification que la demande n°X du même demandeur. » |

Le score est borné entre 0 et 100. L'organisation cible et le rôle (champs obligatoires), le compte actif et l'email vérifié (les routes l'exigent) ne rapportent aucun point : ils sont acquis à tout le monde. L'agent garde la possibilité de fixer lui-même la note à l'approbation ou au refus (`confidence_note` du corps de la requête).

### Ce que renvoie l'API

| Champ | Demande en attente | Demande traitée (approuvée ou refusée) |
|---|---|---|
| `confidence_note` | Le score courant, toujours égal à `confidence_details.score` | La note **de la décision**, figée |
| `confidence_details` (`score`, `reasons`, `warnings`) | Calculé à chaque lecture | Recalculé à chaque lecture **à titre indicatif**, d'après l'état actuel des données |
| `confidence_level`, `confidence_level_label` | Niveau de la note affichée : `faible` (moins de 40), `moyen` (40 à 69), `eleve` (70 et plus) ; libellés « Faible », « Moyen », « Élevé » | Niveau de la note de la décision |

Pour une demande traitée, `confidence_details.score` peut donc différer de `confidence_note` : le refus supprime le justificatif (le score recalculé perd ses points), un compte ou une adresse a pu changer depuis. L'écart n'est pas une anomalie : la note de la décision fait foi. Pour une demande de création d'organisation traitée, les contrôles de l'état du moment (BCE ou nom déjà connus, email déjà utilisé, autre demande en attente, historique du demandeur) ne sont plus évalués : l'organisation créée par l'approbation porte les mêmes nom, email et numéro.

### Note enregistrée (`confidence_note`)

La colonne `confidence_note` est la seule valeur persistée (le détail ne l'est jamais). Elle trie les listes (`sort=confiance`), elle est donc tenue à jour pour une demande en attente :

| Moment | Effet |
|---|---|
| Création | Score calculé et enregistré |
| Justificatif ajouté ou supprimé (`POST` / `DELETE .../proof`) | Score recalculé et enregistré. La SPA envoie le justificatif **après** la création : sans cela, la note ignorerait ses points (+15 pour une organisation, +25 pour une adhésion) |
| Approbation ou refus | La note est figée. Adhésions : la note donnée par l'agent (`confidence_note` du corps), sinon le score du moment. Organisations : le score du moment, calculé avant la création de l'organisation et avant la suppression du justificatif d'un refus |

Les changements indirects (téléphone ou compte du demandeur, autre demande en attente du même demandeur, organisation créée entre-temps avec le même email) apparaissent immédiatement dans la note et le détail affichés. La note enregistrée — donc le tri — les rattrape par la commande `php artisan requests:refresh-confidence-notes`, planifiée chaque heure : elle ne recalcule que les demandes en attente (les demandes traitées gardent la note de leur décision). À lancer une fois après le déploiement de cette évolution : les demandes déjà en attente ont une note enregistrée calculée avant l'ajout de leur justificatif.

---

## API

```
GET    /api/organizations                     Liste (filtrée selon le rôle) : q, type, banned, sort, per_page
GET    /api/organizations/{id}               Détail
POST   /api/organizations                    Créer (agent_communal, admin)
PATCH  /api/organizations/{id}               Mettre à jour (agent_communal, admin)
POST   /api/organizations/{id}/ban           Bannir (admin)
POST   /api/organizations/{id}/unban         Débannir (admin)
GET    /api/organizations/types              Types, libellés et règles (config/reservations.php)
```

Liste (`ListOrganizationsRequest`, `OrganizationService::list`) :

| Paramètre | Valeurs |
|---|---|
| `q` | chaque mot dans le nom, l'email officiel ou le contact (casse et accents ignorés) |
| `type` | `ecole_communale`, `personnel_communal`, `cpas`, `asbl`, `externe` |
| `banned` | `0` ou `1` |
| `sort` | `nom` (défaut), `quota` (part du quota utilisée, la plus grande d'abord ; exemptées à la fin), `activite` (le plus de réservations à venir d'abord) |
| `per_page` | 1 à 100 (15 par défaut) |

Chaque organisation de la liste et du détail renvoie aussi `members_count`, `upcoming_reservations_count`, `quota` et
`rules` (`priority`, `auto_approve`, `quota_exempt`, `council_required`, `recurrences_allowed`). Le justificatif est
`proof_document_id` (document existant). Création et modification sont tracées (`organization.created`,
`organization.updated` avec les champs changés).

---

## Files de demandes (page agent « Demandes »)

`GET /api/user-org-requests` (adhésions) et `GET /api/org-registration-requests` (nouvelles organisations) acceptent des filtres facultatifs, combinables et conservés dans les liens de pagination. Le périmètre visible ne change pas (un utilisateur voit ses demandes ; pour les adhésions, aussi celles des organisations qu'il gère). Valeur invalide : 422 en français.

| Paramètre | Valeurs | Adhésions | Nouvelles organisations |
|---|---|---|---|
| `statuses[]` | `en_attente`, `approuve`, `refuse` (plusieurs possibles) | ✓ | ✓ |
| `q` | Chaque mot doit apparaître (casse et accents ignorés). Adhésions : nom de l'organisation, prénom / nom / email du demandeur. Nouvelles organisations : `organization_name`, `official_email`, `enterprise_number`, prénom / nom / email du demandeur | ✓ | ✓ |
| `organization_id` | id d'une organisation | ✓ | — |
| `organization_type` | `ecole_communale`, `personnel_communal`, `cpas`, `asbl`, `externe` | — | ✓ |
| `sort` | `confiance` (défaut : note décroissante, puis plus récentes), `anciennete` (plus anciennes d'abord : ordre d'arrivée de la file « à traiter »), `recentes` | ✓ | ✓ |
| `per_page` | 1 à 100 (défaut 15) | ✓ | ✓ |

Compteur de la pastille « à traiter » : `meta.total` de `GET /api/user-org-requests?statuses[]=en_attente&per_page=1` (idem pour les nouvelles organisations). Aucune permission `dashboard.view` n'est requise, contrairement à `GET /api/dashboard`.

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
| `reservations_used_this_year` | int | Ancien compteur, **plus utilisé** (le quota se compte : voir « Quota annuel ») |
| `quota_reset_year` | timestamp | Ancien horodatage de remise à zéro, **plus utilisé** |
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