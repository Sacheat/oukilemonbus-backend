# Documentation technique — Module Feuilles de route

## 1. Objectif du module

Le module Feuilles de route permet de générer les documents officiels liés aux trajets du bus communal.

Une feuille de route représente le service d’un véhicule pour une journée donnée.

Elle sert notamment :

- au suivi administratif ;
- à l’organisation du service ;
- au chauffeur ;
- à la traçabilité des missions ;
- à la présentation d’un document en cas de contrôle.

Le mode actuel vise une feuille de route journalière par véhicule.

---

## 2. Rôle dans l’application

Le module Feuilles de route dépend de plusieurs autres modules :

```text
Feuilles de route
├── Réservations
├── Véhicules
├── Chauffeurs
├── Lieux
├── Documents
├── Planning
├── Logs
└── Notifications futures
Il utilise les réservations confirmées ou planifiées pour produire un document PDF contenant les missions d’un véhicule.

3. Principe général

La feuille de route est générée à partir :

d’une date de service ;
d’un véhicule ;
des réservations associées à ce véhicule pour cette date.

Le système récupère les missions concernées, construit un contexte de génération, produit un PDF, stocke le fichier, puis crée une entrée dans le module Documents.

4. Mode journalier par véhicule

Le mode cible actuel est :

1 journée
+
1 véhicule
=
1 feuille de route

Exemple :

Feuille de route du 18/06/2026
Véhicule : 1-ABC-123

Ce choix est plus propre qu’une feuille globale sur plusieurs jours, car il limite les données présentes dans le document.

Il permet également de mieux gérer les accès chauffeurs.

5. Pourquoi une feuille par véhicule

La feuille de route est liée au service du véhicule.

Cela permet :

d’identifier clairement le véhicule utilisé ;
de regrouper les missions du jour pour ce véhicule ;
de fournir un document cohérent en cas de contrôle ;
de limiter l’accès aux chauffeurs concernés ;
de faciliter l’archivage.
6. Contenu d’une feuille de route

Une feuille de route peut contenir :

la date du service ;
la plaque du véhicule ;
l’identification du transporteur ;
le numéro de licence ;
la zone de signature du transporteur ;
le ou les conducteurs ;
les missions du jour ;
les donneurs d’ordre ;
les lieux de départ ;
les destinations ;
les horaires ;
les itinéraires principaux ;
le nombre de voyageurs ;
le kilométrage du service ;
les mentions de conservation.
7. Données utilisées

Le module utilise principalement :

les réservations ;
le véhicule ;
les chauffeurs associés aux réservations ;
les lieux de prise en charge ;
les destinations ;
les horaires ;
le nombre de passagers ;
les distances estimées ;
les informations de configuration du transporteur.
8. Configuration du transporteur

Certaines données sont configurables via le fichier :

config/route_sheets.php

Exemples de variables prévues :

ROUTE_SHEET_TRANSPORTER_NAME=
ROUTE_SHEET_TRANSPORTER_IDENTIFIER=
ROUTE_SHEET_TRANSPORTER_SIGNATURE_LABEL=
ROUTE_SHEET_LICENSE_NUMBER=
ROUTE_SHEET_EMERGENCY_CONTACT=

Cette configuration permet d’éviter d’écrire en dur les informations administratives dans le code ou dans le template.

9. Génération manuelle

La génération manuelle permet à un agent ou administrateur de générer une feuille de route à la demande.

Exemple de payload API :

{
  "service_date": "2026-06-18",
  "vehicle_id": 1
}

Endpoint utilisé :

POST /api/documents/route-sheets/generate

Le mode historique avec start_date / end_date peut rester supporté pour compatibilité, mais le mode principal est désormais service_date + vehicle_id.

10. Génération par commande Artisan

Une commande Artisan permet aussi de générer les feuilles de route journalières.

Exemple :

php artisan route-sheets:generate-daily 2026-06-18

Cette commande génère les feuilles de route pour la date donnée.

Une option permet de forcer une nouvelle génération :

php artisan route-sheets:generate-daily 2026-06-18 --force

L’option --force sert à générer une nouvelle version même si une feuille existe déjà.

11. Génération automatique

Une génération automatique peut être planifiée.

Principe retenu :

Tous les jours à 18h00, heure de Bruxelles,
le système génère les feuilles de route du lendemain.

Le système génère une feuille uniquement pour les véhicules ayant au moins une réservation concernée.

Cela évite de produire des documents vides pour des véhicules sans mission.

12. Risque de doublons

La génération automatique doit éviter les doublons inutiles.

Règle souhaitable :

Si une feuille existe déjà pour la date et le véhicule,
ne pas générer une nouvelle version automatiquement,
sauf si le mode forcé est demandé ou si une modification le justifie.

La génération manuelle avec --force peut permettre de créer une nouvelle version volontairement.

13. Versioning

Les feuilles de route sont versionnées afin d’éviter l’écrasement d’un document existant.

Exemple :

route-sheet-2026-06-18-vehicle-1-v001.pdf
route-sheet-2026-06-18-vehicle-1-v002.pdf

Le versioning est important car une feuille de route générée représente un snapshot historique.

Si des réservations changent après génération, l’ancienne feuille doit rester traçable.

14. Snapshot historique

Une feuille de route générée représente l’état du service au moment de sa génération.

Si une réservation est modifiée ensuite, le PDF déjà généré ne change pas automatiquement.

Une nouvelle génération peut produire une nouvelle version.

Ce principe permet de conserver l’historique documentaire.

15. Association aux réservations

Une feuille de route est liée aux réservations qu’elle contient.

Cette liaison passe par une relation entre le document et les réservations.

Exemple :

Feuille de route du 18/06/2026 - véhicule 1
├── Réservation 12
├── Réservation 13
└── Réservation 17

Cette association permet :

de savoir quelles missions sont reprises dans le document ;
de contrôler les droits d’accès ;
de retrouver les documents liés à une réservation ;
de garder une trace administrative.
16. Accès chauffeur

Un chauffeur peut accéder à une feuille de route si elle contient au moins une mission qui lui est assignée.

Cela permet au chauffeur de récupérer le document utile à son service.

Il ne doit pas avoir accès à toutes les feuilles de route de tous les véhicules.

17. Accès admin et agent communal

Les rôles internes peuvent accéder aux feuilles de route.

Ils peuvent :

générer les feuilles ;
consulter les documents ;
télécharger les PDF ;
vérifier les missions ;
régénérer si nécessaire.
18. Accès organisation

L’accès des organisations aux feuilles de route doit être traité avec prudence.

Une feuille de route peut contenir plusieurs missions, potentiellement liées à plusieurs organisations.

Par défaut, il est préférable de ne pas exposer une feuille complète à une organisation si elle contient des données qui ne la concernent pas.

19. Service documentaire

La génération de feuille de route s’intègre au module Documents.

Le processus général est :

Requête API ou commande Artisan
↓
Service de feuille de route
↓
Construction du contexte
↓
Génération PDF
↓
Stockage fichier
↓
Création Document
↓
Association aux réservations
20. Construction du contexte

Le contexte de génération rassemble toutes les données nécessaires au template.

Il peut contenir :

date du service ;
véhicule ;
chauffeur(s) ;
réservations ;
lieux ;
destinations ;
horaires ;
passagers ;
distances ;
transporteur ;
numéro de licence ;
mentions légales.

Ce contexte est ensuite transmis au template PDF.

21. Template PDF

Le template journalier est situé dans les vues documents.

Exemple :

resources/views/documents/route_sheets/daily.blade.php

Le template doit principalement s’occuper de la présentation.

La logique métier doit rester dans les services et builders de contexte.

22. Lien avec le planning

Le planning et les feuilles de route utilisent les mêmes données métier :

réservations ;
horaires ;
véhicules ;
chauffeurs ;
lieux ;
occupation du bus.

Le planning sert à organiser le service.

La feuille de route sert à produire le document officiel lié au service.

23. Lien avec l’occupation du bus

Le calcul d’occupation du bus est centralisé dans :

app/Services/Reservations/ReservationOccupationService.php

Ce calcul permet de savoir pendant combien de temps le véhicule est réellement occupé.

Il tient compte :

du départ ;
du retour depuis la destination ;
de la durée estimée ;
d’une marge de sécurité.

Ce calcul est utile pour éviter les chevauchements et organiser correctement les missions.

24. Archivage

Les feuilles de route sont des documents officiels.

Elles doivent être conservées selon les règles métier ou légales retenues.

Une mention de conservation de 3 ans peut être présente dans le document.

L’archivage concerne le document généré, pas l’API planning.

Le planning est un outil opérationnel, tandis que la feuille de route est un document à conserver.

25. Logs

Les actions importantes peuvent être journalisées.

Exemples :

génération automatique ;
génération manuelle ;
génération forcée ;
erreur de génération ;
document créé ;
absence de réservation pour un véhicule/date ;
téléchargement.

Les logs facilitent le suivi et la maintenance.

26. Notifications futures

Les notifications pourront prévenir :

un chauffeur qu’une feuille de route est disponible ;
un agent qu’une génération automatique a échoué ;
un administrateur qu’un document a été régénéré ;
les utilisateurs concernés en cas de changement important.

Ce point dépend du module Notifications.

27. Tests

Les tests liés aux feuilles de route doivent vérifier :

génération via API ;
génération via commande Artisan ;
génération journalière ;
versioning ;
association aux réservations ;
absence d’exposition du chemin privé ;
règles d’accès ;
génération automatique ;
génération forcée ;
contenu du contexte.
28. Limites actuelles

Certaines limites peuvent encore exister :

optimisation de tournée non avancée ;
vraie liste nominative des passagers non gérée ;
modèle légal exact à confirmer si nécessaire ;
accès organisation à maintenir strict ;
gestion fine du remplacement d’une ancienne version ;
archivage légal à préciser selon le cahier des charges.
29. Améliorations futures

Améliorations possibles :

marquer automatiquement une ancienne version comme remplacée ;
améliorer la recherche des feuilles archivées ;
ajouter des filtres par véhicule et date ;
notifier automatiquement les chauffeurs ;
générer une version par chauffeur si nécessaire ;
intégrer un vrai module d’archivage légal ;
améliorer le PDF avec des informations légales complémentaires ;
produire un export complémentaire pour les agents.
Espace chauffeur : feuille de la semaine et feuilles officielles paginées

- GET /api/route-sheets/driver?week=AAAA-MM-JJ[&driver_id=] (permission documents.view) : PDF de la semaine
  (lundi → dimanche contenant la date) d'un chauffeur, généré à la demande, jamais enregistré (toujours à jour).
  Chauffeur : lui-même (un autre driver_id : 403) ; agent ou admin : driver_id obligatoire (422 sinon) ; autres : 403.
  Contenu (template route_sheets.driver, RouteSheetContextBuilder::buildForDriverWeek) : ses seules missions
  `confirme`, un bloc par jour du lundi au vendredi (même vide), samedi et dimanche seulement s'ils ont des missions ;
  par trajet, le prévu (horaire, bus, organisation et contact, adresses, passagers, km, objet) puis des cases à remplir
  à la main (réalisé ou non, heures et passagers réels, compteur kilométrique, remarques) ; signature du chauffeur.
  C'est le support papier si le téléphone ne fonctionne pas ; l'archive officielle reste la feuille journalière.
- GET /api/route-sheets?date_from=&date_to=&page= (documents.view) : feuilles journalières officielles visibles
  (chauffeur : celles qui contiennent au moins une de ses missions), dernière version seulement pour chaque jour et
  véhicule, `service_date` décroissante, 15 par page. Champs : id, service_date, vehicle, version, file_name,
  generated_at, trip_count, own_trip_count (chauffeur connecté, null sinon). Téléchargement :
  GET /documents/{id}/download.
- Colonnes `documents.service_date` et `documents.vehicle_id` (migration 2026_10_02_000001, reprise des feuilles
  existantes depuis leur chemin) : remplies par generateForServiceDateVehicle, pour filtrer et paginer en base.

Points relevés, non traités dans cette PR :
- la génération automatique de 20:00 ignore un véhicule dont la feuille du lendemain existe déjà : une mission
  modifiée ou ajoutée après 20:00 n'est pas reportée sur la feuille officielle (régénération à prévoir, avec --force
  ou à chaque changement de mission) ;
- GET /documents n'est pas paginé (Collection complète) : à paginer quand le volume grandira.

30. Résumé final

Le module Feuilles de route permet de générer les documents officiels liés aux services journaliers des véhicules.

Le mode actuel est basé sur une feuille journalière par véhicule.

Ce module est relié aux réservations, chauffeurs, véhicules, documents, planning et logs.

Il constitue un élément important du suivi administratif et opérationnel du service du bus communal.
