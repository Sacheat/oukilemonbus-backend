# Documentation technique — Intégration Frontend React

## 1. Objectif du document

Ce document explique comment le futur frontend React devra communiquer avec le backend Laravel de l'application OuKiLéMonBus.

Le backend expose une API REST sécurisée.  
Le frontend devra utiliser cette API pour :

- authentifier les utilisateurs ;
- afficher les réservations ;
- créer ou modifier des demandes ;
- afficher le planning ;
- télécharger des documents ;
- afficher les feuilles de route ;
- gérer les notifications futures ;
- adapter l'interface selon le rôle utilisateur.

---

## 2. Architecture générale

L'application est pensée avec deux parties séparées :

```text
Frontend React
↓ appels HTTP
Backend Laravel API
↓
Base de données + fichiers + services métier
Le frontend ne doit pas accéder directement :

à la base de données ;
au dossier storage/ ;
aux fichiers PDF internes ;
aux logs ;
aux backups.

Tout doit passer par les routes API sécurisées du backend.

3. Responsabilités du frontend

Le frontend React doit principalement gérer :

l'affichage des écrans ;
les formulaires ;
les appels API ;
les menus selon le rôle ;
l'affichage des erreurs ;
l'expérience utilisateur ;
les filtres de recherche ;
les tableaux ;
les calendriers ;
les boutons d'action ;
les notifications visuelles.

Le frontend ne doit pas contenir la logique métier principale.

Exemple :

Mauvais :
React décide si une réservation est légalement acceptable.

Bon :
React envoie les données au backend.
Laravel valide les règles métier.
React affiche la réponse.
4. Responsabilités du backend

Le backend Laravel doit gérer :

l'authentification ;
les permissions ;
les règles métier ;
les validations ;
les conflits ;
les calculs d'occupation ;
les documents ;
les exports ;
les téléchargements ;
les logs ;
les données persistantes.

Le frontend doit considérer le backend comme la source de vérité.

5. Authentification

L'authentification est nécessaire pour accéder aux routes protégées.

Le frontend devra envoyer les informations d'identification puis conserver le mode d'authentification prévu par le projet.

Selon la configuration Laravel/Sanctum, le frontend pourra utiliser :

un token API ;
ou une authentification par cookie/session si le front est sur le même domaine ou correctement configuré.

Les routes sensibles doivent être appelées avec l'utilisateur authentifié.

6. Gestion des rôles côté frontend

Le frontend peut adapter l'affichage selon le rôle utilisateur.

Exemples :

Admin

Peut voir :

tableau de bord global ;
réservations ;
planning complet ;
documents ;
feuilles de route ;
logs si exposés plus tard ;
gestion utilisateurs si prévue.
Agent communal

Peut voir :

réservations ;
planning complet ;
disponibilités ;
documents ;
feuilles de route ;
décisions administratives.
Chauffeur

Peut voir :

ses trajets ;
son planning ;
ses feuilles de route ;
éventuellement les notifications liées à ses missions.
Responsable d'organisation / demandeur

Peut voir :

ses demandes ;
les réservations de son organisation si autorisé ;
certains documents liés à ses demandes.
7. Ne pas faire confiance au frontend

Même si le frontend masque certains boutons, le backend doit toujours vérifier les droits.

Exemple :

Le frontend cache le bouton "Générer feuille de route" pour un chauffeur.
Mais si le chauffeur appelle l'API manuellement, le backend doit quand même refuser.

Le frontend améliore l'expérience utilisateur.
Le backend protège réellement l'application.

8. Format général des appels API

Les appels API se feront généralement en JSON.

Exemple :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07
Accept: application/json
Authorization: Bearer <token>

Pour les requêtes avec corps :

POST /api/reservations
Content-Type: application/json
Accept: application/json
Authorization: Bearer <token>
9. Gestion des erreurs côté frontend

Le frontend doit gérer plusieurs types d'erreurs.

401 Unauthorized

Utilisateur non connecté.

Action frontend possible :

rediriger vers la page de connexion ;
supprimer l'état utilisateur local.
403 Forbidden

Utilisateur connecté mais non autorisé.

Action frontend possible :

afficher un message clair ;
cacher ou désactiver l'action dans l'interface.
404 Not Found

Ressource inexistante ou inaccessible.

Action frontend possible :

afficher une page ou alerte "ressource introuvable".
422 Unprocessable Entity

Erreur de validation.

Action frontend possible :

afficher les erreurs sous les champs du formulaire.

Exemple Laravel :

{
  "message": "The given data was invalid.",
  "errors": {
    "start_date": [
      "La date de debut est obligatoire."
    ]
  }
}
10. Planning côté frontend

Le module planning est exposé par :

GET /api/planning

Paramètres obligatoires :

start_date
end_date

Paramètres optionnels :

include_availability
statuses[]
vehicle_id
driver_id
organization_id

Exemple :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07
11. Planning global

Le frontend peut afficher une vue globale du planning pour les rôles internes.

Exemple :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07

Cette vue peut être utilisée pour :

calendrier hebdomadaire ;
tableau des trajets ;
planning mensuel limité à 31 jours ;
vue agent communal.
12. Planning filtré

Le frontend peut proposer des filtres.

Par véhicule
GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&vehicle_id=1
Par chauffeur
GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&driver_id=2
Par organisation
GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&organization_id=3
Par statut
GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&statuses[]=confirme
13. Limite de période du planning

Le backend limite la période du planning à 31 jours.

Le frontend doit donc éviter de proposer une plage plus longue.

Si l'utilisateur sélectionne une période trop longue, le backend retournera une erreur 422.

Raison :

Le planning est un outil opérationnel.
Il n'est pas destiné à exporter plusieurs années de données.

Pour les archives officielles, il faut passer par le module Documents.

14. Bloc display du planning

Chaque élément du planning contient un bloc display.

Exemple :

"display": {
  "title": "Ecole communale de Dinant - Sortie scolaire",
  "time_label": "09:00 - 11:45",
  "status_label": "Confirme",
  "assignment_label": "Affecte",
  "color": "success"
}

Le frontend peut utiliser ce bloc pour afficher rapidement une carte planning.

Exemple d'usage :

titre de la carte ;
horaire visible ;
badge de statut ;
badge d'affectation ;
couleur de l'événement.
15. Données brutes et données d'affichage

Le frontend reçoit deux types de données :

Données brutes

Exemples :

reservation_id
trip_date
departure_time
return_time
occupation_start
occupation_end
status
driver
vehicle
organization

Elles servent à la logique.

Données d'affichage

Exemples :

display.title
display.time_label
display.status_label
display.assignment_label
display.color

Elles servent à l'interface.

Le frontend peut utiliser les deux.

16. Disponibilités chauffeurs/véhicules

Les disponibilités sont demandées avec :

GET /api/planning?start_date=2026-06-01&end_date=2026-06-07&include_availability=1

Attention :

admin et agent communal peuvent les recevoir ;
chauffeur ne les reçoit pas ;
utilisateur externe ne doit pas les recevoir.

Le frontend doit donc s'attendre à ce que le champ availability soit parfois absent.

17. Export du planning

L'export du planning est disponible via :

GET /api/planning/export

Exemple :

GET /api/planning/export?start_date=2026-06-01&end_date=2026-06-07

Les filtres fonctionnent aussi :

GET /api/planning/export?start_date=2026-06-01&end_date=2026-06-07&vehicle_id=1

Le fichier retourné est compatible Excel.

Le frontend peut déclencher le téléchargement via un lien ou un bouton.

18. Réservations côté frontend

Le frontend devra permettre :

de créer une demande ;
de consulter une demande ;
de voir son statut ;
de modifier certaines informations si autorisé ;
de voir les conflits éventuels ;
de suivre le traitement administratif.

Les règles métier restent côté backend.

Le frontend doit afficher les erreurs de validation retournées par Laravel.

19. Documents côté frontend

Les documents doivent être téléchargés via une route API contrôlée.

Le frontend ne doit jamais construire un chemin direct vers storage.

Bon principe :

Bouton "Télécharger"
↓
GET /api/documents/{id}/download
↓
Backend vérifie les droits
↓
Backend renvoie le fichier

Le frontend peut afficher :

nom du document ;
type ;
date de génération ;
statut ;
bouton téléchargement.
20. Feuilles de route côté frontend

Les feuilles de route sont des documents particuliers.

Les rôles internes peuvent :

générer une feuille ;
consulter les feuilles ;
télécharger les PDF ;
régénérer si autorisé.

Le chauffeur peut accéder uniquement aux feuilles qui le concernent.

La génération manuelle peut utiliser :

POST /api/documents/route-sheets/generate

Avec un payload du type :

{
  "service_date": "2026-06-18",
  "vehicle_id": 1
}
21. Délibérations côté frontend

Les délibérations peuvent être générées pour les décisions administratives.

Exemple :

POST /api/council-decisions/{id}/generate-document

Le frontend peut proposer cette action uniquement aux rôles autorisés.

Il devra afficher le document généré dans la liste des documents liés.

22. Notifications côté frontend

Le module Notifications reste à finaliser.

Le frontend devra probablement afficher :

une icône de notifications ;
une liste de notifications ;
un statut lu/non lu ;
des liens vers les réservations ou documents concernés.

Les notifications pourront concerner :

nouvelle réservation ;
changement de statut ;
conflit ;
document disponible ;
chauffeur affecté ;
feuille de route disponible.
23. Logs côté frontend

Les logs ne doivent pas être exposés à tous les utilisateurs.

Si une interface de consultation existe un jour, elle devra être réservée à des rôles internes spécifiques.

En V1, les logs restent plutôt côté serveur.

24. Backups côté frontend

Les backups sont sensibles.

Si une interface de gestion des backups est créée plus tard, elle devra être strictement réservée aux administrateurs.

Le frontend ne doit jamais exposer publiquement :

fichiers de backup ;
chemins de backup ;
informations de configuration serveur.
25. Structure recommandée côté React

Exemple de structure possible :

src/
├── api/
│   ├── client.js
│   ├── authApi.js
│   ├── reservationsApi.js
│   ├── planningApi.js
│   ├── documentsApi.js
│   └── notificationsApi.js
├── components/
│   ├── planning/
│   ├── reservations/
│   ├── documents/
│   └── layout/
├── pages/
│   ├── DashboardPage.jsx
│   ├── PlanningPage.jsx
│   ├── ReservationsPage.jsx
│   ├── DocumentsPage.jsx
│   └── LoginPage.jsx
└── hooks/
    ├── useAuth.js
    ├── usePlanning.js
    └── useNotifications.js
26. Client API recommandé

Le frontend devrait centraliser les appels HTTP dans un client API.

Exemple conceptuel :

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  headers: {
    Accept: 'application/json',
  },
});

Cela évite de répéter partout l'URL de base et les headers.

27. Gestion des paramètres

Pour les filtres, le frontend doit construire proprement l'URL.

Exemple conceptuel :

const params = {
  start_date: startDate,
  end_date: endDate,
  vehicle_id: selectedVehicleId || undefined,
  driver_id: selectedDriverId || undefined,
  organization_id: selectedOrganizationId || undefined,
};

Il ne faut pas envoyer des filtres vides.

28. Sécurité côté frontend

Le frontend doit :

stocker les tokens prudemment selon la stratégie choisie ;
ne jamais afficher d'information non reçue de l'API ;
ne pas exposer les routes sensibles dans des composants accessibles à tous ;
vérifier les rôles pour l'affichage ;
accepter que le backend refuse quand même une action.

Le backend reste l'autorité finale.

29. Expérience utilisateur

Le frontend doit prévoir :

états de chargement ;
messages d'erreur ;
affichage vide ;
filtres réinitialisables ;
pagination si nécessaire ;
boutons désactivés selon rôle ;
messages de succès ;
confirmations avant actions sensibles.
30. Exemple de page Planning

Une page Planning pourrait contenir :

sélection de période ;
filtre véhicule ;
filtre chauffeur ;
filtre organisation ;
filtre statut ;
bouton export ;
calendrier ou tableau ;
badges de statut ;
affichage des conflits ;
bouton vers détails de réservation.
31. Exemple de flux planning
Utilisateur ouvre le planning
↓
React charge les véhicules/chauffeurs/organisations utiles
↓
React appelle GET /api/planning avec start_date et end_date
↓
Laravel retourne les réservations filtrées selon les droits
↓
React affiche les cartes planning
↓
Utilisateur change de filtre
↓
React rappelle GET /api/planning avec les nouveaux paramètres
32. Bonnes pratiques

Bonnes pratiques recommandées :

centraliser les appels API ;
centraliser la gestion utilisateur/rôles ;
afficher les erreurs Laravel proprement ;
ne pas dupliquer les règles métier dans React ;
utiliser les données display quand elles existent ;
utiliser les données brutes pour les liens/actions ;
ne jamais accéder directement à storage/ ;
tester les écrans principaux.
33. Limites actuelles

Le backend est avancé, mais le frontend reste à construire ou finaliser.

Certaines fonctionnalités dépendront de l'état final des modules :

notifications ;
backups ;
certains documents ;
éventuelles délibérations de refus ;
vues d'administration.
34. Résumé final

Le frontend React devra consommer le backend Laravel via des routes API sécurisées.

Le backend garde la logique métier, les droits, les validations et les documents.

Le frontend se concentre sur l'affichage, les formulaires, les filtres et l'expérience utilisateur.

Cette séparation permet de garder une application plus propre, plus maintenable et plus sécurisée.
