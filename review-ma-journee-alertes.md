# Review « Ma journée » et alertes chauffeur

Document de discussion : à lire ensemble avant de toucher au code.
Date : 2026-10-06 · Branche de référence : `main` (après la PR #32)

## Périmètre

**Lu** : `TripProgressService`, `DriverAlertService`, `TripRouteService`, `DriverJourneySettingsService`, `TripProgressController`, `DriverAlertController`, `DriverJourneyController`, les FormRequests `DriverJourney/*` et `ValidatesDevicePosition`, `DevicePosition`, `DriverAlertOnCallMail`, le modèle `DriverAlert`, `config/driver_journey.php`, les 3 migrations (`trip_events`, `driver_alerts`, réglages + `trip_routes`), les routes, les titres des tests.

**Non fait** : les tests n'ont pas été lancés. Les Resources (`DriverAlertResource`, `TripEventResource`…), les enums et le front n'ont pas été relus, donc rien n'est dit sur ce que les réponses JSON exposent.

---

## 1. Points forts (à garder)

### Conception
- **Avancement dérivé des événements** : l'état se déduit des `trip_events`, sans colonne d'état à désynchroniser. Le champ `source` (chauffeur / agent / gps / système) permet d'ajouter un suivi GPS sans toucher au reste.
- **Règles écrites en tête de classe** sous forme de tableau « si… → 422/403 », cohérent avec le code (`TripProgressService.php:31-40`). Messages d'erreur en français.
- **Position tolérante** : un refus de partage ou un GPS muet ne bloque jamais l'action, mais la raison est conservée (`position_state`). `DevicePosition::fromArray` rattrape un « envoyée » sans coordonnées (`DevicePosition.php:30`).
- **Heure serveur** : `occurred_at = now()` (`TripProgressService.php:268`). L'horloge du téléphone ne peut pas fausser l'heure d'une étape.

### Fiabilité
- **Double appui absorbé deux fois** : vérification applicative (`:241`) puis contrainte unique `(reservation_id, type)` avec `catch UniqueConstraintViolationException` (`:289`). Réponse 200 avec `created: false`.
- **Prise en charge et clôture atomiques** : `UPDATE … WHERE status = …` en une requête (`DriverAlertService.php:168-221`). Pas de course entre agents, le message dit qui a pris l'alerte.
- **Idempotence à la création** : `POST /driver-alerts` accepte `Idempotency-Key`.
- **Alertes urgentes toujours délivrées** : `urgent: true` contourne digest et anti-spam (`NotificationDispatchService.php:77,82`).
- **Contact de garde bien traité** : email en file `critical`, 3 tentatives (30 s / 2 min / 10 min). Après échec définitif, `failed()` prévient les agents avec le téléphone du contact et trace l'échec (`DriverAlertOnCallMail.php:48`, `DriverAlertService.php:324`).
- **Repli de position** : sans position, le dernier lieu connu est déduit de l'avancement (`DriverAlertService.php:259`).

### Tracé et réglages
- **Tracé ORS** : calculé à la première ouverture, gardé avec une signature des lieux, recalculé seulement si un lieu change, échec mis en cache 180 s (`TripRouteService.php`).
- **Réglages commune sans cache** : un changement d'admin s'applique tout de suite, avec `max(0, …)` et repli sur la config.

### Droits et tests
- **Permissions séparées** (`record`/`manage`, `create`/`manage`). Chaque endpoint par réservation passe par `ensureCanView`. Le chauffeur ne voit que ses missions et ses alertes.
- **20 tests** couvrant le parcours des 4 étapes, l'ordre, la fenêtre horaire, le non réalisé, l'idempotence, la course entre agents, l'échec de l'email de garde.

---

## 2. Points faibles

Légende : 🔴 important (fiabilité, sécurité des personnes) · 🟠 moyen · 🟡 mineur.
La colonne « Décision » est à remplir ensemble.

| # | Gravité | Sujet | Décision |
|---|---------|-------|----------|
| 1 | 🔴 | Alerte enregistrée mais agents peut-être non prévenus | ☐ faire ☐ plus tard ☐ non |
| 2 | 🔴 | Aucune escalade si personne ne prend l'alerte | ☐ faire ☐ plus tard ☐ non |
| 3 | 🔴 | Compte rendu perdu si la création échoue à la dernière arrivée | ☐ faire ☐ plus tard ☐ non |
| 4 | 🔴 | Étape enregistrée par erreur non corrigeable | ☐ faire ☐ plus tard ☐ non |
| 5 | 🔴 | Pas de mode hors ligne (heure d'étape = heure de synchronisation) | ☐ faire ☐ plus tard ☐ non |
| 6 | 🟠 | Une seule position gardée par alerte | ☐ faire ☐ plus tard ☐ non |
| 7 | 🟠 | Pas de limite de débit sur `POST /driver-alerts` | ☐ faire ☐ plus tard ☐ non |
| 8 | 🟠 | Chauffeur non prévenu à la clôture | ☐ faire ☐ plus tard ☐ non |
| 9 | 🟠 | `take()` : 500 possible alors que l'alerte est bien prise | ☐ faire ☐ plus tard ☐ non |
| 10 | 🟠 | Seuil « loin du lieu prévu » ignore la précision GPS, non réglable par commune | ☐ faire ☐ plus tard ☐ non |
| 11 | 🟠 | Ordre des contrôles dans `record()` : fuite d'information | ☐ faire ☐ plus tard ☐ non |
| 12 | 🟠 | ORS appelé pendant un GET, sans verrou | ☐ faire ☐ plus tard ☐ non |
| 13 | 🟡 | Requête `Municipality` répétée, commune active unique supposée | ☐ faire ☐ plus tard ☐ non |
| 14 | 🟡 | Liens du SPA codés en dur dans le backend | ☐ faire ☐ plus tard ☐ non |
| 15 | 🟡 | Alerte possible sur un trajet passé / annulé | ☐ faire ☐ plus tard ☐ non |
| 16 | 🟡 | Données personnelles dans l'email de garde | ☐ faire ☐ plus tard ☐ non |
| 17 | 🟡 | Détails de code | ☐ faire ☐ plus tard ☐ non |
| 18 | 🟡 | Lacunes de tests | ☐ faire ☐ plus tard ☐ non |

### 🔴 1. Alerte enregistrée mais agents peut-être non prévenus
- **Où** : `DriverAlertService.php:133` (`notifyCreated` appelé après la transaction). Seul l'envoi à la garde est dans un `try/catch` (`:310-316`).
- **Problème** : si `dispatch()` vers les agents lève une exception, la réponse est un 500 alors que l'alerte existe. Le chauffeur réessaie. Sans `Idempotency-Key` (facultative), cela crée un doublon.
- **Piste** : isoler les notifications dans un `try/catch` avec log, ou les mettre en file. La création de l'alerte doit toujours répondre 201.

### 🔴 2. Aucune escalade si personne ne prend l'alerte
- **Problème** : pas de rappel aux agents ni de passage au contact de garde si l'alerte reste `ouverte` N minutes. Le contact de garde n'est prévenu que par email (pas de SMS ni d'appel).
- **Piste** : commande planifiée `alerts:escalate` (seuil configurable) qui renotifie les agents puis la garde, avec une trace dans l'historique. À décider : seuil, nombre de relances, canal SMS ou non.

### 🔴 3. Compte rendu perdu si la création échoue à la dernière arrivée
- **Où** : `TripProgressService.php:294` (`createRealisedReport` après la transaction).
- **Problème** : si `tripReportService->save` lève une exception (validation, course sur `steps_close_at`), l'événement est déjà enregistré et la réponse est un 500. Au réessai, la branche « déjà enregistré » (`:243`) renvoie `report: null` : le compte rendu n'est plus jamais créé automatiquement.
- **Piste** : mettre la création dans la même transaction, ou rattraper le cas dans la branche « déjà enregistré » pour `ARRIVEE_RETOUR` s'il n'existe pas de compte rendu.

### 🔴 4. Étape enregistrée par erreur non corrigeable
- **Problème** : aucun endpoint d'annulation ni de correction, alors que la doc de classe parle de « un agent corrige ». Un « Départ » tapé trop tôt bloque le trajet dans cet état, et l'heure fausse part dans le compte rendu.
- **Piste** : `DELETE` de la dernière étape, réservé à `trip-progress.manage`, journalisé via `DomainEventLogger`. À décider : interdit une fois le compte rendu créé ?

### 🔴 5. Pas de mode hors ligne
- **Où** : `TripProgressService.php:268`.
- **Problème** : `occurred_at = now()` est l'heure de synchronisation. Si le front rejoue une file d'attente après une perte de réseau (bus rural), toutes les heures sont fausses, y compris les heures réelles du compte rendu. Pas d'`Idempotency-Key` sur `trip-events` (la contrainte unique compense pour les doublons de même type, pas pour le rejeu).
- **Piste** : accepter un `client_occurred_at` borné (pas dans le futur, pas plus ancien que X h), stocké à part, utilisé pour le compte rendu avec `source` explicite. À décider avec l'équipe front : le front gère-t-il une file hors ligne ?

### 🟠 6. Une seule position gardée par alerte
- **Où** : `DriverAlertService.php:147` (`updatePosition` écrase les colonnes, compteur `position_updates` seulement).
- **Problème** : on perd la trace du déplacement pendant un incident.
- **Piste** : table `driver_alert_positions` (une ligne par envoi) ou événements avec coordonnées.

### 🟠 7. Pas de limite de débit sur `POST /driver-alerts`
- **Problème** : aucun `throttle` vu dans les routes. Un bouton bloqué ou un abus envoie des emails urgents à tous les agents et à la garde, et `urgent` contourne l'anti-spam.
- **Piste** : `throttle` par utilisateur, et/ou une seule alerte non clôturée par chauffeur (sinon renvoyer l'existante).

### 🟠 8. Chauffeur non prévenu à la clôture
- **Où** : `DriverAlertService.php:193-221` (`close()` ne notifie personne, alors que `notifyDriverTaken` existe).
- **Piste** : notification au chauffeur avec la note de clôture (ou un résumé).

### 🟠 9. `take()` : 500 possible alors que l'alerte est bien prise
- **Où** : `DriverAlertService.php:187`.
- **Problème** : si `notifyDriverTaken` échoue après l'UPDATE, l'agent voit un 500. Son réessai donne « déjà prise en charge par vous ».
- **Piste** : même traitement que le point 1.

### 🟠 10. Seuil « loin du lieu prévu »
- **Où** : `TripProgressService.php:172,196` · `DriverJourneySettingsService.php:38-41`.
- **Problème** : 500 m fixes, sans tenir compte de `accuracy_m`. Un fix à 1 500 m de précision donne de faux signaux, un fix à 300 m en masque de vrais. Le seuil n'est pas réglable par commune alors que le docblock du service le laisse entendre (seuls le moteur et le délai le sont).
- **Piste** : signaler seulement si `distance > seuil + accuracy_m`, et décider si le seuil devient un réglage commune.

### 🟠 11. Ordre des contrôles dans `record()`
- **Où** : `TripProgressService.php:222` (422 « confirmé ») avant `:230` (403 droit).
- **Problème** : un chauffeur peut sonder n'importe quel id de réservation et savoir s'il est confirmé.
- **Piste** : contrôle de droit d'abord, état ensuite.

### 🟠 12. ORS appelé pendant un GET, sans verrou
- **Où** : `TripRouteService.php:52-67`.
- **Problème** : deux ouvertures simultanées déclenchent deux appels ORS, et le `updateOrCreate` sur `reservation_id` unique peut lever une exception de doublon (500). Latence ORS dans la requête.
- **Piste** : `Cache::lock` autour du calcul ; à terme, calcul asynchrone à la confirmation.

### 🟡 13. Requête `Municipality` répétée
- **Où** : `DriverJourneySettingsService.php:63-66`, `DriverAlertService.php:307,326`.
- **Problème** : une requête à chaque lecture de réglage (deux fois par `show`), et `first()` sans `orderBy` suppose une seule commune active.
- **Piste** : mémoriser par requête (`once()`) et décider du comportement s'il y a plusieurs communes actives.

### 🟡 14. Liens du SPA codés en dur
- **Où** : `DriverAlertService.php:300,368`, `DriverAlertOnCallMail.php:62` (`/gestion/alertes/{id}`, `/chauffeur/journee`).
- **Piste** : les mettre en config ou les centraliser dans `EmailPresenter`.

### 🟡 15. Alerte possible sur un trajet passé / annulé
- **Où** : `DriverAlertService.php:98`.
- **Problème** : seul le propriétaire du trajet est contrôlé. Un trajet passé ou non confirmé donne un contexte trompeur (progression `A_VENIR`).

### 🟡 16. Données personnelles dans l'email de garde
- **Problème** : téléphone du chauffeur, position et message libre partent vers une adresse externe sans compte (`alertSummary`). Probablement voulu.
- **Piste** : le documenter explicitement dans `docs/driver_journey.md`.

### 🟡 17. Détails de code
- `catch (GeocodingException|Throwable)` redondant (`TripRouteService.php:58`).
- `COALESCE` de `close()` avec un `now()` formaté injecté dans du SQL brut (`DriverAlertService.php:200-201`) : sûr ici, fragile à copier.
- Le compteur via `per_page=1` est une astuce, pas un endpoint de comptage.

### 🟡 18. Lacunes de tests
Pas de test vu pour :
- l'échec de création du compte rendu (point 3) ;
- l'échec du dispatch aux agents (point 1) ;
- `updatePosition` (refus après clôture, autre chauffeur) ;
- le 403 de `show` pour l'alerte d'un autre chauffeur ;
- `close` sans prise en charge préalable ;
- la position `refusee` sur une alerte ;
- les filtres de dates de la liste (passage heure d'été).

La course entre deux agents est testée de façon séquentielle : elle vérifie la requête conditionnelle, pas une vraie concurrence.

---

## 3. Ordre proposé

1. **Fiabilité immédiate** : points 1, 9, 11 (petits changements, gros gain).
2. **Données** : point 3 (compte rendu atomique), point 4 (annulation de la dernière étape).
3. **Sécurité des personnes** : points 2, 7, 8 (escalade, limite de débit, notification de clôture).
4. **Terrain** : point 5 (hors ligne), à caler avec le front.
5. **Reste** : points 6, 10, 12 puis les mineurs 13 à 18.

## 4. Questions à trancher ensemble

- Seuil et canal de l'escalade (point 2) : combien de minutes, SMS ou non ?
- Une annulation d'étape (point 4) est-elle permise après la création du compte rendu ?
- Le front gère-t-il (ou gérera-t-il) une file hors ligne (point 5) ?
- Une seule alerte ouverte par chauffeur (point 7) : on renvoie l'existante ou on refuse ?
- Veut-on garder l'historique des positions (point 6) et combien de temps (RGPD) ?
- Le seuil de distance des étapes devient-il un réglage par commune (point 10) ?
