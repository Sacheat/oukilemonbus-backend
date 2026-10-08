# Planning hebdomadaire envoyé automatiquement

Cahier des charges §5.9 : le planning de la semaine suivante est préparé en fin de semaine et transmis au service. Ici,
il part tout seul aux agents et aux admins, puis une version corrigée part si un trajet change. Décidé avec Steph le
07/10 ; code : `WeeklyPlanningService`, commande `planning:send-weekly`, config `config/weekly_planning.php`.

## 1. Quand

`planning:send-weekly` tourne **toutes les 15 minutes** (`routes/console.php`) :

```
envoi désactivé par la commune                                       → rien
le moment de l'envoi de cette semaine est passé (vendredi 12:00 par
défaut, heure de Bruxelles) et le planning de la semaine suivante
n'est pas encore parti                                               → version 1
une semaine déjà envoyée et pas encore terminée (jusqu'au dimanche) :
  trajets changés depuis la dernière version, et dernière version
  partie il y a plus de 60 min (update_min_interval_minutes)          → version corrigée (2, 3…)
```

- Un serveur arrêté à l'heure prévue n'empêche pas l'envoi : le planning part au passage suivant.
- Jamais deux fois la même version : la ligne `weekly_planning_dispatches` (unique semaine + version) est réservée
  avant de générer le fichier.
- « Changé » : l'empreinte (`signature`) des trajets du fichier diffère de celle de la dernière version (ajout,
  annulation, date, heures, statut, chauffeur, bus, lieux, passagers, objet).

## 2. Quoi

- Le fichier est l'**export Excel du Planning** (`PlanningExportService`, tableau HTML en `.xls`) de la semaine du lundi au
  dimanche, tous bus et chauffeurs : trajets **approuvés et confirmés**, avec la colonne **Statut** (libellé lisible).
- Les demandes **en attente ou en conflit** ne sont pas dans le fichier ; leur nombre est annoncé dans le message.
- Un **seul document** par version (`documents`, type `planning`, `service_date` = lundi, `version`), visible dans la
  page Documents par les rôles internes. Chaque destinataire reçoit un **lien personnel** vers ce document (jeton valable
  24 h, `DocumentAccessTokenService`) : pas de pièce jointe, pas de copie par destinataire.

## 3. Qui

Agents et admins actifs, selon leur préférence `weekly_planning_opt_in` (`user_notification_preferences`) :

```
true  → le reçoit toujours
false → ne le reçoit jamais
null  → automatique : le responsable du planning de la commune s'il est désigné et actif, sinon tous
```

Catégorie de notification `weekly_planning` (canaux `notify_weekly_planning_in_app` / `_email`), événements
`planning.weekly.sent` et `planning.weekly.updated`. Non urgente : elle suit les préférences, le récapitulatif et les
heures silencieuses de chacun.

**Responsable du planning** (`municipalities.weekly_planning_owner_user_id`, réglé par un admin) : un agent ou un admin
actif (sinon 422). Le changement est noté dans l'historique (`weekly_planning.owner_changed`), la personne désignée
est prévenue (`planning.weekly.owner_assigned`) et l'ancienne aussi (`planning.weekly.owner_removed`), dans la catégorie
`weekly_planning` (même si « Travail à traiter » est coupé). Un
responsable désactivé ne compte plus : tout le monde le reçoit à nouveau, pour que le planning ne se perde pas.
Les autres peuvent toujours l'activer pour eux (remplacement, maladie).

## 4. Réglages de la commune (admins)

`PATCH /municipalities/{id}`, champs **réservés aux admins** (422 `prohibited` pour un agent) ; `null` = valeur par
défaut de la config :

| Champ | Valeurs | Défaut |
| --- | --- | --- |
| `weekly_planning_enabled` | booléen | `true` |
| `weekly_planning_send_day` | 1 (lundi) à 7 (dimanche) | 5 (vendredi) |
| `weekly_planning_send_time` | `HH:MM` (Bruxelles), au quart d'heure (00, 15, 30, 45 : la vérification tourne toutes les 15 min) | `12:00` |
| `weekly_planning_owner_user_id` | id d'un agent ou admin actif, ou `null` | aucun (tous) |

La réponse donne aussi `effective_weekly_planning_*` et `weekly_planning_owner` (`id`, `name`, `email`, `active`,
`effective` : false si le compte est désactivé ou n'est plus agent ni admin, il ne compte alors plus).
`GET /notifications/preferences` et `PATCH` acceptent `weekly_planning_opt_in` (`null`, `true`, `false`) et les deux
canaux.

## 5. Tests

`tests/Feature/WeeklyPlanningTest.php` : envoi au bon moment et une seule fois, fichier unique, version corrigée au plus
une fois par heure et jusqu'au dimanche, responsable seul par défaut + inscription d'un autre, responsable désactivé,
réglages réservés aux admins, envoi coupé puis déplacé.

## 6. Étape suivante prévue

Rapport définitif du lundi 08:00 pour la semaine passée (prévu et réel de chaque trajet, comptes rendus), même
mécanisme : H1a, H2a, H3a, H4b.
