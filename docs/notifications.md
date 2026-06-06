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
- À usage unique (`used_at`)
- Révocables (`revoked_at`)
- Journalisés (`document_access_logs` — succès et échecs)

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