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
`config/privacy.php` ; 0 désactive une purge.

| Donnée | Durée par défaut | Variable | Effet |
|---|---|---|---|
| Positions GPS des chauffeurs | 90 jours | `PRIVACY_RETENTION_DRIVER_POSITIONS_DAYS` | `latitude`, `longitude`, `accuracy_m`, `position_captured_at` vidés dans `trip_events` (d'après `occurred_at`) et dans les `driver_alerts` clôturées (d'après `closed_at`). `position_state`, le dernier lieu connu et les tracés prévus (`trip_routes`) restent |

Une alerte purgée affiche « Position non disponible (Envoyée) » et sa carte retombe sur le dernier lieu connu.

Autres durées existantes : notifications (`config/notifications.php`), logs fichiers (`LOG_DAILY_DAYS`, 14 j),
backups (`BACKUP_RETENTION_DAYS`, 14 j), Inspector (14 j), justificatifs orphelins (7 j).
