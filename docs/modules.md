# Vue d'ensemble des modules — OuKiLéMonBus

## 1. Objectif du document

Ce document présente les principaux modules backend de l'application OuKiLéMonBus.

Il sert à donner une vision globale de l'état du projet, des responsabilités de chaque module et des éléments encore à finaliser.

L'application est construite avec Laravel et expose principalement une API destinée à être utilisée par un futur front React.

---

## 2. Architecture générale

Le backend suit une organisation en couches :

```text
Routes API
↓
Controllers
↓
FormRequests
↓
Services métier
↓
Models Eloquent
↓
Resources API
↓
Tests automatisésCette structure permet de séparer :

la réception des requêtes HTTP ;
la validation des données ;
la logique métier ;
l'accès aux données ;
le format des réponses API ;
les tests.
3. Modules déjà présents
3.1 Authentification, utilisateurs, rôles et permissions

Le projet contient une gestion des utilisateurs, des rôles et des permissions.

Les permissions permettent de protéger les routes API selon le profil de l'utilisateur.

Exemples de rôles présents dans le projet :

admin ;
agent communal ;
chauffeur ;
responsable d'organisation ;
utilisateur demandeur.

Ce module est essentiel car il conditionne l'accès aux réservations, documents, planning et futures notifications.

3.2 Organisations et affiliations

Le module organisations permet de gérer les structures qui effectuent des demandes de réservation.

Exemples :

écoles communales ;
ASBL ;
organisations externes.

Les organisations peuvent être liées à des utilisateurs.

Ce lien permet ensuite de déterminer quelles réservations un utilisateur peut consulter.

3.3 Réservations

Le module réservations constitue le coeur métier de l'application.

Il permet de gérer les demandes d'utilisation du bus communal.

Une réservation contient notamment :

une date de trajet ;
une heure de départ ;
une heure de retour depuis la destination ;
une organisation ;
un demandeur ;
un lieu de prise en charge ;
une destination ;
un nombre d'enfants ;
un nombre d'adultes ;
un statut ;
éventuellement un chauffeur ;
éventuellement un véhicule.

Les statuts permettent de suivre l'évolution de la demande.

Exemples :

en attente ;
approuvée ;
confirmée ;
en conflit ;
refusée ;
annulée.
3.4 Règles métier des réservations

Plusieurs règles métier sont déjà prises en compte dans le backend.

Exemples :

validation des dates ;
limitation de certaines demandes ;
contrôle des quotas ;
gestion des conflits ;
calcul de distance et durée estimée ;
contrôle de disponibilité du bus ;
gestion des demandes communales et non communales.

Certaines règles peuvent conduire à une validation automatique, tandis que d'autres peuvent nécessiter une décision du Collège.

3.5 Chauffeurs

Le module chauffeurs permet de représenter les personnes pouvant conduire le bus.

Un chauffeur est lié à un utilisateur.

Les chauffeurs peuvent être actifs ou inactifs.

Ils peuvent être affectés à des réservations.

Dans le planning, un chauffeur ne voit que les trajets qui le concernent.

3.6 Véhicules

Le module véhicules représente le ou les véhicules disponibles pour les trajets.

Un véhicule possède notamment :

une plaque d'immatriculation ;
une capacité ;
un statut actif/inactif.

Les véhicules sont utilisés dans :

les réservations ;
les affectations ;
les feuilles de route ;
le planning ;
les conflits de disponibilité.
3.7 Lieux

Le module lieux permet de gérer les adresses utilisées dans les réservations.

Il peut s'agir :

du lieu de prise en charge ;
de la destination.

Les lieux peuvent contenir des informations géographiques comme latitude et longitude.

Ces informations servent aux calculs de distance, de durée estimée et, plus tard, à l'ouverture d'itinéraires côté front.

3.8 Indisponibilités

Le module indisponibilités permet de bloquer un chauffeur ou un véhicule sur une période donnée.

Une indisponibilité peut concerner :

un chauffeur ;
ou un véhicule.

Elle est prise en compte lors :

des affectations ;
du planning ;
des disponibilités ;
de la détection de conflits.
3.9 Conflits de réservation

Le module conflits permet de signaler des problèmes liés aux réservations.

Exemples de conflits :

chevauchement horaire ;
aucun chauffeur disponible ;
aucun véhicule disponible ;
capacité insuffisante ;
distance non calculée ;
créneau impossible.

Les conflits sont visibles dans le planning et peuvent aider les agents à prendre une décision.

3.10 Affectation chauffeur / véhicule

Le projet permet d'affecter un chauffeur et un véhicule à une réservation.

Cette logique tient compte :

de la disponibilité du chauffeur ;
de la disponibilité du véhicule ;
des indisponibilités ;
des autres réservations ;
de la capacité du véhicule ;
de l'historique d'affectation.

L'historique permet de garder une trace des changements d'affectation.

3.11 Calcul d'occupation du bus

Le calcul d'occupation du bus est centralisé dans un service dédié.

Ce calcul ne se limite pas à l'heure de départ et à l'heure de retour demandée.

Le return_time représente l'heure à laquelle le bus repart depuis la destination.

L'occupation réelle du bus inclut donc :

heure de départ
→ heure de retour depuis destination
→ durée estimée du trajet retour
→ marge de sécurité

Ce calcul sert à :

détecter les chevauchements ;
proposer des créneaux ;
vérifier les disponibilités ;
alimenter le planning ;
préparer les feuilles de route.
3.12 Planning

Le module planning est documenté en détail dans :

docs/planning.md

Il permet :

de consulter le planning par période ;
de limiter la période à 31 jours ;
de filtrer par statut ;
de filtrer par véhicule ;
de filtrer par chauffeur ;
de filtrer par organisation ;
d'afficher les conflits ;
d'afficher les disponibilités pour les rôles internes ;
d'exporter le planning en format compatible Excel ;
de fournir un bloc d'affichage prêt pour le futur front React.

État actuel : stable en version backend V1/V1+.

3.13 Documents

Le module documentaire permet de générer, stocker et télécharger des documents liés au fonctionnement du bus communal.

Les documents peuvent concerner notamment :

les délibérations ;
les feuilles de route ;
les documents liés aux réservations.

Le module gère aussi les droits d'accès aux documents.

3.14 Délibérations

Le module délibérations permet de générer des documents administratifs liés aux décisions du Collège.

Les délibérations peuvent concerner :

un accord ;
un refus, à finaliser si nécessaire selon le modèle métier retenu.

Le module a été amélioré pour éviter l'écrasement des documents et pour intégrer une logique de versioning si besoin.

3.15 Feuilles de route

Les feuilles de route sont des documents officiels liés au service du bus.

Le mode actuel vise une feuille de route journalière par véhicule.

Elle peut contenir :

la date du service ;
le véhicule ;
le conducteur ;
les missions du jour ;
les lieux de départ ;
les destinations ;
les horaires ;
le nombre de voyageurs ;
le kilométrage ;
les mentions de conservation.

Une génération automatique peut produire les feuilles du lendemain pour les véhicules ayant au moins une réservation.

La génération manuelle reste possible.

3.16 Logs

Un module de logging a été ajouté.

Il permet de tracer certains événements métier importants.

Ce module est utile pour :

suivre les actions sensibles ;
faciliter le débogage ;
disposer d'un historique technique ;
améliorer la maintenance.

Une documentation dédiée existe ou doit être relue :

docs/logging.md
3.17 Backups

Une branche dédiée aux backups est prévue ou en cours.

Les commandes annoncées concernent notamment :

php artisan backup:run
php artisan backup:run --only=db
php artisan backup:run --only=files
php artisan backup:run --include-logs
php artisan backup:list

Ce module doit permettre de sauvegarder la base de données, les fichiers, et éventuellement les logs.

Le chiffrement des backups est prévu comme amélioration future.

État actuel : à confirmer selon la branche de travail de Sacha.

3.18 Notifications

Le module notifications reste un point important à finaliser.

Il devra probablement gérer :

les notifications lors d'une nouvelle demande ;
les changements de statut ;
les décisions du Collège ;
les conflits ;
les documents générés ;
les rappels éventuels ;
les messages destinés aux agents, demandeurs ou chauffeurs.

État actuel : en attente de finalisation / clarification.

4. Modules en cours ou à finaliser
Notifications

Module encore à terminer.

Backups

Architecture annoncée, à vérifier et documenter une fois stabilisée.

Délibérations de refus

À confirmer selon les règles métier et les modèles disponibles.

Front React

Le backend expose déjà de nombreuses routes, mais l'interface utilisateur reste à construire ou finaliser.

Documentation complète

La documentation doit encore être complétée pour :

réservations ;
documents ;
feuilles de route ;
délibérations ;
logs ;
backups ;
notifications.
5. État général du projet

Le backend est déjà bien avancé.

Les principaux blocs métier sont en place :

utilisateurs ;
rôles ;
organisations ;
réservations ;
chauffeurs ;
véhicules ;
lieux ;
conflits ;
indisponibilités ;
documents ;
feuilles de route ;
planning ;
logs.

Le projet dispose aussi d'un grand nombre de tests automatisés.

Le travail restant concerne surtout :

les notifications ;
les backups ;
la documentation ;
le front React ;
les finitions métier ;
la préparation de la défense.
6. Priorités conseillées

Ordre conseillé pour la suite :

Finaliser les notifications.
Stabiliser et tester les backups.
Documenter les modules métier restants.
Commenter les services critiques.
Vérifier les règles d'accès.
Préparer le front React.
Relire les tests globaux.
Préparer la défense orale.
7. Conclusion

Le projet OuKiLéMonBus dispose déjà d'une base backend solide.

Les modules essentiels sont présents et plusieurs fonctionnalités avancées sont déjà développées : planning, feuilles de route journalières, documents, logs, conflits et calcul d'occupation du bus.

Les prochaines étapes doivent surtout viser la stabilisation, la documentation, les notifications, les backups et l'intégration avec le futur front React.
