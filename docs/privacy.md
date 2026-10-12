# RGPD : droits des personnes et conservation

Ce que le backend fait pour le RGPD. Le registre des traitements, la politique de confidentialité, l'AIPD
sur la géolocalisation des chauffeurs et les durées exactes restent à la charge de la commune et de son DPO.

---

## Effacement : anonymisation d'un compte (art. 17)

`PATCH /api/users/{user}/anonymize` (admin, `users.anonymize`, jamais sur son propre compte). Irréversible.
Service : `UserAnonymizationService`.

Un compte n'est **jamais supprimé** : les réservations, historiques, comptes rendus et documents générés
(feuilles de route, délibérations) relèvent d'obligations légales de conservation (art. 17.3.b). Ils restent et
pointent vers « Utilisateur supprimé #id ».

| Donnée | Traitement |
|---|---|
| Identité (`users`) | prénom `Utilisateur`, nom `supprimé #id`, email `deleted-{id}@anonymized.invalid`, téléphone vidé, mot de passe aléatoire, `active = false`, `email_verified_at` vidé, `anonymized_at` renseigné |
| Profil chauffeur | `licence_number` → `ANON-{id du chauffeur}`, `is_active = false` |
| Accès | tokens Sanctum et sessions supprimés |
| Appartenances et rôles | retirés |
| Notifications | in-app, préférences et file de digest supprimées ; `recipient_email` et `payload` des livraisons vidés |
| Demandes d'adhésion et d'inscription | celles en attente sont refusées (« Compte supprimé à la demande de la personne (RGPD). ») ; justification, contact, téléphone et commentaire vidés ; justificatifs supprimés, sauf celui devenu le justificatif d'une organisation |
| Divers | clés d'idempotence supprimées ; responsable du planning hebdo retiré ; événement `user.anonymized` dans `domain_events` |

**Refus (422, champ `user`, liste de messages)** : compte déjà anonymisé, dernier administrateur actif,
réservations à venir demandées par le compte, trajets à venir affectés au chauffeur, demande d'aide encore ouverte.
L'agent annule ou réaffecte d'abord. Un compte anonymisé ne peut plus être réactivé (`PATCH /users/{user}/status`
→ 422 sur `active`).

Non touché : les entrées de l'Inspector (Tooling), purgées après `tooling.inspector.retention_days`, et les
coordonnées de l'organisation (`organizations.name_contact`, `phone_contact`, `official_email`), qui appartiennent
à l'organisation : l'agent les corrige si la personne en était le contact.

## Accès et portabilité : export (art. 15 et 20)

- `GET /api/auth/me/personal-data` : ses propres données (tout compte connecté, même email non vérifié).
- `GET /api/users/{user}/personal-data` : un autre compte (admin, `users.export-data`).

Réponse JSON (`data`, en-tête `Content-Disposition: attachment`), 20 requêtes par minute au plus. Service :
`PersonalDataExportService`. Sections : `profile`, `organizations`, `notification_preferences`, `api_tokens`,
`reservations_requested`, `membership_requests`, `organization_registration_requests`, `in_app_notifications`,
`document_access_logs`, et `driver` (profil, étapes de trajet avec positions, alertes, comptes rendus,
indisponibilités, trajets affectés) ou `null`.

**Une nouvelle table qui porte des données d'un utilisateur doit être ajoutée à l'export et à l'anonymisation.**

## Conservation

`php artisan privacy:prune [--dry-run]`, planifiée chaque nuit à 02:45 (Europe/Brussels). Durées dans
`config/privacy.php` ; 0 désactive une purge. Valeurs à valider avec le DPO.

| Donnée | Défaut | Variable | Effet |
|---|---|---|---|
| Positions GPS des chauffeurs | 90 j | `PRIVACY_RETENTION_DRIVER_POSITIONS_DAYS` | `latitude`, `longitude`, `accuracy_m`, `position_captured_at` vidés dans `trip_events` (d'après `occurred_at`) et dans les `driver_alerts` clôturées (d'après `closed_at`). `position_state`, le dernier lieu connu et les tracés prévus (`trip_routes`) restent |
| Clés d'idempotence (réponses rejouables) | 7 j | `PRIVACY_RETENTION_IDEMPOTENCY_KEYS_DAYS` | lignes supprimées |
| Journal d'accès aux documents (IP, navigateur) | 1 an | `PRIVACY_RETENTION_DOCUMENT_ACCESS_LOGS_DAYS` | lignes supprimées |
| Journal d'audit `domain_events` | 3 ans | `PRIVACY_RETENTION_DOMAIN_EVENTS_DAYS` | lignes supprimées |
| Comptes inactifs | 3 ans | `PRIVACY_RETENTION_INACTIVE_ACCOUNTS_DAYS` | voir ci-dessous |
| Listes des passagers (carnet de bord, noms des passagers) | jusqu'au 31/12 de la 3e année après l'année du trajet | `PASSENGER_LIST_RETENTION_YEARS` (années) | fichier et document supprimés par `documents:purge-passenger-lists` (03:15, avant le backup) ; durée prise sur l'arrêté royal du 22 mai 2014, art. 2 § 4 (feuilles de route), à confirmer pour un transport pour compte propre (`docs/documents.md`) |

Une alerte purgée affiche « Position non disponible (Envoyée) » et sa carte retombe sur le dernier lieu connu.

**Comptes inactifs.** L'inactivité part de `users.last_seen_at`, mis à jour au plus une fois par jour par toute
requête authentifiée (`EnsureUserIsActive`) ; sans activité connue, `created_at` fait foi.
`PRIVACY_INACTIVE_ACCOUNTS_WARNING_DAYS` (30) jours avant le délai, la personne reçoit l'email
`InactiveAccountWarning` (« se connecter suffit ») et `inactivity_warned_at` est renseigné ; un compte désactivé est
marqué sans email. Toute activité annule l'avertissement. Le délai passé **et** l'avertissement vieux d'au moins le
préavis, le compte est anonymisé (même service, sans auteur : `actor_user_id` null, `context.reason = inactivity`).
Un compte encore engagé (réservation ou mission à venir, alerte ouverte, dernier admin) est sauté et journalisé
(`privacy.inactive_account.skipped`).

**Tokens Sanctum** : expiration de 7 jours (`SANCTUM_TOKEN_EXPIRATION`, minutes), purge quotidienne
`sanctum:prune-expired`. La SPA utilise la session (purgée par Laravel après `SESSION_LIFETIME`).

**Hors purge automatique** : les réservations, adresses, comptes rendus et documents générés. Leur durée relève des
archives communales et doit être fixée par le DPO avant toute purge.

Autres durées existantes : notifications (`config/notifications.php`), logs fichiers (`LOG_DAILY_DAYS`, 14 j),
backups (`BACKUP_RETENTION_DAYS`, 14 j, puis `OFFSITE_RETENTION_DAYS` sur la copie distante), Inspector (14 j),
justificatifs orphelins (7 j). Une donnée effacée reste dans les backups jusqu'à leur expiration (voir
[`backups.md`](backups.md)).

## Minimisation

- `DriverResource` : permis (`licence_number`, `licence_expiry_date`) et email du chauffeur réservés à un admin, un
  agent et au chauffeur lui-même. Les membres d'organisation et les autres chauffeurs voient le nom et le téléphone.
- Délibérations : le contexte gardé avec le PDF ne contient que le nom du chauffeur.

- Membres d'une organisation (`GET /organizations/{id}/members`) : email et téléphone renvoyés aux responsables de
  l'organisation, aux admins et agents, et au membre lui-même. Un simple membre voit ceux des responsables (pour les
  joindre) ; pour les autres membres, `email` et `phone` valent `null`.

## Textes libres (données sensibles)

Les champs libres peuvent recevoir, par maladresse, des données de santé ou d'autres données sensibles (art. 9 :
« hospitalisé », « enceinte »…). L'API ne peut pas les détecter : **la SPA affiche sous chaque champ la consigne
« Pas d'information médicale ni personnelle : un motif court suffit (ex. "absent", "congé") »**.

| Champ | Saisi par | Vu par |
|---|---|---|
| `unavailabilities.reason` | agent | agents, admins, le chauffeur concerné |
| `driver_alerts.message`, `closing_note` | chauffeur, agent | agents, admins ; le message part aussi au contact de garde |
| `trip_reports.remarks` | chauffeur, agent | chauffeur du trajet, agents, admins |
| `reservations.rejection_reason`, `cancellation_reason` ; `reason` des historiques | agent, demandeur | le demandeur et son organisation ; envoyé par email |
| `reservations.trip_purpose` | demandeur | organisation, agents ; feuilles de route |
| `user_org_requests.justification`, `org_registration_requests.comment` | demandeur | agents (et responsables pour les adhésions) |

Ces textes suivent la conservation de leur ligne (voir plus haut) ; l'anonymisation d'un compte vide la
justification et le commentaire de ses demandes, pas les motifs des réservations (ils concernent l'organisation).

## Comptes et sessions

- Mots de passe (inscription, création par un admin, changement, réinitialisation) : 12 caractères au moins, avec
  lettres et chiffres. En production, un mot de passe présent dans une fuite connue est refusé (Have I Been Pwned,
  voir [`sous-traitants.md`](sous-traitants.md)). Les comptes existants ne sont pas forcés à changer.
- Sessions : contenu chiffré en base (`SESSION_ENCRYPT`) et cookie `Secure`, par défaut en production. Passer
  `SESSION_ENCRYPT` à `true` sur une instance existante déconnecte une fois les sessions ouvertes.

## Politique de confidentialité (art. 13)

La page vit dans la SPA (`PRIVACY_POLICY_URL`, défaut `{FRONTEND_URL}/confidentialite`) ; le backend garde la version
lue par chacun (`users.privacy_policy_version`, `privacy_policy_accepted_at`). Ce n'est **pas un consentement** :
les traitements reposent sur la mission de service public de la commune, rien n'est bloqué.

- `GET /api/privacy-policy` (public) : `{ version, url }`, pour le lien du formulaire d'inscription.
- `POST /api/auth/register` exige `privacy_policy_accepted: true` (422 sinon) et enregistre la version en vigueur.
- `/auth/me`, login et inscription renvoient `privacy_policy { version, url, accepted_version, accepted_at, must_accept }`
  pour son propre compte uniquement. `must_accept` vrai : la SPA affiche la politique (comptes antérieurs, comptes
  créés par un admin, nouvelle version).
- `POST /api/auth/me/privacy-policy { version }` : enregistre la lecture ; 422 sur `version` si ce n'est pas la
  version en vigueur (page périmée).
- Nouvelle version : changer `PRIVACY_POLICY_VERSION` (ex. la date) redemande la lecture à tous.

## Backups

Chiffrés (libsodium), planifiés chaque nuit et refusés en clair en production ; copie hors VM par
`deploy/backup-offsite.sh`. Voir [`backups.md`](backups.md) et `deploy/README.md`.

## Tiers

Ce qui part vers OpenRouteService, Brevo, GeoNames, le contact de garde, etc. : [`sous-traitants.md`](sous-traitants.md).
