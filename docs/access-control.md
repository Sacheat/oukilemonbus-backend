# Documentation technique — Contrôle d'accès et permissions

## 1. Objectif du document

Ce document décrit les règles générales de contrôle d'accès dans l'application OuKiLéMonBus.

Le contrôle d'accès permet de répondre à une question simple :

```text
Qui peut voir ou faire quoi dans l'application ?
Ce module est transversal.
Il concerne notamment :

les utilisateurs ;
les rôles ;
les permissions ;
les réservations ;
les documents ;
les feuilles de route ;
les délibérations ;
le planning ;
les exports ;
les futures notifications.
2. Principe général

L'application repose sur une séparation entre :

Authentification
→ Qui est connecté ?

Autorisation
→ Qu'a-t-il le droit de faire ?

L'authentification permet d'identifier l'utilisateur.

L'autorisation permet de vérifier si cet utilisateur peut accéder à une ressource ou effectuer une action.

3. Authentification

L'authentification permet de savoir quel utilisateur effectue une requête.

Dans le projet, les routes API sensibles sont protégées.

Un utilisateur non connecté ne doit pas pouvoir accéder aux données métier importantes.

Exemples de données protégées :

réservations ;
planning ;
documents ;
feuilles de route ;
décisions administratives ;
informations chauffeurs ;
informations véhicules.
4. Rôles

Les rôles permettent de représenter les profils utilisateurs.

Exemples de rôles utilisés ou prévus :

admin ;
agent communal ;
chauffeur ;
responsable d'organisation ;
utilisateur demandeur.

Chaque rôle donne accès à certaines fonctionnalités.

5. Permissions

Les permissions représentent des droits précis.

Exemples :

reservations.view
reservations.create
reservations.update
documents.view
documents.download
planning.view

L'intérêt des permissions est de ne pas coder uniquement en fonction des noms de rôles.

Un rôle peut recevoir plusieurs permissions.

Une permission peut être donnée à plusieurs rôles.

6. Différence entre rôle et permission

Un rôle représente un profil.

Une permission représente une action autorisée.

Exemple :

Rôle : agent communal
Permissions possibles :
- voir les réservations
- modifier les réservations
- générer des documents
- consulter le planning

Cette séparation rend le système plus flexible.

7. Admin

Le rôle admin est le rôle le plus élevé.

Il peut généralement :

consulter toutes les données ;
gérer les utilisateurs ;
gérer les rôles et permissions ;
accéder aux réservations ;
accéder au planning ;
accéder aux documents ;
générer les documents ;
consulter les logs ;
lancer ou consulter les sauvegardes si le module le permet.

Ce rôle doit être limité aux personnes réellement responsables de l'application.

8. Agent communal

L'agent communal est un rôle interne.

Il peut généralement :

consulter les réservations ;
traiter les demandes ;
affecter un chauffeur ;
affecter un véhicule ;
consulter le planning global ;
voir les disponibilités ;
générer des documents ;
consulter les documents administratifs ;
gérer les feuilles de route.

L'agent communal n'a pas forcément tous les droits techniques de l'admin, mais il possède les droits métier principaux.

9. Chauffeur

Le chauffeur a un accès limité à ce qui le concerne.

Il peut généralement consulter :

ses trajets ;
son planning ;
les feuilles de route liées à ses missions ;
les informations nécessaires à son service.

Il ne doit pas voir :

toutes les réservations ;
tous les documents administratifs ;
les documents qui ne le concernent pas ;
les disponibilités des autres chauffeurs ;
les informations internes inutiles.
10. Responsable d'organisation

Le responsable d'organisation représente une structure demandeuse.

Il peut généralement consulter :

les réservations de son organisation ;
les demandes qu'il a introduites ;
éventuellement certains documents liés à son organisation.

Il ne doit pas voir :

les réservations d'autres organisations ;
le planning global ;
les documents contenant des données d'autres organisations ;
les informations internes des chauffeurs ou véhicules.
11. Demandeur simple

Un demandeur simple peut consulter les demandes qu'il a introduites.

Il ne doit pas accéder :

au planning global ;
aux documents internes ;
aux feuilles de route globales ;
aux informations d'autres utilisateurs ;
aux réservations d'autres organisations.
12. Contrôle d'accès dans les FormRequests

Les FormRequests peuvent contenir une méthode :

authorize()

Cette méthode vérifie si l'utilisateur a le droit d'effectuer l'action demandée.

Exemple :

public function authorize(): bool
{
    return $this->user()?->can('reservations.view') ?? false;
}

Cela permet de bloquer une requête avant même d'arriver dans le contrôleur.

13. Contrôle d'accès dans les contrôleurs

Les contrôleurs ne doivent pas contenir toute la logique métier, mais ils peuvent orchestrer l'accès.

Ils peuvent :

récupérer l'utilisateur connecté ;
appeler un FormRequest ;
vérifier une permission ;
appeler un service métier ;
retourner une ressource adaptée.

Exemple :

PlanningController
↓
PlanningIndexRequest
↓
PlanningService
↓
PlanningItemResource
14. Contrôle d'accès dans les services

Les services peuvent contenir des règles d'accès métier plus fines.

Exemple :

Un chauffeur peut consulter une feuille de route
uniquement si elle contient au moins une mission qui lui est assignée.

Ce type de règle ne se résume pas toujours à une simple permission.

Il dépend parfois de la relation entre l'utilisateur et la ressource.

15. Accès aux réservations

Les règles générales sont :

Admin / agent communal

Peuvent consulter toutes les réservations.

Chauffeur

Peut consulter les réservations auxquelles il est affecté.

Responsable d'organisation

Peut consulter les réservations liées à son organisation.

Demandeur

Peut consulter les réservations qu'il a introduites.

16. Accès au planning

Le planning applique les mêmes principes de visibilité.

Admin / agent communal

Peuvent consulter le planning global.

Ils peuvent également demander les disponibilités détaillées :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&include_availability=1
Chauffeur

Ne voit que ses trajets.

Il ne reçoit pas les détails de disponibilité des autres chauffeurs et véhicules.

Responsable d'organisation / demandeur

Ne voit que les réservations qui le concernent, selon les règles de visibilité.

17. Accès aux documents

L'accès aux documents est sensible.

Le backend doit vérifier :

le rôle de l'utilisateur ;
la relation entre l'utilisateur et le document ;
la réservation liée ;
l'organisation liée ;
le type de document ;
les règles spécifiques au document.

Un utilisateur ne doit jamais accéder à un fichier uniquement parce qu'il connaît son chemin ou son identifiant.

18. Masquage du file_path

Le champ file_path ne doit pas être exposé publiquement dans l'API.

Ce champ représente un chemin interne du serveur.

Le front doit utiliser une route de téléchargement contrôlée.

Exemple attendu :

{
  "id": 12,
  "filename": "route-sheet-2026-06-18-vehicle-1-v001.pdf",
  "download_url": "/api/documents/12/download"
}

Le backend reste responsable de :

retrouver le fichier ;
vérifier les droits ;
renvoyer le téléchargement.
19. Accès aux feuilles de route

Les feuilles de route sont des documents particuliers.

Le mode actuel vise :

1 feuille de route
par véhicule
par jour
Admin / agent communal

Accès complet.

Chauffeur

Accès uniquement si la feuille contient au moins une mission qui lui est assignée.

Organisation

Accès à traiter avec prudence.

Une feuille de route peut contenir plusieurs missions et potentiellement plusieurs organisations.

Par défaut, il vaut mieux éviter de donner accès à une feuille complète à une organisation si elle contient des données qui ne la concernent pas.

20. Accès aux délibérations

Les délibérations sont des documents administratifs.

Admin / agent communal

Peuvent consulter les délibérations.

Organisation / demandeur

Accès possible uniquement si la règle métier le prévoit et si la délibération concerne leur demande.

Chauffeur

N'a généralement pas besoin d'accéder aux délibérations.

21. Accès aux exports

Les exports doivent respecter les mêmes règles que les données affichées.

Exemple :

Si un chauffeur ne voit que ses trajets dans le planning,
alors son export planning ne doit contenir que ses trajets.

C'est le cas du module planning :

GET /api/planning
GET /api/planning/export

Les deux passent par le même service métier.

Cela évite d'avoir une différence entre l'affichage et l'export.

22. Accès aux disponibilités

Les disponibilités chauffeurs/véhicules sont des informations internes.

Elles peuvent indiquer :

qu'un chauffeur est indisponible ;
qu'un véhicule est indisponible ;
qu'un chauffeur est déjà affecté ;
qu'un véhicule est déjà utilisé ;
qu'une capacité est insuffisante.

Ces informations doivent être réservées aux rôles internes :

admin ;
agent communal.

Un chauffeur ne doit pas recevoir les détails de disponibilité des autres chauffeurs.

23. Accès aux logs

Les logs contiennent des informations techniques et métier.

Ils peuvent révéler :

des erreurs ;
des actions sensibles ;
des documents générés ;
des conflits ;
des tentatives d'accès refusées.

L'accès aux logs doit être limité aux rôles techniques ou administratifs autorisés.

24. Accès aux backups

Les sauvegardes sont très sensibles.

Elles peuvent contenir :

la base de données ;
les fichiers ;
les documents ;
les logs.

L'accès aux commandes et fichiers de backup doit être strictement limité.

Idéalement, seuls les administrateurs ou responsables techniques devraient pouvoir les manipuler.

25. Règles transversales de sécurité

Quelques règles doivent être respectées partout :

ne jamais exposer les chemins internes ;
ne jamais se baser uniquement sur un ID reçu en URL ;
toujours vérifier l'utilisateur connecté ;
toujours vérifier la permission ou la relation métier ;
ne pas donner aux utilisateurs plus de données que nécessaire ;
appliquer les mêmes droits à l'affichage et à l'export ;
journaliser les actions sensibles si nécessaire.
26. Exemple : accès chauffeur au planning

Un chauffeur connecté demande :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07

Le backend doit retourner uniquement les réservations où ce chauffeur est affecté.

Il ne doit pas retourner :

les trajets des autres chauffeurs ;
les disponibilités complètes ;
les données internes d'administration.
27. Exemple : accès admin au planning

Un agent communal connecté demande :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&include_availability=1

Le backend peut retourner :

toutes les réservations de la période ;
les conflits ;
les chauffeurs ;
les véhicules ;
les disponibilités ;
les raisons d'indisponibilité.

Cela permet à l'agent d'organiser le service.

28. Exemple : accès document

Un utilisateur demande :

GET /api/documents/12/download

Le backend doit vérifier :

1. Le document existe-t-il ?
2. Le fichier existe-t-il ?
3. L'utilisateur est-il connecté ?
4. L'utilisateur a-t-il le droit d'accéder à ce document ?
5. Le document contient-il des données d'autres utilisateurs ?

Si la réponse est négative, l'accès doit être refusé.

29. Tests attendus

Les tests de contrôle d'accès doivent vérifier :

qu'un admin peut voir les données internes ;
qu'un agent communal peut gérer le planning ;
qu'un chauffeur ne voit que ses trajets ;
qu'un utilisateur ne voit que ses demandes ;
qu'un responsable d'organisation ne voit que son organisation ;
qu'un document n'expose pas file_path ;
qu'un export respecte les mêmes droits que l'affichage ;
qu'un accès interdit retourne une erreur correcte.
30. Erreurs attendues

Selon les cas, le backend peut retourner :

401 Unauthorized

Utilisateur non connecté.

403 Forbidden

Utilisateur connecté mais non autorisé.

404 Not Found

Ressource inexistante ou volontairement masquée.

422 Unprocessable Entity

Paramètres invalides.

Exemple :

vehicle_id inexistant
date invalide
statut invalide
période trop longue
31. Bonnes pratiques appliquées

Le projet applique plusieurs bonnes pratiques :

validation dans les FormRequests ;
logique métier dans les services ;
transformation des réponses dans les Resources ;
routes protégées ;
permissions par rôle ;
tests automatisés ;
masquage des chemins internes ;
règles spécifiques selon les relations métier.
32. Points à surveiller

Certains points doivent rester surveillés :

accès aux feuilles de route contenant plusieurs missions ;
accès des organisations aux documents globaux ;
disponibilité visible uniquement par les rôles internes ;
exports qui doivent respecter les droits ;
logs et backups qui doivent rester très protégés ;
notifications qui ne doivent pas révéler des données non autorisées.
33. Améliorations futures

Améliorations possibles :

renforcer les policies Laravel ;
documenter toutes les permissions disponibles ;
ajouter une matrice rôles/permissions complète ;
ajouter des tests d'accès pour chaque module ;
auditer les téléchargements ;
journaliser les refus d'accès sensibles ;
masquer certaines ressources par 404 plutôt que 403 si nécessaire ;
ajouter un tableau d'administration des rôles.
34. Résumé final

Le contrôle d'accès est transversal à toute l'application.

Il protège les réservations, documents, feuilles de route, délibérations, planning, logs et backups.

La règle principale est simple :

Un utilisateur ne doit voir que les données nécessaires à son rôle
ou directement liées à lui.

Le projet utilise les rôles, permissions, FormRequests, services métier et tests pour appliquer ces règles de manière cohérente.
