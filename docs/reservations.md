# Documentation technique — Module Réservations

## 1. Objectif du module

Le module Réservations est le coeur métier de l'application OuKiLéMonBus.

Il permet de gérer les demandes d'utilisation du bus communal, depuis leur création jusqu'à leur traitement administratif et opérationnel.

Une réservation représente une demande de trajet avec le bus communal, comprenant notamment :

- une organisation demandeuse ;
- un utilisateur demandeur ;
- une date de trajet ;
- une heure de départ ;
- une heure de retour depuis la destination ;
- un lieu de prise en charge ;
- une destination ;
- un nombre de passagers ;
- un statut ;
- éventuellement un chauffeur ;
- éventuellement un véhicule ;
- éventuellement des conflits ;
- éventuellement une décision du Collège ;
- éventuellement des documents liés.

---

## 2. Rôle du module dans l'application

Le module Réservations est utilisé par plusieurs autres modules :

```text
Réservations
├── Planning
├── Documents
├── Délibérations
├── Feuilles de route
├── Chauffeurs
├── Véhicules
├── Indisponibilités
├── Conflits
├── Logs
└── Notifications futures
Il sert donc de point central entre :

la demande initiale ;
la validation métier ;
l'administration communale ;
l'organisation du service ;
la production documentaire ;
le planning ;
le suivi opérationnel.
3. Données principales d'une réservation

Une réservation contient généralement les informations suivantes :

DonnéeRôle
requested_by_user_idUtilisateur qui a introduit la demande
organization_idOrganisation liée à la demande
pickup_location_idLieu de prise en charge
destination_location_idDestination
driver_idChauffeur affecté, si disponible
vehicle_idVéhicule affecté, si disponible
trip_dateDate du trajet
departure_timeHeure de départ
return_timeHeure de retour depuis la destination
nb_childrenNombre d'enfants
nb_adultsNombre d'adultes
trip_purposeMotif du trajet
activity_typeType d'activité
distance_kmDistance estimée
estimated_duration_minDurée estimée
statusStatut de la réservation
approval_typeType d'approbation
4. Sens métier de return_time

Point important :

return_time ne représente pas forcément l'heure à laquelle le bus est revenu au dépôt.

Dans le projet, return_time représente :

l'heure à laquelle le bus repart depuis la destination pour rentrer.

Cela signifie que le bus reste encore occupé après return_time.

L'occupation réelle du bus est donc calculée comme ceci :

departure_time
→ return_time
→ durée estimée du trajet retour
→ marge de sécurité

Exemple :

Départ : 09:00
Retour depuis destination : 11:00
Durée estimée : 30 minutes
Marge : 15 minutes

Occupation réelle du bus : 09:00 → 11:45

Ce calcul est centralisé dans :

app/Services/Reservations/ReservationOccupationService.php
5. Statuts des réservations

Les réservations évoluent avec différents statuts.

Les statuts principaux utilisés dans le projet sont notamment :

en_attente
approuve
confirme
en_conflit
refuse
annule

Le statut permet de savoir où se trouve la réservation dans le processus métier.

6. Cycle de vie général d'une réservation

Cycle simplifié :

Création de la demande
↓
Validation des données
↓
Prévisualisation / détection des conflits
↓
Approbation automatique ou passage administratif
↓
Affectation chauffeur / véhicule
↓
Confirmation
↓
Apparition dans le planning
↓
Génération éventuelle de documents
↓
Feuille de route

Selon le type d'organisation et les règles métier, certaines réservations peuvent être approuvées automatiquement, tandis que d'autres doivent passer par une décision du Collège.

7. Types d'approbation

Le champ approval_type permet de distinguer le type de validation.

Exemples possibles :

approbation automatique ;
approbation administrative ;
décision du Collège.

Les réservations communales ou scolaires peuvent suivre un circuit différent des demandes externes.

8. FormRequests

Les FormRequests servent à valider les données entrantes avant qu'elles arrivent dans les services métier.

Leur rôle est de vérifier :

que les champs obligatoires sont présents ;
que les dates ont le bon format ;
que les heures sont valides ;
que les IDs existent en base ;
que les valeurs envoyées correspondent aux énumérations autorisées.

Cela évite de placer toute la validation directement dans les contrôleurs.

9. Services principaux liés aux réservations
9.1 ReservationService

Service principal de gestion des réservations.

Il orchestre probablement les opérations importantes comme :

création ;
mise à jour ;
changement de statut ;
logique métier globale ;
appels aux autres services.
9.2 ReservationPreviewService

Ce service sert à prévisualiser une demande avant validation complète.

Il permet notamment :

de calculer la distance ;
d'estimer la durée ;
de détecter les conflits potentiels ;
de vérifier certaines règles métier ;
de proposer éventuellement un prochain créneau disponible.
9.3 ReservationAssignmentService

Ce service gère l'affectation d'un chauffeur et d'un véhicule.

Il prend en compte :

les disponibilités ;
les indisponibilités ;
les chevauchements ;
la capacité du véhicule ;
l'historique d'affectation.
9.4 ReservationOccupationService

Ce service centralise le calcul de l'occupation réelle du bus.

Il évite de dupliquer les calculs horaires dans plusieurs classes.

Il sert notamment à :

détecter les chevauchements ;
calculer la durée réelle d'occupation ;
proposer des créneaux disponibles ;
alimenter le planning ;
vérifier les affectations.
10. Détection des conflits

Une réservation peut générer des conflits.

Exemples :

horaire qui chevauche une autre réservation ;
aucun chauffeur disponible ;
aucun véhicule disponible ;
véhicule trop petit ;
chauffeur indisponible ;
véhicule indisponible ;
distance ou durée impossible à calculer ;
demande hors règle métier.

Les conflits permettent aux agents communaux de savoir qu'une réservation demande une attention particulière.

Ils sont également visibles dans le planning.

11. Conflits et planning

Les réservations ayant le statut en_conflit sont affichées dans le planning.

Cela permet aux agents de visualiser directement les problèmes à résoudre.

Le planning peut donc afficher :

les réservations approuvées ;
les réservations confirmées ;
les réservations en conflit.

Par défaut, les réservations refusées ou annulées ne sont pas affichées dans le planning opérationnel.

12. Affectation chauffeur et véhicule

Une réservation peut être associée à :

un chauffeur ;
un véhicule.

L'affectation doit vérifier :

que le chauffeur est actif ;
que le véhicule est actif ;
que le véhicule a assez de places ;
que le chauffeur n'est pas indisponible ;
que le véhicule n'est pas indisponible ;
qu'il n'y a pas de chevauchement avec une autre réservation.

Quand une affectation change, un historique peut être conservé.

13. Historique d'affectation

L'historique d'affectation permet de garder une trace des changements.

Il peut répondre à des questions comme :

qui a changé le chauffeur ?
quand ?
quel était l'ancien chauffeur ?
quel est le nouveau chauffeur ?
quel était l'ancien véhicule ?
quel est le nouveau véhicule ?
pourquoi ce changement a été fait ?

Ce point est important pour la traçabilité administrative.

14. Règles d'accès aux réservations

Les réservations ne sont pas visibles de la même manière pour tous les utilisateurs.

Admin et agent communal

Ils peuvent consulter l'ensemble des réservations.

Chauffeur

Il peut consulter les réservations qui lui sont assignées.

Responsable d'organisation

Il peut consulter les réservations liées à son organisation.

Demandeur

Il peut consulter les réservations qu'il a introduites.

Ces règles sont importantes pour protéger les données et éviter qu'un utilisateur voie des trajets qui ne le concernent pas.

15. Réservations et organisations

Une réservation est liée à une organisation.

L'organisation influence certaines règles métier :

demande communale ;
école communale ;
ASBL ;
organisation externe ;
priorité éventuelle ;
quota annuel.

Certaines organisations peuvent bénéficier d'un circuit d'approbation plus simple ou automatique.

16. Quotas

Le projet prévoit une logique de quotas pour certaines organisations.

Un quota peut limiter le nombre de réservations sur une période donnée, par exemple par année.

Le service de réservation peut vérifier si une organisation peut encore introduire une demande.

17. Lieux et distance

Une réservation contient :

un lieu de prise en charge ;
une destination.

Ces lieux peuvent contenir des coordonnées géographiques.

Lorsque les coordonnées sont disponibles, le système peut estimer :

la distance ;
la durée du trajet.

Ces informations sont utilisées pour :

vérifier certaines règles métier ;
calculer l'occupation du bus ;
alimenter la feuille de route ;
aider le futur front à ouvrir un itinéraire.
18. Lien avec les documents

Les réservations peuvent être liées à des documents.

Exemples :

délibération ;
feuille de route ;
document administratif.

Les feuilles de route journalières sont liées aux réservations concernées.

Cette relation permet de savoir quelles réservations apparaissent dans un document donné.

19. Lien avec les délibérations

Certaines réservations peuvent nécessiter une décision du Collège.

Dans ce cas, une décision peut être créée et associée à la demande.

Selon le résultat :

la réservation peut être approuvée ;
la réservation peut être refusée ;
un document de délibération peut être généré.

La délibération de refus doit être confirmée ou finalisée selon les modèles disponibles.

20. Lien avec les feuilles de route

Une réservation confirmée et affectée peut apparaître dans une feuille de route.

Le système actuel vise une feuille de route journalière par véhicule.

La feuille de route reprend les missions du véhicule pour une journée donnée.

Elle peut être générée :

manuellement ;
automatiquement pour le lendemain ;
avec une option de régénération forcée si nécessaire.
21. Lien avec les logs

Certaines actions liées aux réservations peuvent être journalisées.

Exemples :

conflit détecté ;
changement de statut ;
réservation créée ;
réservation mise à jour ;
document généré ;
erreur métier.

Les logs permettent de faciliter le suivi et le débogage.

22. Lien avec les notifications

Les notifications restent à finaliser.

Elles pourront être déclenchées lors de différents événements :

nouvelle réservation ;
changement de statut ;
réservation approuvée ;
réservation refusée ;
conflit détecté ;
chauffeur affecté ;
document disponible.

Ce module devra préciser :

qui reçoit la notification ;
quand ;
par quel canal ;
avec quel message.
23. Exemple de scénario métier

Exemple : une école communale demande le bus.

1. Un utilisateur introduit une demande.
2. Le système valide les données.
3. Le système calcule la distance et la durée.
4. Le système vérifie les règles métier.
5. La demande peut être approuvée automatiquement.
6. Un chauffeur et un véhicule peuvent être affectés.
7. La réservation apparaît dans le planning.
8. La réservation apparaît dans la feuille de route du véhicule.
9. Les documents nécessaires peuvent être générés.
24. Tests liés aux réservations

Les tests permettent de vérifier que les règles métier restent stables.

Les tests peuvent couvrir :

création de réservation ;
validation des données ;
statuts ;
conflits ;
affectation chauffeur/véhicule ;
occupation du bus ;
visibilité selon rôle ;
intégration avec le planning ;
intégration avec les documents.

Les tests existants sont importants car le module réservation est utilisé par presque tous les autres modules.

25. Limites actuelles

Certaines limites restent connues.

Optimisation avancée

Le module ne fait pas encore de vraie optimisation de tournées.

Il ne gère pas encore :

les regroupements de passagers ;
les arrêts multiples ;
les détours ;
les capacités restantes en cours de tournée ;
les trajets à vide entre deux missions.
Liste nominative des passagers

Le système connaît actuellement les nombres de passagers, mais pas forcément une liste nominative.

Si une liste nominative devient nécessaire légalement ou métier, il faudra ajouter un modèle dédié.

Interface utilisateur

Le backend prépare les données, mais le front React doit encore proposer les écrans permettant de créer, consulter et gérer les réservations.

26. Améliorations futures

Améliorations possibles :

finaliser les notifications liées aux réservations ;
ajouter une liste nominative des passagers si nécessaire ;
améliorer l'optimisation des tournées ;
proposer automatiquement des créneaux alternatifs plus intelligents ;
améliorer la gestion des refus ;
ajouter des vues front pour les agents, chauffeurs et organisations ;
renforcer les logs métier ;
ajouter une documentation utilisateur.
27. Résumé final

Le module Réservations est le centre de l'application OuKiLéMonBus.

Il relie les utilisateurs, organisations, lieux, chauffeurs, véhicules, conflits, documents, planning et feuilles de route.

La logique métier principale est déjà en place : validation, statuts, conflits, affectations, occupation du bus et intégration avec le planning.

Les prochaines étapes concernent surtout les notifications, la documentation, le front React et les optimisations avancées.
