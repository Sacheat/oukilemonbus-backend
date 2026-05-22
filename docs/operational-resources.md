# Documentation technique — Ressources opérationnelles

## 1. Objectif du document

Ce document présente les modules liés à l'organisation concrète du service du bus communal.

Il regroupe les éléments qui permettent de passer d'une demande de réservation à un trajet réellement exploitable :

- chauffeurs ;
- véhicules ;
- lieux ;
- indisponibilités ;
- conflits ;
- affectations chauffeur/véhicule.

Ces modules sont fortement liés aux réservations, au planning, aux feuilles de route et au calcul d'occupation du bus.

---

## 2. Vue d'ensemble

Les ressources opérationnelles servent à répondre à plusieurs questions métier :

```text
Quel véhicule peut effectuer le trajet ?
Quel chauffeur peut conduire ?
Le véhicule est-il disponible ?
Le chauffeur est-il disponible ?
Y a-t-il un conflit d'horaire ?
Le véhicule a-t-il assez de places ?
Quels lieux sont concernés par le trajet ?
Ces réponses permettent ensuite :

d'affecter correctement une réservation ;
d'éviter les chevauchements ;
d'alimenter le planning ;
de générer les feuilles de route ;
de fournir des informations exploitables au futur front React.
3. Chauffeurs

Le module Chauffeurs représente les personnes autorisées à conduire le bus communal.

Un chauffeur est lié à un utilisateur de l'application.

Cela permet :

de l'authentifier ;
de lui attribuer un rôle ;
de lui associer des réservations ;
de limiter son accès à ses propres missions.
4. Données principales d'un chauffeur

Un chauffeur peut contenir notamment :

DonnéeRôle
user_idUtilisateur lié au chauffeur
licence_numberNuméro de permis ou référence administrative
licence_expiry_dateDate d'expiration du permis
is_activeIndique si le chauffeur peut être utilisé

Le champ is_active est important : un chauffeur inactif ne doit pas être proposé pour une affectation.

5. Chauffeur et utilisateur

Le chauffeur n'est pas seulement une fiche technique.

Il est lié à un compte utilisateur.

Cela permet :

de connecter le chauffeur à l'application ;
de lui appliquer le rôle chauffeur ;
de lui afficher uniquement ses trajets ;
de lui permettre d'accéder à ses feuilles de route.
6. Accès chauffeur

Un chauffeur ne doit pas voir tout le planning.

Il ne doit voir que :

les réservations auxquelles il est affecté ;
ses propres trajets ;
les feuilles de route qui contiennent au moins une de ses missions.

Il ne doit pas recevoir :

les disponibilités complètes des autres chauffeurs ;
les documents administratifs qui ne le concernent pas ;
les trajets des autres chauffeurs.
7. Véhicules

Le module Véhicules représente les bus ou véhicules disponibles pour effectuer les trajets.

Un véhicule est utilisé dans :

les réservations ;
les affectations ;
le planning ;
les feuilles de route ;
les conflits de disponibilité.
8. Données principales d'un véhicule

Un véhicule peut contenir notamment :

DonnéeRôle
licence_platePlaque d'immatriculation
capacityNombre de places disponibles
is_activeIndique si le véhicule peut être utilisé

Le champ capacity est utilisé pour vérifier que le véhicule peut accueillir les passagers.

Le champ is_active permet d'exclure un véhicule du planning ou des affectations.

9. Capacité du véhicule

Lorsqu'une réservation est affectée à un véhicule, le système doit vérifier que le véhicule possède assez de places.

La capacité nécessaire peut inclure :

les enfants ;
les adultes ;
éventuellement le chauffeur ou accompagnant selon la règle retenue.

Exemple :

10 enfants
2 adultes
= au minimum 12 places passagers nécessaires

Si la capacité est insuffisante, un conflit peut être généré.

10. Lieux

Le module Lieux représente les adresses utilisées dans les réservations.

Il peut s'agir :

du lieu de prise en charge ;
de la destination.

Les lieux sont utilisés par :

les réservations ;
le calcul de distance ;
le calcul de durée ;
les feuilles de route ;
le futur front pour ouvrir un itinéraire.
11. Données principales d'un lieu

Un lieu peut contenir notamment :

DonnéeRôle
formatted_addressAdresse complète ou formatée
cityVille
countryPays
latitudeLatitude
longitudeLongitude
is_verifiedIndique si le lieu est validé

Les coordonnées géographiques permettent de calculer une distance ou d'ouvrir un itinéraire.

12. Validation des lieux

Un lieu doit idéalement être vérifié avant d'être utilisé dans une réservation.

Cela évite :

les adresses incorrectes ;
les destinations ambiguës ;
les erreurs de calcul de distance ;
les feuilles de route imprécises.

Le champ is_verified permet de distinguer les lieux validés des lieux à contrôler.

13. Indisponibilités

Le module Indisponibilités permet de bloquer un chauffeur ou un véhicule sur une période donnée.

Une indisponibilité peut concerner :

un chauffeur ;
un véhicule.

Elle permet d'éviter qu'une ressource soit affectée à une réservation alors qu'elle n'est pas disponible.

14. Exemples d'indisponibilités

Exemples pour un chauffeur :

congé ;
maladie ;
formation ;
indisponibilité personnelle ;
permis expiré ou non valide.

Exemples pour un véhicule :

entretien ;
panne ;
contrôle technique ;
nettoyage ;
indisponibilité administrative.
15. Utilisation des indisponibilités

Les indisponibilités sont prises en compte dans :

l'affectation chauffeur/véhicule ;
le planning ;
les disponibilités affichées aux agents ;
la détection des conflits.

Exemple :

Un véhicule est indisponible de 08:00 à 12:00.
Une réservation demande ce véhicule de 09:00 à 10:30.
→ Le véhicule ne doit pas être proposé.
16. Conflits

Les conflits représentent des problèmes métier détectés autour d'une réservation.

Ils permettent de signaler qu'une réservation ne peut pas être traitée normalement sans intervention.

17. Exemples de conflits

Exemples :

chevauchement horaire ;
aucun chauffeur disponible ;
aucun véhicule disponible ;
véhicule inactif ;
chauffeur inactif ;
véhicule de capacité insuffisante ;
indisponibilité du chauffeur ;
indisponibilité du véhicule ;
distance impossible à calculer ;
durée estimée manquante ;
réservation hors règle métier.
18. Conflits et planning

Les conflits sont visibles dans le module planning.

Cela permet aux agents communaux de repérer rapidement les réservations problématiques.

Une réservation en conflit peut apparaître dans le planning avec :

son statut ;
son type de conflit ;
son message ;
son état résolu ou non.
19. Conflits et résolution

Un conflit peut être considéré comme résolu lorsqu'une action corrige le problème.

Exemples :

affecter un autre chauffeur ;
changer le véhicule ;
modifier l'heure ;
corriger une adresse ;
refuser la réservation ;
confirmer une dérogation.

La résolution des conflits doit rester traçable.

20. Affectation chauffeur/véhicule

L'affectation consiste à associer une réservation à :

un chauffeur ;
un véhicule.

Elle transforme une demande validée en mission réellement exploitable.

21. Règles d'affectation

Avant d'affecter une ressource, le système doit vérifier :

que le chauffeur est actif ;
que le véhicule est actif ;
que le véhicule a assez de places ;
que le chauffeur n'est pas indisponible ;
que le véhicule n'est pas indisponible ;
qu'il n'existe pas de chevauchement avec une autre réservation ;
que le créneau réel d'occupation est compatible.
22. Calcul d'occupation du bus

L'affectation ne se base pas uniquement sur les heures brutes.

Le système utilise le service :

app/Services/Reservations/ReservationOccupationService.php

Ce service calcule l'occupation réelle du bus.

Le return_time représente l'heure à laquelle le bus repart depuis la destination.

L'occupation réelle inclut donc :

départ
→ retour depuis destination
→ durée estimée
→ marge de sécurité

Exemple :

Départ : 09:00
Retour depuis destination : 11:00
Durée estimée : 30 minutes
Marge : 15 minutes
Occupation réelle : 09:00 → 11:45
23. Chevauchement

Deux réservations se chevauchent si leurs créneaux réels d'occupation se croisent.

Exemple :

Réservation A : 09:00 → 11:45
Réservation B : 10:30 → 12:00
→ conflit

Mais :

Réservation A : 09:00 → 11:45
Réservation B : 11:45 → 13:00
→ pas de chevauchement direct
24. Disponibilités dans le planning

Le module planning peut afficher les disponibilités des chauffeurs et véhicules pour les rôles internes.

Cela permet à un agent communal de voir :

quelles ressources sont disponibles ;
quelles ressources sont indisponibles ;
pourquoi elles sont indisponibles.

Exemples de raisons :

chauffeur inactif ;
véhicule inactif ;
chauffeur déjà affecté ;
véhicule déjà utilisé ;
indisponibilité déclarée ;
capacité insuffisante.
25. Historique d'affectation

L'historique d'affectation permet de conserver une trace des changements.

Il peut indiquer :

qui a fait le changement ;
quand ;
ancien chauffeur ;
nouveau chauffeur ;
ancien véhicule ;
nouveau véhicule ;
raison du changement.

Cet historique est important pour la traçabilité.

26. Lien avec les feuilles de route

Les feuilles de route dépendent des affectations.

Une feuille de route journalière par véhicule contient les réservations liées à ce véhicule pour une date donnée.

Elle peut donc reprendre :

le véhicule ;
le ou les chauffeurs ;
les missions ;
les horaires ;
les lieux ;
les destinations ;
les passagers ;
les distances.
27. Lien avec les documents

Les affectations et réservations alimentent les documents.

Exemples :

une feuille de route utilise le véhicule et le chauffeur affectés ;
une délibération utilise les données de la réservation ;
un document peut être lié à plusieurs réservations.
28. Lien avec les logs

Les événements opérationnels importants peuvent être loggés.

Exemples :

affectation créée ;
affectation modifiée ;
conflit détecté ;
chauffeur indisponible ;
véhicule indisponible ;
génération de feuille de route ;
erreur d'affectation.

Ces logs facilitent le diagnostic.

29. Lien avec les notifications

Les notifications pourront être utilisées pour prévenir les personnes concernées.

Exemples :

chauffeur affecté à une mission ;
chauffeur retiré d'une mission ;
conflit détecté ;
agent informé qu'aucun véhicule n'est disponible ;
feuille de route disponible.
30. Règles d'accès
Admin et agent communal

Peuvent consulter et gérer les ressources opérationnelles.

Chauffeur

Peut consulter ses propres missions.

Organisation / demandeur

Ne doit pas accéder aux informations internes des chauffeurs et véhicules, sauf données strictement nécessaires à sa demande.

31. Tests à prévoir ou maintenir

Les tests doivent vérifier :

qu'un véhicule inactif n'est pas proposé ;
qu'un chauffeur inactif n'est pas proposé ;
qu'une indisponibilité bloque une ressource ;
qu'un chevauchement est détecté ;
qu'une capacité insuffisante crée un conflit ;
qu'un chauffeur ne voit que ses missions ;
qu'un export respecte les droits ;
que le planning affiche correctement les disponibilités.
32. Limites actuelles

Certaines limites restent possibles :

optimisation de tournée non avancée ;
pas encore de gestion fine des trajets à vide ;
pas encore de calcul multi-arrêts ;
pas encore de liste nominative de passagers ;
pas encore de tableau front complet pour gérer les ressources ;
indisponibilités à enrichir si nécessaire.
33. Améliorations futures

Améliorations possibles :

écran front de gestion des véhicules ;
écran front de gestion des chauffeurs ;
calendrier des indisponibilités ;
suggestion automatique du meilleur véhicule ;
suggestion automatique du meilleur chauffeur ;
optimisation de tournées ;
calcul du temps de repositionnement entre deux missions ;
gestion d'arrêts multiples ;
meilleure traçabilité des changements d'affectation.
34. Résumé final

Les ressources opérationnelles permettent de transformer une réservation validée en mission concrète.

Elles regroupent les chauffeurs, véhicules, lieux, indisponibilités, conflits et affectations.

Ces modules sont essentiels pour organiser le service du bus communal, éviter les erreurs de planning et produire des feuilles de route cohérentes.
