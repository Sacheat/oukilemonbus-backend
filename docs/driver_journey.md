# Journée du chauffeur : étapes des trajets et demandes d'aide

Page « Ma journée » du front (`/chauffeur/journee`) : le téléphone du chauffeur, sur un support dans le bus, affiche les
trajets du jour. Pour chaque trajet, le chauffeur appuie sur **Départ** et **Arrivée** (aller, puis retour) ; un bouton
**Aide** prévient les agents en cas de panne ou de problème.

## 1. Principe : des faits, une source, un avancement calculé

Le backend enregistre des **faits** (`trip_events`) : « départ de l'aller à 09:02 », avec leur **source** :

| Source | Aujourd'hui | Plus tard |
| --- | --- | --- |
| `chauffeur` | bouton de « Ma journée » | — |
| `agent` | correction par un agent | — |
| `gps` | — | application de suivi, géorepérage (arrivée détectée) |
| `systeme` | — | règle automatique |

L'**avancement** (`TripProgressService::stateFor`) ne dépend que des faits enregistrés, jamais de leur source :

```
aucun fait               → a_venir
depart_aller             → en_route_aller
arrivee_aller            → sur_place
depart_retour            → en_route_retour
arrivee_retour           → termine
```

Un futur suivi automatique n'aura donc qu'à écrire les mêmes lignes : avancement, badges du Planning, compte rendu et
écrans ne changent pas. « Pour l'instant suivi manuel par le chauffeur ; l'automatisation se branche ensuite. »

**Limite à connaître** : une page web ne lit pas le GPS en arrière-plan (écran éteint, autre application au premier
plan). Un suivi en continu, y compris sans réseau (positions gardées puis envoyées), demandera une application
installée sur le téléphone, qui écrira dans `trip_events` avec `source = gps`.

## 2. Position

Chaque fait et chaque alerte gardent la position du téléphone au moment de l'appui (`latitude`, `longitude`,
`accuracy_m` en mètres, `position_captured_at`) et son **état** `position_state` :

- `envoyee` : coordonnées reçues ;
- `refusee` : le téléphone a refusé le partage (le règlement interne l'impose ; l'application ne peut pas l'empêcher,
  elle en garde la trace) ;
- `indisponible` : pas de réponse du GPS. « envoyée » sans coordonnées est enregistré comme « indisponible ».

Les coordonnées des étapes ne sont renvoyées qu'aux rôles internes (`trip-progress.manage`) ; le chauffeur voit l'état.

## 3. Étapes d'un trajet

Routes (`TripProgressController`, permission `trip-progress.record` ou `trip-progress.manage`) :

| Méthode | Route | Réponse |
| --- | --- | --- |
| POST | `/reservations/{id}/trip-events` | `type`, `position {state, latitude, longitude, accuracy_m, captured_at}`, et pour `arrivee_retour` `actual_nb_children` / `actual_nb_adults`. 201 (nouvelle étape) ou 200 (déjà enregistrée : double appui). `data` = l'étape, `meta.progress`, `meta.trip_report` (créé à la dernière arrivée) |
| GET | `/reservations/{id}/trip-progress` | `data {state, next_step, events}` + `meta` : `first_departure_opens_at`, `first_departure_closes_at`, `steps_close_at` (heure locale sans fuseau), `can_record` |
| GET | `/trip-progress?reservation_ids[]=` | 1 à 100 réservations ; le chauffeur ne reçoit que les siennes |

Règles (`TripProgressService::record`) :

```
trajet pas confirmé, ou déclaré non réalisé              → 422 (reservation)
ni agent / admin, ni chauffeur affecté                   → 403
étape déjà enregistrée                                   → 200, rien ne change
pas l'étape suivante                                     → 422 (type) « Étape attendue : … »
chauffeur, départ : à partir de N min avant l'heure prévue
  (config driver_journey.departure_window_minutes, 60)
  et jusqu'à la fin du jour du trajet                    → 422 sinon
chauffeur, autres étapes : jusqu'au lendemain 12:00      → 422 sinon (délai du compte rendu)
agent / admin : pas de contrainte d'heure (source agent)
```

**Validation automatique** : la dernière arrivée crée le compte rendu « réalisé » (`TripReportService::save`) avec les
heures réelles du départ et du départ du retour (heure de Bruxelles) et les passagers confirmés, sauf si un compte rendu
existe déjà. La réservation reste `confirme`.

## 4. Demandes d'aide

Table `driver_alerts`, routes `DriverAlertController` :

| Méthode | Route | Qui | Effet |
| --- | --- | --- | --- |
| POST | `/driver-alerts` | chauffeur (`driver-alerts.create`) | `reservation_id` (facultatif, une de ses missions), `message` (facultatif, 500 max), `position`. 201. `Idempotency-Key` accepté : un double appui ne crée pas deux alertes |
| PATCH | `/driver-alerts/{id}/position` | le chauffeur de l'alerte | « Renvoyer ma position » (alerte non clôturée) ; `position.updates` compte les renvois |
| GET | `/driver-alerts` | agent / admin (toutes), chauffeur (les siennes) | `statuses[]`, `date_from`, `date_to`, `per_page` (1 à 100) ; non clôturées d'abord, puis les plus récentes |
| GET | `/driver-alerts/{id}` | idem | détail |
| POST | `/driver-alerts/{id}/take` | agent / admin (`driver-alerts.manage`) | `ouverte` → `prise_en_charge` ; le chauffeur est prévenu |
| POST | `/driver-alerts/{id}/close` | agent / admin | `closing_note` obligatoire → `cloturee` |

Sans position, `fallback_location` donne le **dernier lieu connu**, d'après l'avancement du trajet : en route vers la
destination ou sur place → la destination ; sinon → le lieu de départ (libellé explicite : « En route vers la
destination : … »). `map_point` (`exact` : true pour la position, false pour le dernier lieu connu) et `map_links`
(OpenStreetMap, Google Maps) servent à la carte.

Notifications (catégorie `driver_alert`, `urgent` : ni récapitulatif, ni délai anti-spam, ni préférence qui la coupe) :

- création : agents et admins actifs, cloche + email, priorité haute (`driver_alert.created`). Email : bouton « Voir
  l'alerte » (`/gestion/alertes/{id}`) et lien « Ouvrir la carte » (carte publique, sans connexion) ;
- **contact de garde** de la commune active (`municipalities.on_call_name`, `on_call_email`, `on_call_phone`,
  facultatif, réglé par un admin dans Communes) : email seul (`DriverAlertOnCallMail`, file `critical`), avec la carte
  en bouton principal ; une panne d'envoi n'empêche pas l'alerte ;
- prise en charge : le chauffeur (`driver_alert.taken`).

Le texte (`summary`) reprend le numéro, l'heure, le trajet et les passagers prévus, le message, la position (précision)
ou le dernier lieu connu, et le téléphone du chauffeur.

## 5. Permissions

| Permission | admin | agent_communal | chauffeur |
| --- | --- | --- | --- |
| `trip-progress.record` | ✓ | — | ✓ |
| `trip-progress.manage` | ✓ | ✓ | — |
| `driver-alerts.create` | ✓ | — | ✓ |
| `driver-alerts.manage` | ✓ | ✓ | — |

Après un pull : `php artisan migrate` (dev et `--env=testing`), `db:seed --class=RolesAndPermissionsSeeder`,
`optimize:clear`.

## 6. Étape suivante prévue

Catalogue des applications de navigation (OpenStreetMap par défaut, Google Maps…) dans `config/driver_journey.php`,
choix et délai du premier départ par commune (page Communes), tracé de l'itinéraire calculé par ORS et gardé en cache.
