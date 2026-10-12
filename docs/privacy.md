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

## Backups

Chiffrés (libsodium), planifiés chaque nuit et refusés en clair en production ; copie hors VM par
`deploy/backup-offsite.sh`. Voir [`backups.md`](backups.md) et `deploy/README.md`.

## Tiers

Ce qui part vers OpenRouteService, Brevo, GeoNames, le contact de garde, etc. : [`sous-traitants.md`](sous-traitants.md).
