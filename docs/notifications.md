# Notifications

Ce document décrit le système de notifications du backend : canaux, priorités, digest, quiet hours, déduplication et observabilité.

---

## Architecture

Le système repose sur deux couches :

1. **Table `notifications`** (native Laravel) — canal in-app. Chaque notification est une ligne avec un payload JSON.
2. **Table `notification_deliveries`** — journal d'audit de chaque tentative d'envoi (in-app ou email), avec statut, provider, erreurs et métadonnées.

Les deux tables sont indépendantes : `notification_deliveries` peut logger un envoi email sans qu'une entrée `notifications` existe.

---

## Canaux

| Canal | Description |
|---|---|
| `database` | In-app — persisté dans `notifications`, lu via API |
| `mail` | Email via le provider configuré (`MAIL_MAILER`) |

---

## Priorités

Chaque notification a une priorité qui détermine la queue utilisée :

| Priorité | Queue | Usage |
|---|---|---|
| `critical` | `critical` | Annulation de dernière minute, incident urgent |
| `high` | `default` | Confirmation, approbation d'une réservation |
| `normal` | `default` | Mises à jour standard |
| `low` | `low` | Digest, rapports non urgents |

La queue worker est configurée via `QUEUE_WORKER_QUEUES=critical,default,low`.

---

## Déduplication

Chaque livraison a un `dedupe_key` unique (index unique en base). Si une même notification est déclenchée plusieurs fois pour le même événement et le même destinataire, les doublons sont silencieusement ignorés.

L'`event_id` (UUID) regroupe toutes les livraisons issues d'un même événement métier. L'`event_key` est une clé lisible permettant d'identifier le type d'événement.

---

## Digest

Les utilisateurs peuvent configurer une fréquence de digest (`digest_frequency`) :

| Valeur | Comportement |
|---|---|
| `none` | Pas de digest, notifications envoyées immédiatement |
| `daily` | Regroupement quotidien |
| `weekly` | Regroupement hebdomadaire |

Les notifications en attente de digest sont stockées dans `notification_digest_queues` jusqu'à l'envoi par le scheduler.

---

## Quiet hours

Chaque utilisateur peut définir une plage horaire silencieuse :

- `quiet_hours_start` : heure de début (0-23)
- `quiet_hours_end` : heure de fin (0-23)

Pendant cette plage, les notifications non critiques sont mises en queue et envoyées après la plage.

---

## Demandes d'aide des chauffeurs

Catégorie `driver_alert` (`driver_alert.created` pour les agents et admins, `driver_alert.taken` pour le chauffeur),
envoyée avec `urgent: true` : pas de récapitulatif ni de délai anti-spam, et aucune préférence ne la coupe (la
catégorie n'a pas de colonne dans `user_notification_preferences`). Le contact de garde de la commune, qui n'a pas
forcément de compte, reçoit `DriverAlertOnCallMail` (même gabarit). Voir `docs/driver_journey.md`.

## Préférences utilisateur

```
GET  /api/notifications/preferences
PATCH /api/notifications/preferences
```

Structure des préférences (table `user_notification_preferences`) :

| Champ | Type | Description |
|---|---|---|
| `notify_reservation_updates_in_app` | boolean | Mises à jour réservations in-app |
| `notify_reservation_updates_email` | boolean | Mises à jour réservations email |
| `notify_driver_planning_in_app` | boolean | Planning chauffeur in-app |
| `notify_driver_planning_email` | boolean | Planning chauffeur email |
| `notify_admin_workflow_in_app` | boolean | Workflow admin in-app |
| `notify_admin_workflow_email` | boolean | Workflow admin email |
| `notify_last_minute_cancellation_in_app` | boolean | Annulation dernière minute in-app |
| `notify_last_minute_cancellation_email` | boolean | Annulation dernière minute email |
| `digest_frequency` | varchar | `none`, `daily`, `weekly` |
| `quiet_hours_start` | tinyint | Heure début (0-23) |
| `quiet_hours_end` | tinyint | Heure fin (0-23) |

---

## API in-app

```
GET  /api/notifications               Liste paginée des notifications
GET  /api/notifications/summary       Résumé (non lues par catégorie)
GET  /api/notifications/unread-count  Nombre non lues

PATCH /api/notifications/{id}/read           Marquer une notification lue
PATCH /api/notifications/read                Marquer plusieurs lues (IDs dans le body)
PATCH /api/notifications/read-by-filter      Marquer lues selon filtre (type, date...)
PATCH /api/notifications/read-all           Tout marquer lu
```

---

## Documents en pièce jointe

Les emails peuvent inclure un document en pièce jointe ou en lien signé selon la taille :

- Fichier < `NOTIFICATIONS_MAIL_MAX_ATTACHMENT_BYTES` → pièce jointe directe
- Fichier > seuil → lien signé temporaire (`document_access_tokens`)

Les liens signés sont :
- Uniques (token 100 caractères)
- À durée limitée (`expires_at`)
- Réutilisables jusqu'à expiration (`used_at` n'est plus écrit) ; les PDF s'ouvrent dans l'onglet (`Content-Disposition: inline`), les autres types (export Excel…) sont toujours téléchargés (`attachment`, jamais `inline`). Le `Content-Type` est celui du `mime_type` du document (jamais deviné depuis le contenu : un export `.xls` est un tableau HTML) avec `X-Content-Type-Options: nosniff`. Dans l'email, le bouton dit « Ouvrir … » pour un PDF et « Télécharger le fichier » pour le reste. `GET /documents/{id}/download` (authentifié) reste en `attachment`
- Révocables (`revoked_at`)
- Journalisés (`document_access_logs` — succès et échecs)

---

## Emails (gabarits Blade)

Tous les emails de l'application suivent la charte d'OuKiLéMonBus : un **gabarit Blade par type d'email**, avec sa version texte. Maquette validée (à ouvrir dans un navigateur) : [`docs/design/emails-maquette.html`](design/emails-maquette.html).

| Email | Classe | Gabarit (HTML / texte) |
|---|---|---|
| Notification opérationnelle | `OperationalNotification` | `emails/notification` / `emails/notification-text` |
| Récapitulatif | `OperationalDigestNotification` | `emails/digest` / `emails/digest-text` |
| Vérification de l'email | `QueuedVerifyEmail` | `emails/auth/verify-email` / `-text` |
| Mot de passe oublié | `QueuedResetPassword` | `emails/auth/reset-password` / `-text` |

```text
resources/views/emails/
  layouts/base.blade.php       squelette commun (doctype, <head>, en-tête, pied)
  components/                  <x-emails::header>, chip, button, fallback-link, quote, notice, trip-ticket, trip-summary, footer
resources/images/email/logo.png  logo 64 × 64, joint en ligne (cid) à chaque email
app/Support/Mail/EmailPresenter.php   logique de présentation (testable sans rendu)
app/Support/Mail/TripTicket.php       billet du trajet
```

Les composants sont des composants Blade anonymes enregistrés dans `AppServiceProvider` avec le préfixe `emails` : on les appelle `<x-emails::button …>` (avec `::`).

### Contraintes de rendu

- **Styles en ligne écrits à la main et mise en page en tableaux** (`role="presentation"`) : les clients mail ignorent les feuilles de style et le CSS moderne. Le seul `<style>` contient les media queries.
- **Pas de SVG** (Gmail et Outlook ne l'affichent pas) : le logo est un PNG intégré en pièce jointe en ligne (`$message->embed()`), donc visible aussi en local dans Mailpit sans URL publique.
- **Fluide d'abord** : conteneur `width="600"` + `max-width:600px`, centré, jamais de défilement horizontal ; l'email reste correct si les media queries sont ignorées. Sous 480 px : marges réduites, titre 21 px, bouton pleine largeur (au moins 44 px de haut), étiquettes du billet qui passent à la ligne.
- Textes longs (adresses, liens) : `word-break` et `overflow-wrap`. Pas de mode sombre (`color-scheme: light`).
- Une **version texte** accompagne chaque email, avec le lien en clair.
- Police : `Inter, 'Segoe UI', Arial, sans-serif` (aucune police web). Couleurs : voir la maquette et `EmailPresenter` (tons des pastilles).

### Notification opérationnelle

Contenu, dans l'ordre : en-tête (avec « Gestion » pour un agent ou un admin), bandeau « À traiter rapidement » si `priority = high`, pastille (ton et libellé), titre, « Bonjour {prénom}, », message, motif en citation, billet du trajet, bouton, lien secondaire vers le document, lien de secours, pied (commune active).

- **Pastille** : ton d'après `event_key` puis la catégorie, comme `notificationKind` du front (refus et annulation : danger ; approuvé ou confirmé : succès ; passagers, document, demande à traiter : info ; conflit : conflit ; sinon neutre). Libellés précis par événement dans `EmailPresenter::LABELS`, avec un repli par ton.
- **Lien vers la SPA** (email seulement, selon le destinataire, comme `getHomePath` du front) :

| Cible | Agent / admin | Chauffeur affecté | Usager |
|---|---|---|---|
| Réservation `{id}` | `/gestion/reservations?reservation={id}` | `/chauffeur/missions/{id}` | `/espace/deplacements/{id}` |
| Demande d'adhésion `{id}` | `/gestion/demandes?file=adhesions&statut=toutes&demande={id}` | — | `/espace/organisations` |
| Demande de création `{id}` | `/gestion/demandes?file=organisations&statut=toutes&demande={id}` | — | `/espace/organisations` |
| Récapitulatif | `/notifications` | `/notifications` | `/notifications` |

  Un chauffeur qui n'est pas celui du trajet n'a pas de bouton, sauf s'il en est le demandeur (lien usager). Sans réservation, sans lien et avec un document, le bouton devient « Ouvrir le document » (lien signé).
- **Billet du trajet**, chargé au moment du rendu : complet pour l'usager et le chauffeur (date longue en français, départ, arrivée estimée, retour, passagers, bus et chauffeur seulement s'ils sont affectés), **résumé** pour un agent ou un admin. Les arrivées estimées viennent de `ReservationOccupationService` (comme `estimated_occupation` de l'API) et sont **arrondies à 5 minutes au plus proche**, comme dans le front. Si la réservation n'existe plus : ni billet ni bouton.
- **Document** : pièce jointe sous `NOTIFICATIONS_MAIL_MAX_ATTACHMENT_BYTES` (inchangé). Avec un bouton SPA, le lien signé devient un lien secondaire « Ouvrir la décision du Collège (PDF) · aussi jointe à cet email ».
- **Objet** : plus précis que le titre pour quatre événements — confirmation (« Votre déplacement du 15 octobre est confirmé »), refus (« Votre réservation n° 98 est refusée »), annulation (« … est annulée » ; chauffeur : « Réservation n° 105 annulée »), trajet non réalisé (« Trajet non réalisé : réservation n° 106 »). Partout ailleurs : le titre.
- **Textes dédiés** (email seulement) : titre et phrase propres pour ces événements (« Votre déplacement est confirmé… »). Les titres particuliers comme « Réservation de nouveau confirmée » gardent leur texte. Les autres événements affichent le titre et le message de la notification, avec « #104 » écrit « n° 104 ».

### Paramètres facultatifs de `NotificationDispatchService::dispatch()`

Ils ne servent qu'à l'email ; le pipeline (préférences, récapitulatif, anti-spam, déduplication, files) ne change pas.

| Paramètre | Rôle |
|---|---|
| `reason` | Motif d'un refus, d'une annulation ou d'un trajet non réalisé, ou d'un refus de demande. L'email le retire du message et l'affiche en citation « Motif : … ». `message` garde le motif (« Motif : … ») : c'est ce que stocke la cloche. Renseigné pour : réservation refusée, annulée (demandeur et chauffeur), trajet non réalisé, demandes d'adhésion et de création refusées. |
| `link` | `['path' => '/gestion/demandes?…', 'label' => 'Ouvrir la demande']` : lien vers la SPA quand il n'y a pas de réservation ; avec une réservation, son lien prime, sauf si la clé `overrides_reservation` est vraie (une demande d'aide mène à sa page `/gestion/alertes/{id}`, même avec un trajet). Clé facultative `secondary` (`['url' => …, 'label' => …]`) : second lien sous le bouton, par exemple « Ouvrir la carte » d'une demande d'aide. Renseigné par `UserOrgRequestService`, `OrgRegistrationRequestService` et `DriverAlertService`. Il est aussi conservé dans le récapitulatif (`notification_digest_queues.payload`). |

**Le canal `database` ne change pas** : `OperationalNotification::toArray()` garde les mêmes clés, le même `message` (motif compris) et le même `action_url` (lien signé du document). Le front s'en sert pour la cloche et `/notifications`. Un test le verrouille.

### Récapitulatif

Objet « Votre résumé du 3 octobre : 3 notifications » (fréquence quotidienne ou en plage de silence) ou « Votre résumé de 14:00 : … » (horaire). Une ligne par notification : pastille du ton, titre, heure de Bruxelles, message, lien SPA. Les notifications de **priorité haute sont en tête**. L'introduction rappelle l'heure du dernier récapitulatif envoyé. Pas de pièce jointe. Bouton « Voir toutes mes notifications » vers `/notifications`.

### Vérification de l'email et mot de passe oublié

Liens inchangés (`createUrlUsing` dans `AppServiceProvider`). Durée de validité lue dans la configuration (`auth.verification.expire`, `auth.passwords.users.expire`). Deux variantes d'accueil :

- compte créé par un admin (`POST /users`, `new QueuedVerifyEmail(createdByAdmin: true)`) : « Un compte a été créé pour vous sur OuKiLéMonBus… » ;
- lien envoyé par un admin (`POST /users/{id}/password-reset-link`, via le callback du broker, `new QueuedResetPassword($token, sentByAdmin: true)`) : « Un administrateur vous a envoyé ce lien… ».

### Vérifier le rendu

Mailpit (http://localhost:8025) : onglet HTML aux largeurs bureau, tablette et téléphone, onglet « HTML Check » et version texte. Après une modification des vues ou des notifications : `docker compose restart queue scheduler` (le worker garde le code en mémoire).

---

## Observabilité

### Endpoint de santé

```
GET /api/notifications/health
```

Permission requise : `notifications.monitor` (admin uniquement).

Retourne un JSON avec `status: ok` ou `status: alert` et les métriques :

```json
{
  "status": "ok",
  "checks": {
    "failed_deliveries_24h": { "value": 0, "threshold": 10, "ok": true },
    "high_pending_over_5m":  { "value": 0, "threshold": 5,  "ok": true },
    "late_digests_over_30m": { "value": 0, "threshold": 3,  "ok": true }
  }
}
```

### Commande artisan

```bash
docker compose exec app php artisan notifications:health
docker compose exec app php artisan notifications:health --json
```

### Variables de seuil

| Variable | Description | Défaut |
|---|---|---|
| `NOTIFICATIONS_OBS_MAX_FAILED_DELIVERIES_24H` | Max livraisons échouées sur 24h | configurable |
| `NOTIFICATIONS_OBS_MAX_HIGH_PENDING_OVER_5M` | Max notifications high+ en attente > 5 min | configurable |
| `NOTIFICATIONS_OBS_MAX_LATE_DIGESTS_OVER_30M` | Max digests en retard > 30 min | configurable |

### Runbook si `status: alert`

1. Vérifier que les workers de queues tournent : `docker compose ps queue`
2. Vérifier le scheduler (`schedule:run` toutes les minutes)
3. Inspecter les lignes `status = failed` dans `notification_deliveries`
4. Relancer les jobs si nécessaire via la queue

---

## Tables impliquées

| Table | Rôle |
|---|---|
| `notifications` | Notifications in-app (native Laravel) |
| `notification_deliveries` | Journal d'audit de chaque tentative d'envoi |
| `user_notification_preferences` | Préférences par utilisateur (1 ligne par user) |
| `notification_digest_queues` | File d'attente pour les notifications à agréger |
| `document_access_tokens` | Tokens signés pour les liens de téléchargement |
| `document_access_logs` | Journal des accès aux documents via token |

---

## Services impliqués

| Service | Rôle |
|---|---|
| `NotificationDispatchService` | Orchestre l'envoi en prenant en compte les préférences, quiet hours, digest |
| `NotificationRecipientResolver` | Résout la liste des destinataires selon le contexte métier |
| `SystemHealthService` | Calcule les métriques de santé du système de notifications |