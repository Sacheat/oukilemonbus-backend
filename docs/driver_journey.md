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
ni agent / admin, ni chauffeur affecté                   → 403 (vérifié en premier : rien n'est dit d'un autre trajet)
trajet pas confirmé, ou déclaré non réalisé              → 422 (reservation)
étape déjà enregistrée                                   → 200, rien ne change (sauf le rattrapage ci-dessous)
pas l'étape suivante                                     → 422 (type) « Étape attendue : … »
chauffeur, départ : à partir de N min avant l'heure prévue
  (config driver_journey.departure_window_minutes, 60)
  et jusqu'à la fin du jour du trajet                    → 422 sinon
chauffeur, autres étapes : jusqu'au lendemain 12:00      → 422 sinon (délai du compte rendu)
agent / admin : pas de contrainte d'heure (source agent)
```

**Validation automatique** : la dernière arrivée crée le compte rendu « réalisé » (`TripReportService::save`) avec les
heures réelles du départ et du départ du retour (heure de Bruxelles) et les passagers confirmés, sauf si un compte rendu
existe déjà. La réservation reste `confirme`. Si cette création échoue (erreur 500 après l'enregistrement de l'étape),
le réessai de la dernière arrivée la refait : étape déjà enregistrée et pas de compte rendu → compte rendu créé
(`meta.trip_report`).

## 4. Demandes d'aide

Table `driver_alerts`, routes `DriverAlertController` :

| Méthode | Route | Qui | Effet |
| --- | --- | --- | --- |
| POST | `/driver-alerts` | chauffeur (`driver-alerts.create`) | `reservation_id` (facultatif, une de ses missions), `message` (facultatif, 500 max), `position`. 201 et `meta.created: true` ; **une seule alerte en cours par chauffeur** : 200 et `meta.created: false` (voir plus bas). `Idempotency-Key` accepté. 10 envois par minute au plus (429) |
| PATCH | `/driver-alerts/{id}/position` | le chauffeur de l'alerte | « Renvoyer ma position » (alerte non clôturée) ; `position.updates` compte les renvois |
| GET | `/driver-alerts` | agent / admin (toutes), chauffeur (les siennes) | `statuses[]`, `date_from`, `date_to`, `per_page` (1 à 100) ; non clôturées d'abord, puis les plus récentes |
| GET | `/driver-alerts/{id}` | idem | détail |
| POST | `/driver-alerts/{id}/take` | agent / admin (`driver-alerts.manage`) | `ouverte` → `prise_en_charge` ; le chauffeur est prévenu |
| POST | `/driver-alerts/{id}/close` | agent / admin | `closing_note` obligatoire → `cloturee` |

Prise en charge et clôture sont des mises à jour conditionnelles en une requête (`WHERE status = 'ouverte'`, ou
`!= 'cloturee'`) : si deux agents cliquent en même temps, un seul gagne ; l'autre reçoit 422 « déjà prise en charge
par … » et le chauffeur n'est prévenu qu'une fois.

Une seule alerte en cours (ouverte ou prise en charge) par chauffeur, sous verrou sur le chauffeur :

```
alerte en cours           → renvoyée (200) : nouvelle position si elle a des coordonnées (position.updates + 1),
                            nouveau message ajouté avec l'heure ; pas de nouvel email (historique driver_alert.repeated)
sinon                     → nouvelle alerte (201) et notifications
```

Le trajet n'est rattaché que s'il a lieu : approuvé ou confirmé, **aujourd'hui ou hier** (trajet du soir qui finit
après minuit). Sinon (trajet passé, futur, annulé), l'alerte part **sans trajet** : jamais refusée, mais sans contexte
trompeur pour les agents. Un trajet d'un autre chauffeur reste refusé (422).

Sans position, `fallback_location` donne le **dernier lieu connu**, d'après l'avancement du trajet : en route vers la
destination ou sur place → la destination ; sinon → le lieu de départ (libellé explicite : « En route vers la
destination : … »). `map_point` (`exact` : true pour la position, false pour le dernier lieu connu) et `map_links`
(OpenStreetMap, Google Maps) servent à la carte.

Notifications (catégorie `driver_alert`, `urgent` : ni récapitulatif, ni délai anti-spam, ni préférence qui la coupe) :

- création : agents et admins actifs, cloche + email, priorité haute (`driver_alert.created`). Email : bouton « Voir
  l'alerte » (`/gestion/alertes/{id}`) et lien « Ouvrir la carte » (carte publique, sans connexion) ;
- **contact de garde** de la commune active (`municipalities.on_call_name`, `on_call_email`, `on_call_phone`,
  facultatif, réglé par un admin dans Communes) : email seul (`DriverAlertOnCallMail`, file `critical`, 3 tentatives),
  avec la carte en bouton principal ; une panne d'envoi n'empêche pas l'alerte. Si l'email échoue définitivement,
  `failed()` prévient les agents et admins dans l'application (`driver_alert.on_call_failed` : « prévenez-le autrement »,
  avec son téléphone) et le note dans l'historique de l'alerte ;
- prise en charge : le chauffeur (`driver_alert.taken`) ;
- clôture : le chauffeur, avec la note (`driver_alert.closed`). Une alerte clôturée sans prise en charge est notée prise
  en charge par celui qui la clôture.

Une notification qui échoue (base, file d'attente, email) est notée dans le journal (`Log::error`) sans faire échouer
l'action : l'alerte, la prise en charge ou la clôture restent enregistrées et la réponse reste un succès. Sinon, le
chauffeur croirait que son aide n'est pas partie.

**Données envoyées au contact de garde** : le téléphone du chauffeur, sa position et son message partent par email vers
une adresse sans compte dans l'application. C'est voulu : la garde doit pouvoir le rappeler et le localiser. L'adresse
est réglée par un admin (Communes) ; la laisser vide coupe cet envoi.

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

## 6. Réglages de la commune et navigation

`GET /driver-journey/settings` (chauffeur, agent, admin) :

```json
{ "data": {
  "navigation_engine": { "key": "openstreetmap", "label": "OpenStreetMap",
    "android": "geo:{lat},{lng}?q={lat},{lng}({label})",
    "web": "https://www.openstreetmap.org/?mlat={lat}&mlon={lng}#map=17/{lat}/{lng}",
    "web_from": "https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route={from_lat}%2C{from_lng}%3B{lat}%2C{lng}" },
  "departure_window_minutes": 60,
  "step_distance_warning_meters": 500,
  "navigation_engines": [{ "key": "openstreetmap", "label": "OpenStreetMap" }, { "key": "google", "label": "Google Maps" }],
  "default_navigation_engine": "openstreetmap",
  "default_departure_window_minutes": 60 } }
```

- Catalogue des applications dans `config/driver_journey.php` (`navigation_engines`) : ajouter une application = ajouter
  une entrée. Le front remplit les gabarits : `android` sur un téléphone Android (`geo:` ouvre l'application de
  navigation installée, utilisable hors ligne si la région est téléchargée ; `null` = lien web), sinon `web`
  (depuis la position du téléphone) ou `web_from` (d'un point connu).
- Choix de la commune : `PATCH /municipalities/{id}` avec `navigation_engine` (clé du catalogue) et
  `driver_departure_window_min` (0 à 240), **réservés aux admins** (422 `prohibited` pour un agent, qui garde le reste de
  la page Communes). `null` = valeur par défaut de la config ; `effective_*` dans la réponse.
- Le délai du premier départ de la commune remplace `departure_window_minutes` de la config (TripProgressService).

## 7. Tracé réel d'un trajet

`GET /reservations/{id}/route` (chauffeur affecté, agents et admins ; sinon 403) : tracé de l'aller (lieu de départ →
destination) calculé par ORS (`/v2/directions/driving-car/geojson`, instructions en français) **à la première ouverture**,
puis gardé dans `trip_routes` (une ligne par réservation).

```
tracé gardé et lieux inchangés (même signature) → renvoyé tel quel, sans appel ORS
pas de tracé ou lieu changé                     → recalculé, même ligne
coordonnées manquantes ou ORS en panne           → data: null (le front garde la ligne à vol d'oiseau), rien d'enregistré
```

`data` : `geometry` `[[lat, lng], …]` (ordre de Leaflet), `distance_m`, `duration_s`, `steps`
`[{ instruction, name, distance_m, duration_s }]`, `computed_at`.

## 8. Étapes loin du lieu prévu

`GET /reservations/{id}/trip-progress` renvoie aux agents et admins (`trip-progress.manage`) `meta.step_checks` et
`meta.step_distance_warning_meters` (config `step_distance_warning_meters`, 500 par défaut) :

```
départ (aller), arrivée au lieu de départ  → lieu prévu = lieu de départ (pickup)
arrivée à destination, départ du retour   → lieu prévu = destination
distance (vol d'oiseau) > seuil + précision du GPS (accuracy_m)
                                           → far_from_expected = true (signalé dans le Planning, rien n'est bloqué)
position ou coordonnées du lieu absentes   → distance_m null, pas de signal
```

Chaque entrée : `type`, `expected_place`, `expected_latitude`, `expected_longitude`, `distance_m`, `far_from_expected`.
La précision est ajoutée au seuil : une position à 1 000 m près ne crée pas de faux signal. Le seuil vient de la config
seulement (pas réglable par commune).

## 9. Étape suivante prévue

Relevé du compteur kilométrique, facultatif, activé par commune (désactivé par défaut).
