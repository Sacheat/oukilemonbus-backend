# Matrice des droits des policies

Ce document definit les droits metier a encoder dans les policies Laravel.

Il complete les permissions Spatie existantes :

- Spatie verifie le droit global : `reservations.update`, `users.update`, etc.
- Une policy verifie le droit sur une ressource precise : cette reservation, cet utilisateur, cette organisation, etc.

> **Mise à jour (septembre 2026)** : le rôle global responsable de l'organisation a été supprimé. Être responsable d'une organisation dépend désormais de la relation avec chaque organisation (`relationship_type` = `responsable` ou `directeur`). Dans ce document, « responsable » désigne donc un responsable **de l'organisation concernée**. Référence à jour : [`access-control.md`](access-control.md).

## Portee recommandee

Pour la V1, il n'est pas necessaire de creer une policy pour chaque modele.

Policies a creer maintenant :

- `ReservationPolicy` : prioritaire, car les droits dependent du demandeur, de l'organisation, du chauffeur assigne, du statut et du workflow.
- `CouncilDecisionPolicy` : utile, car la decision du college est une ressource metier autonome avec ses propres routes et droits.
- `UserPolicy` : utile, car un utilisateur peut modifier son propre profil mais pas forcement celui des autres.
- `OrganizationPolicy` : utile, car les responsables d'organisation doivent etre limites a leurs organisations.
- `UnavailabilityPolicy` : utile, car les indisponibilites peuvent concerner un chauffeur, un vehicule ou un createur.
- `OrgRegistrationRequestPolicy` : utile, car une demande peut etre visible par son createur ou par les agents qui la traitent.
- `UserOrgRequestPolicy` : utile, car une demande de rattachement peut etre visible par son createur, par les responsables de l'organisation ou par les agents.

Policies non prioritaires pour l'instant :

- `VehiclePolicy` : Spatie suffit tant que les vehicules sont geres globalement par admin / agent communal.
- `DriverPolicy` : Spatie suffit tant que les chauffeurs sont geres globalement par admin / agent communal.
- `LocationPolicy` : Spatie suffit tant que les lieux ne sont pas rattaches a une organisation proprietaire.
- `MunicipalityPolicy` et `MunicipalityLocalityPolicy` : Spatie suffit pour la V1.
- Historiques et notifications : acces indirect via la ressource parente.

## Permissions reservations a ajouter

Permissions Spatie proposees :

- `reservations.view`
- `reservations.create`
- `reservations.update`
- `reservations.approve`
- `reservations.reject`
- `reservations.cancel`
- `reservations.assign`
- `reservations.confirm`
- `reservations.manage-conflicts`
- `council-decisions.view`
- `council-decisions.create`
- `council-decisions.update`

`reservations.validate` n'est pas retenu pour la V1 afin d'eviter la confusion avec la validation de formulaire. Le terme metier retenu est `approve`.

## Attribution des permissions reservations

| Role | Permissions |
| --- | --- |
| `admin` | Toutes les permissions `reservations.*` et `council-decisions.*` |
| `agent_communal` | Toutes les permissions `reservations.*` et `council-decisions.*` |
| `user` | `reservations.view`, `reservations.create`, `reservations.update`, `reservations.cancel`, `council-decisions.view` |
| `chauffeur` | `reservations.view`, `council-decisions.view` |

Important : donner `reservations.view` a un role ne signifie pas que ce role voit toutes les reservations. La policy et les filtres de requete limitent le perimetre.

## ReservationPolicy

### Regles generales

Statuts actifs :

- `en_attente`
- `en_conflit`
- `approuve`
- `confirme`

Statuts finaux :

- `refuse`
- `annule`

Pour la V1, `confirme` est considere actif mais beaucoup d'actions metier seront limitees dessus.

### Matrice fonctionnelle

| Action | Admin | Agent communal | Responsable organisation | User | Chauffeur |
| --- | --- | --- | --- | --- | --- |
| Voir la liste | Oui, tout | Oui, tout | Oui, organisations rattachees | Oui, reservations creees par lui | Oui, planning assigne |
| Voir une reservation | Oui | Oui | Oui si organisation rattachee | Oui si createur | Oui si chauffeur assigne |
| Preview creation | Oui | Oui | Oui | Oui | Non |
| Creer | Oui, pour toute organisation | Oui, pour toute organisation | Oui, pour organisation rattachee | Oui, pour organisation rattachee | Non |
| Modifier | Oui, si non finale | Oui, si non finale | Oui, si organisation rattachee et statut modifiable | Oui, si createur et statut modifiable | Non |
| Approuver | Oui | Oui | Non | Non | Non |
| Refuser | Oui | Oui | Non | Non | Non |
| Annuler | Oui, si active | Oui, si active | Oui, si organisation rattachee et active | Oui, si createur et active | Non |
| Assigner chauffeur / vehicule | Oui | Oui | Non | Non | Non |
| Confirmer | Oui | Oui | Non | Non | Non |
| Enregistrer decision college | Oui | Oui | Non | Non | Non |
| Voir conflits | Oui | Oui | Non en V1 | Non en V1 | Non |
| Resoudre conflits | Oui | Oui | Non | Non | Non |

### Methodes a prevoir

- `viewAny(User $user): bool`
- `view(User $user, Reservation $reservation): bool`
- `preview(User $user): bool`
- `create(User $user): bool`
- `createForOrganization(User $user, Organization $organization): bool`
- `update(User $user, Reservation $reservation): bool`
- `approve(User $user, Reservation $reservation): bool`
- `reject(User $user, Reservation $reservation): bool`
- `cancel(User $user, Reservation $reservation): bool`
- `assign(User $user, Reservation $reservation): bool`
- `confirm(User $user, Reservation $reservation): bool`
- `viewConflicts(User $user, Reservation $reservation): bool`
- `resolveConflict(User $user, Reservation $reservation): bool`

### Details des regles

`viewAny`

- Autorise tout utilisateur ayant `reservations.view`.
- Ne suffit pas a filtrer les donnees retournees.
- Le service ou le query builder doit appliquer un filtre `visibleTo($user)`.

`view`

- `admin` et `agent_communal` : toutes les reservations.
- responsable de l'organisation : reservations des organisations auxquelles il est rattache.
- `user` : reservations dont `requested_by_user_id` vaut son id.
- `chauffeur` : reservations dont `driver_id` correspond a son profil chauffeur.

`preview`

- Autorise `admin`, `agent_communal`, responsable de l'organisation et `user`.
- Refuse `chauffeur`.
- Les utilisateurs non internes doivent preview uniquement pour une organisation rattachee.

`create`

- Autorise tout utilisateur ayant `reservations.create`.
- La verification de l'organisation cible doit etre faite par `createForOrganization` ou dans le service.

`createForOrganization`

- `admin` et `agent_communal` : toute organisation.
- responsable de l'organisation et `user` : uniquement une organisation rattachee via `user_organization`.
- `chauffeur` : refuse.

`update`

- Requiert `reservations.update`.
- Refuse si la reservation est `refuse` ou `annule`.
- `admin` et `agent_communal` : autorises tant que la reservation n'est pas finale.
- responsable de l'organisation : autorise si l'organisation est rattachee et si le statut est `en_attente` ou `en_conflit`.
- `user` : autorise si createur et si le statut est `en_attente` ou `en_conflit`.
- `chauffeur` : refuse.

`approve`

- Requiert `reservations.approve`.
- Autorise seulement `admin` et `agent_communal`.
- Autorise uniquement si le statut est `en_attente` ou `en_conflit`.
- Pour les organisations non ecoles communales, le service doit verifier la decision favorable du college.

`reject`

- Requiert `reservations.reject`.
- Autorise seulement `admin` et `agent_communal`.
- Refuse si la reservation est deja `refuse`, `annule` ou `confirme`.
- La raison de refus est obligatoire dans la request.

`cancel`

- Requiert `reservations.cancel`.
- Autorise seulement sur reservation active.
- `admin` et `agent_communal` : toutes les reservations actives.
- responsable de l'organisation : reservations actives des organisations rattachees.
- `user` : reservations actives creees par lui.
- `chauffeur` : refuse.
- La raison d'annulation est obligatoire dans la request.

`assign`

- Requiert `reservations.assign`.
- Autorise seulement `admin` et `agent_communal`.
- Refuse si le statut est `refuse` ou `annule`.
- Le service verifie la disponibilite chauffeur / vehicule et cree l'historique.

`confirm`

- Requiert `reservations.confirm`.
- Autorise seulement `admin` et `agent_communal`.
- Autorise seulement si le statut est `approuve`.
- Le service verifie la presence d'un chauffeur, d'un vehicule et l'absence de conflit bloquant non resolu.

`viewConflicts`

- Requiert `reservations.manage-conflicts`.
- Autorise seulement `admin` et `agent_communal` en V1.

`resolveConflict`

- Requiert `reservations.manage-conflicts`.
- Autorise seulement `admin` et `agent_communal`.
- Autorise seulement sur reservation active.

`trip-report` (compte rendu de trajet, TripReportService, pas de Policy dédiée)

- Voir : `trip-reports.manage`, ou `trip-reports.submit` et être le chauffeur affecté (403 sinon).
- Déclarer ou corriger : réservation `confirme` (422 sinon) ; `trip-reports.manage` dès l'heure de départ, sans délai ; `trip-reports.submit` pour le chauffeur affecté, de l'heure de départ jusqu'au lendemain 12:00, heure de Bruxelles (422 avant ou après ; avant le départ, 422 aussi pour un agent).

`trip-events` (étapes du trajet, TripProgressService, pas de Policy dédiée)

- Voir : `trip-progress.manage`, ou `trip-progress.record` et être le chauffeur affecté (403 sinon) ; la liste
  `GET /trip-progress` est limitée aux trajets du chauffeur.
- Enregistrer : réservation `confirme` et pas déclarée non réalisée (422 sinon), étapes dans l'ordre (422 sinon) ;
  chauffeur affecté : premier départ à partir de N minutes avant l'heure prévue (config) jusqu'à la fin du jour, autres
  étapes jusqu'au lendemain 12:00 (422 sinon) ; `trip-progress.manage` sans contrainte d'heure.

`driver-alerts` (demandes d'aide, DriverAlertService, pas de Policy dédiée)

- Créer, renvoyer sa position : `driver-alerts.create`, profil chauffeur ; le trajet donné doit être une de ses missions
  (422 sinon) ; position seulement sur une alerte non clôturée de ce chauffeur (403 / 422).
- Voir : `driver-alerts.manage` (toutes), sinon ses propres alertes (403).
- Prendre en charge (alerte ouverte), clôturer (note obligatoire) : `driver-alerts.manage`.

### Filtre de liste obligatoire

La route `GET /api/reservations` ne doit jamais retourner toutes les reservations puis compter sur les resources pour masquer les donnees.

Regles de filtrage :

- `admin` et `agent_communal` : toutes les reservations.
- `chauffeur` : reservations ou `driver_id` vaut l'id de son profil chauffeur.
- responsable de l'organisation : reservations des organisations rattachees.
- `user` : reservations creees par lui.

Si un utilisateur a plusieurs roles, appliquer le perimetre le plus large autorise par ses roles.

## UserPolicy

## CouncilDecisionPolicy

Permissions Spatie existantes :

- `council-decisions.view`
- `council-decisions.create`
- `council-decisions.update`

| Action | Admin | Agent communal | Responsable organisation | User | Chauffeur |
| --- | --- | --- | --- | --- | --- |
| Voir la liste | Oui, tout | Oui, tout | Oui, si reservation visible | Oui, si reservation visible | Oui, si reservation visible |
| Voir une decision | Oui | Oui | Oui, si reservation visible | Oui, si reservation visible | Oui, si reservation visible |
| Creer | Oui | Oui | Non | Non | Non |
| Modifier | Oui | Oui | Non | Non | Non |

Regles recommandees :

- `viewAny` : requiert `council-decisions.view`.
- `view` : internes voient tout ; sinon, reutiliser le meme perimetre fonctionnel que la reservation liee.
- `create` : requiert `council-decisions.create`, reserve aux roles internes, seulement pour une reservation d'organisation non `ecole_communale`.
- `update` : requiert `council-decisions.update`, reserve aux roles internes, avec la meme contrainte de perimetre metier que la creation.

Details metier :

- Une reservation ne peut avoir qu'une seule `council_decision`.
- La decision du college reste autonome cote API, mais elle peut produire des effets sur la reservation liee.
- Une decision `approuvee` peut lever les conflits de derogation prevus par le reglement.
- Une decision `refusee` entraine le refus de la reservation.

Permissions Spatie existantes :

- `users.view`
- `users.create`
- `users.update`
- `users.update-status`
- `users.deactivate`
- `users.send-password-reset`

| Action | Admin | Agent communal | Responsable organisation | User | Chauffeur |
| --- | --- | --- | --- | --- | --- |
| Voir liste utilisateurs | Oui | Oui | Limite, a definir | Non sauf soi | Non sauf soi |
| Voir un utilisateur | Oui | Oui | Oui si meme organisation | Oui si soi | Oui si soi |
| Creer | Oui | Oui | Non | Non | Non |
| Modifier profil | Oui | Oui | Non sauf soi | Oui si soi | Oui si soi |
| Modifier statut | Oui | Oui | Non | Non | Non |
| Desactiver | Oui | Oui | Non | Non | Non |
| Modifier mot de passe | Oui si admin ou soi | Oui si agent ou soi | Oui si soi | Oui si soi | Oui si soi |

Regles recommandees :

- `viewAny` : `users.view`, mais filtrage obligatoire si on expose la liste a des roles non internes.
- `view` : internes voient tout ; un utilisateur voit son propre compte ; un responsable peut voir les utilisateurs rattaches a ses organisations.
- `update` : internes ou utilisateur lui-meme.
- `updateStatus` et `deactivate` : internes uniquement.
- `updatePassword` : utilisateur lui-meme, ou interne si le cas metier est confirme.

## OrganizationPolicy

Permissions Spatie existantes :

- `organizations.view`
- `organizations.create`
- `organizations.update`
- `organizations.ban` — **admin uniquement**

| Action | Admin | Agent communal | Responsable organisation | User | Chauffeur |
| --- | --- | --- | --- | --- | --- |
| Voir liste organisations | Oui | Oui | Organisations rattachees | Organisations rattachees | Organisations rattachees si pertinent |
| Voir organisation | Oui | Oui | Oui si rattache | Oui si rattache | Oui si rattache si pertinent |
| Creer | Oui | Oui | Non, passer par demande d'enregistrement | Non, passer par demande d'enregistrement | Non |
| Modifier | Oui | Oui | Non en V1 | Non | Non |
| Bannir / debannir | Oui | Non | Non | Non | Non |

Regles recommandees :

- `viewAny` : `organizations.view`.
- `view` : internes voient tout ; autres utilisateurs voient seulement les organisations auxquelles ils sont rattaches.
- `create` et `update` : admin / agent communal uniquement.
- `ban` / `unban` : admin uniquement (`organizations.ban`). Un agent communal ne peut pas bannir une organisation.

## UnavailabilityPolicy

Permissions Spatie existantes :

- `unavailabilities.view`
- `unavailabilities.create`
- `unavailabilities.update`
- `unavailabilities.cancel`

| Action | Admin | Agent communal | Responsable organisation | User | Chauffeur |
| --- | --- | --- | --- | --- | --- |
| Voir liste | Oui | Oui | Non en V1 | Non | Ses indisponibilites chauffeur |
| Voir une indisponibilite | Oui | Oui | Non en V1 | Non | Oui si elle concerne son driver |
| Creer | Oui | Oui | Non | Non | Oui pour son driver si autorise |
| Modifier | Oui | Oui | Non | Non | Oui si creee par lui et passee non active/non terminee a definir |
| Annuler | Oui | Oui | Non | Non | Oui si creee par lui et encore active |

Regles recommandees :

- Les indisponibilites vehicule restent internes.
- Les indisponibilites chauffeur peuvent etre creees par le chauffeur pour lui-meme si le produit le souhaite.
- Sinon, pour la V1, garder create/update/cancel aux `admin` et `agent_communal`, et limiter `chauffeur` a la lecture de ses propres indisponibilites.

## OrgRegistrationRequestPolicy

Permissions Spatie existantes :

- `org-registration-requests.view`
- `org-registration-requests.create`
- `org-registration-requests.review`

| Action | Admin | Agent communal | Responsable organisation | User | Chauffeur |
| --- | --- | --- | --- | --- | --- |
| Voir liste | Oui | Oui | Ses demandes | Ses demandes | Ses demandes |
| Voir une demande | Oui | Oui | Oui si createur | Oui si createur | Oui si createur |
| Creer | Oui | Oui | Oui | Oui | Oui |
| Approuver | Oui | Oui | Non | Non | Non |
| Refuser | Oui | Oui | Non | Non | Non |

Regles recommandees :

- `viewAny` : utilisateurs avec `org-registration-requests.view` voient toutes les demandes ; sinon uniquement leurs propres demandes si une route le permet.
- `view` : internes ou createur.
- `create` : tout utilisateur authentifie avec `org-registration-requests.create`.
- `approve` / `reject` : `admin` et `agent_communal`, statut `en_attente` uniquement.

## UserOrgRequestPolicy

Permissions Spatie existantes :

- `user-org-requests.view`
- `user-org-requests.create`
- `user-org-requests.review`

| Action | Admin | Agent communal | Responsable organisation | User | Chauffeur |
| --- | --- | --- | --- | --- | --- |
| Voir liste | Oui | Oui | Demandes de ses organisations | Ses demandes | Ses demandes |
| Voir une demande | Oui | Oui | Oui si organisation rattachee | Oui si createur | Oui si createur |
| Creer | Oui | Oui | Oui | Oui | Oui |
| Approuver | Oui | Oui | A definir, non en V1 | Non | Non |
| Refuser | Oui | Oui | A definir, non en V1 | Non | Non |

Regles recommandees :

- `viewAny` : internes voient tout ; responsables voient les demandes liees a leurs organisations ; utilisateurs voient leurs propres demandes.
- `view` : meme logique que `viewAny` mais sur une demande precise.
- `create` : tout utilisateur authentifie avec `user-org-requests.create`.
- `approve` / `reject` : `admin` et `agent_communal`, statut `en_attente` uniquement.
- La possibilite pour un responsable de l'organisation d'approuver une demande de rattachement est reportee apres la V1.

## Ressources sans policy dediee en V1

`Vehicle`, `Driver`, `Location`, `Municipality` et `MunicipalityLocality` peuvent rester proteges par middleware Spatie tant que :

- ils ne sont pas rattaches a une organisation proprietaire ;
- les droits ne dependent pas de l'objet precis ;
- seuls `admin` et `agent_communal` modifient ces ressources ;
- les autres roles ont au maximum un droit de lecture global.

Une policy devra etre ajoutee plus tard si une regle devient contextuelle.

Exemples :

- un chauffeur peut modifier uniquement sa propre fiche chauffeur ;
- une organisation peut gerer ses propres lieux ;
- une commune peut voir uniquement ses localites ;
- un vehicule appartient a une entite donnee.

## Convention d'implementation

Routes :

- Garder `auth:sanctum` pour toutes les routes authentifiees.
- Garder les middlewares Spatie pour les droits globaux simples.
- Appeler les policies dans les controllers ou les FormRequests.

Controllers / FormRequests :

- Pour une creation : `authorize('create', Reservation::class)`.
- Pour une action sur ressource : `authorize('update', $reservation)`.
- Pour une action custom : `authorize('approve', $reservation)`, `authorize('cancel', $reservation)`, etc.

Services :

- Les services appliquent le workflow metier.
- Les services ne doivent pas remplacer les policies, mais ils peuvent refaire des protections critiques si l'action est appelee hors HTTP.

Tests :

- Chaque policy importante doit avoir des tests pour les roles autorises et refuses.
- Les tests API doivent verifier qu'un utilisateur ne peut pas acceder a une ressource d'une autre organisation.
- Les tests de liste doivent verifier le filtrage, pas seulement le code HTTP.
