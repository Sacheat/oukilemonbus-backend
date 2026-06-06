# Documentation technique — Module Planning

## 1. Objectif du module

Le module Planning permet de consulter les réservations du bus communal sur une période donnée.

Il sert à fournir une vue opérationnelle des trajets prévus, avec les informations nécessaires pour l'administration, les agents communaux, les chauffeurs et le futur front React.

Le module permet notamment :

- d'afficher les réservations utiles au planning ;
- de limiter la consultation à une période maximale de 31 jours ;
- de filtrer par statut, véhicule, chauffeur ou organisation ;
- de gérer les droits d'accès selon le rôle utilisateur ;
- d'afficher les conflits liés aux réservations ;
- d'afficher les disponibilités chauffeurs/véhicules pour les rôles internes ;
- d'exporter le planning dans un format compatible Excel ;
- de fournir des données d'affichage prêtes à être utilisées par le front.

Le planning est un outil de travail opérationnel.  
Il ne remplace pas les documents officiels archivés, comme les feuilles de route.

---

## 2. Périmètre actuel

Le module Planning couvre actuellement une version backend V1/V1+.

### Inclus

- Consultation du planning via API JSON.
- Export du planning en fichier compatible Excel.
- Filtre par période.
- Limite de période à 31 jours.
- Filtre par statut.
- Filtre par véhicule.
- Filtre par chauffeur.
- Filtre par organisation.
- Gestion des droits selon le rôle.
- Affichage des conflits.
- Affichage des disponibilités pour les rôles internes.
- Données d'affichage simplifiées pour le front.
- Tests automatisés.

### Non inclus pour l'instant

- Optimisation avancée de tournées.
- Calcul d'itinéraire multi-arrêts.
- Réorganisation automatique des missions.
- Prise en compte d'une capacité restante pendant une tournée partagée.
- Interface React complète.
- Vrai export XLSX natif.

Ces points peuvent être prévus comme améliorations futures.

---

## 3. Routes API disponibles

Le module expose deux endpoints principaux.

### 3.1 Consultation JSON du planning

```http
GET /api/planning
Cette route retourne une liste de réservations planifiées sur une période donnée.

3.2 Export du planning
GET /api/planning/export

Cette route retourne un fichier compatible Excel contenant les réservations du planning.

L'export utilise le même service métier que l'API JSON.
Cela garantit que les filtres et les droits d'accès sont identiques.

4. Paramètres acceptés

Les deux endpoints acceptent les mêmes paramètres.

Paramètres obligatoires
ParamètreTypeDescription
start_datedate Y-m-dDate de début de la période
end_datedate Y-m-dDate de fin de la période

Exemple :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07
Paramètres optionnels
ParamètreTypeDescription
include_availabilitybooleanDemande les disponibilités chauffeurs/véhicules
statuses[]arrayFiltre par statut de réservation
vehicle_idintegerFiltre par véhicule
driver_idintegerFiltre par chauffeur
organization_idintegerFiltre par organisation

Exemples :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&vehicle_id=1
GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&driver_id=2
GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&organization_id=3
GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&statuses[]=confirme
5. Validation des paramètres

La validation est centralisée dans :

app/Http/Requests/Planning/PlanningIndexRequest.php

Cette classe vérifie :

que start_date est obligatoire ;
que end_date est obligatoire ;
que les dates sont au format Y-m-d ;
que end_date est postérieure ou égale à start_date ;
que la période ne dépasse pas 31 jours ;
que les statuts demandés existent ;
que les IDs de véhicule, chauffeur et organisation existent en base.

La limite de 31 jours permet d'éviter les requêtes trop lourdes et les exports trop volumineux.

Le planning est destiné à un usage opérationnel : jour, semaine ou mois.
Les archives officielles, comme les feuilles de route, sont gérées par le module documentaire.

6. Normalisation des filtres

Le PlanningIndexRequest ne fait pas uniquement de la validation.
Il prépare aussi les données utiles au contrôleur.

Méthodes importantes :

planningStatuses()

Retourne les statuts demandés.

wantsAvailabilityDetails()

Indique si l'utilisateur demande les disponibilités.

planningFilters()

Retourne les filtres optionnels normalisés :

vehicle_id
driver_id
organization_id

Les IDs reçus via l'URL sont convertis en entiers.
Cela permet d'avoir une réponse JSON cohérente.

Exemple :

"filters": {
  "vehicle_id": 1
}

et non :

"filters": {
  "vehicle_id": "1"
}
7. Contrôleur

Le contrôleur du module est :

app/Http/Controllers/Api/PlanningController.php

Il contient deux méthodes principales :

index()

Retourne le planning au format JSON.

export()

Retourne le planning au format compatible Excel.

Le contrôleur ne contient pas la logique métier principale.
Il orchestre simplement :

la validation de la requête ;
la récupération de l'utilisateur connecté ;
la vérification du droit d'afficher les disponibilités ;
l'appel au service métier ;
la construction de la réponse.
8. Service métier principal

Le service principal est :

app/Services/Planning/PlanningService.php

Son rôle est de récupérer les réservations du planning selon :

la période demandée ;
les statuts ;
les filtres optionnels ;
les droits de l'utilisateur ;
la demande éventuelle de disponibilités.

La méthode principale est :

forPeriod(
    User $user,
    string $startDate,
    string $endDate,
    bool $includeAvailability = false,
    array $statuses = [],
    array $filters = []
): Collection
9. Statuts affichés par défaut

Si aucun statut n'est demandé, le planning affiche uniquement les réservations utiles à l'organisation du service :

approuve
confirme
en_conflit

Les réservations refusées ou annulées sont exclues par défaut.

Cela évite d'afficher dans le planning opérationnel des trajets qui ne doivent plus être pris en charge.

10. Filtres du planning

Le service applique trois filtres optionnels.

10.1 Filtre par véhicule
vehicle_id=1

Permet d'obtenir le planning d'un véhicule précis.

10.2 Filtre par chauffeur
driver_id=2

Permet d'obtenir le planning d'un chauffeur précis.

10.3 Filtre par organisation
organization_id=3

Permet d'obtenir le planning d'une organisation précise.

Ces filtres sont utiles pour le futur front React, par exemple avec des menus déroulants :

Tous les véhicules ;
Tous les chauffeurs ;
Toutes les organisations.
11. Règles d'accès

Le module respecte les rôles utilisateurs.

Admin et agent communal

Les rôles internes peuvent consulter l'ensemble du planning.

Ils peuvent également demander les disponibilités détaillées avec :

include_availability=1
Chauffeur

Un chauffeur ne voit que les réservations qui le concernent.

Il ne reçoit pas les détails de disponibilité des autres chauffeurs et véhicules.

Autres utilisateurs

Un utilisateur non interne ne voit que :

les réservations qu'il a demandées ;
ou celles liées à son organisation.
12. Disponibilités chauffeurs et véhicules

Les disponibilités sont calculées par :

app/Services/Planning/PlanningAvailabilityService.php

Ce service indique, pour une réservation donnée :

les chauffeurs disponibles ;
les chauffeurs indisponibles ;
les véhicules disponibles ;
les véhicules indisponibles ;
la raison d'une indisponibilité.

Exemples de raisons possibles :

chauffeur inactif ;
chauffeur déjà affecté à une autre réservation ;
chauffeur indisponible sur le créneau ;
véhicule inactif ;
véhicule déjà affecté à une autre réservation ;
véhicule indisponible sur le créneau ;
capacité insuffisante.

Ces informations sont réservées aux rôles internes.

13. Calcul de l'occupation du bus

Le module planning utilise le service :

app/Services/Reservations/ReservationOccupationService.php

Ce service centralise le calcul de l'occupation réelle du bus.

L'occupation réelle ne correspond pas seulement à :

heure de départ -> heure de retour demandée

Elle tient compte du fait que return_time représente l'heure à laquelle le bus repart depuis la destination.

Le bus reste donc occupé jusqu'à :

return_time + durée estimée du retour + marge de sécurité

Exemple :

Départ : 09:00
Retour demandé depuis destination : 11:00
Durée estimée : 30 minutes
Marge : 15 minutes
Occupation réelle : 09:00 -> 11:45

Ce calcul est utilisé pour :

le planning ;
les disponibilités ;
les conflits ;
l'assignation chauffeur/véhicule ;
la prévisualisation des réservations.

Cela évite d'avoir plusieurs calculs différents dans plusieurs classes.

14. Ressource JSON du planning

La ressource utilisée est :

app/Http/Resources/PlanningItemResource.php

Elle transforme une réservation en objet JSON exploitable.

Chaque élément contient notamment :

reservation_id
trip_date
departure_time
return_time
occupation_start
occupation_end
occupation_duration_min
status
approval_type
total_passengers
is_fully_assigned
display
organization
pickup_location
destination_location
driver
vehicle
conflicts
availability si autorisé
15. Bloc display

Le bloc display fournit des données prêtes à afficher dans le futur front.

Exemple :

"display": {
  "title": "Ecole communale de Dinant - Sortie scolaire",
  "time_label": "09:00 - 11:45",
  "status_label": "Confirme",
  "assignment_label": "Affecte",
  "color": "success"
}

Ce bloc ne remplace pas les données brutes.
Il les complète.

Le front peut donc utiliser :

les données brutes pour la logique ;
le bloc display pour l'affichage.
16. Exemple de réponse JSON

Exemple simplifié :

{
  "period": {
    "start_date": "2026-06-16",
    "end_date": "2026-06-16"
  },
  "filters": {
    "vehicle_id": 1
  },
  "data": [
    {
      "reservation_id": 12,
      "trip_date": "2026-06-16",
      "departure_time": "09:00:00",
      "return_time": "11:00:00",
      "occupation_start": "2026-06-16 09:00:00",
      "occupation_end": "2026-06-16 11:45:00",
      "occupation_duration_min": 165,
      "status": "confirme",
      "total_passengers": 11,
      "is_fully_assigned": true,
      "display": {
        "title": "Ecole communale de Dinant - Sortie scolaire",
        "time_label": "09:00 - 11:45",
        "status_label": "Confirme",
        "assignment_label": "Affecte",
        "color": "success"
      }
    }
  ]
}
17. Export Excel-compatible

Le service d'export est :

app/Services/Planning/PlanningExportService.php

L'export est disponible via :

GET /api/planning/export

Il retourne un fichier :

planning-startDate-endDate.xls

L'export actuel est compatible Excel, mais il ne s'agit pas d'un vrai fichier .xlsx natif.

Il s'agit d'un tableau HTML servi avec un type MIME compatible Excel :

application/vnd.ms-excel

Cela fonctionne pour une V1, mais une amélioration future pourrait consister à générer un vrai fichier .xlsx.

18. Tests automatisés

Les tests du module se trouvent dans :

tests/Feature/PlanningApiTest.php

Ils couvrent notamment :

l'affichage du planning par un agent ;
la validation des dates obligatoires ;
la limite de période à 31 jours ;
la visibilité limitée d'un chauffeur ;
l'absence des disponibilités pour un chauffeur ;
le filtre par statut ;
l'affichage des conflits ;
l'export Excel ;
l'export limité pour un chauffeur ;
le filtre par véhicule ;
le filtre par chauffeur ;
le filtre par organisation.

Résultat actuel :

PlanningApiTest : 12 tests OK
Tests globaux : 419 tests OK
19. Commandes de test utiles

Tester uniquement le planning :

php artisan test --filter=PlanningApiTest

Tester le calcul d'occupation :

php artisan test --filter=ReservationOccupationServiceTest

Tester toute l'application :

php artisan test
20. Exemples d'appels API

Planning global :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07

Planning avec disponibilités :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&include_availability=1

Planning par statut :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&statuses[]=confirme

Planning par véhicule :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&vehicle_id=1

Planning par chauffeur :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&driver_id=2

Planning par organisation :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&organization_id=3

Export Excel global :

GET /api/planning/export?start_date=2026-06-01&end_date=2026-06-07

Export Excel filtré par véhicule :

GET /api/planning/export?start_date=2026-06-01&end_date=2026-06-07&vehicle_id=1
21. Limites actuelles

Le module est fonctionnel, mais certaines limites restent connues.

Export Excel

L'export est compatible Excel, mais pas encore en vrai format .xlsx.

Optimisation de tournée

Le module ne fait pas encore d'optimisation avancée de tournée.

Il ne calcule pas encore :

les détours possibles ;
les regroupements de trajets ;
les arrêts intermédiaires ;
les capacités restantes pendant un trajet ;
les trajets à vide entre deux missions.
Interface utilisateur

Le backend prépare les données, mais l'interface React reste à construire.

22. Améliorations futures possibles

Améliorations envisageables :

génération d'un vrai export .xlsx ;
ajout d'une vue calendrier côté front ;
filtres visuels par véhicule, chauffeur et organisation ;
affichage des conflits sous forme d'alertes ;
affichage des créneaux indisponibles ;
suggestion de créneaux libres ;
optimisation de tournées ;
calcul des temps de repositionnement entre deux missions ;
intégration plus poussée avec les feuilles de route journalières par véhicule.
23. Résumé final

Le module Planning est aujourd'hui un module backend complet en version V1/V1+.

Il permet de consulter, filtrer et exporter les réservations utiles à l'organisation du bus communal.

Il respecte les droits d'accès, s'appuie sur un calcul centralisé de l'occupation du bus, expose des données adaptées au futur front React et dispose de tests automatisés.

Les futures évolutions concerneront principalement l'interface utilisateur, l'export .xlsx natif et l'optimisation avancée des tournées.
