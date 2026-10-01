# Documentation technique — Module Délibérations

## 1. Objectif du module

Le module Délibérations permet de gérer les documents administratifs liés aux décisions du Collège communal.

Une délibération représente une décision officielle concernant une demande d'utilisation du bus communal.

Elle peut servir à formaliser :

- une autorisation ;
- un refus ;
- une décision administrative liée à une réservation ;
- une trace officielle de la décision prise.

---

## 2. Rôle dans l'application

Le module Délibérations est lié principalement à :

```text
Délibérations
├── Réservations
├── Décisions du Collège
├── Documents
├── Organisations
├── Utilisateurs
├── Logs
└── Notifications futures
Il permet de transformer une décision administrative en document PDF stocké dans le module Documents.

3. Principe général

Le processus général est le suivant :

Réservation nécessitant une décision
↓
Création ou mise à jour d'une décision du Collège
↓
Décision approuvée ou refusée
↓
Génération d'un document de délibération
↓
Stockage dans le module Documents
↓
Téléchargement contrôlé selon les droits

3.1 Trouver les réservations à décider et les décisions par statut

Une réservation « attend le Collège » si elle est encore approuvable (en_attente, en_conflit), que son organisation
est d'un type soumis au Collège (CPAS, ASBL, externe) et qu'elle n'a pas de décision ou une décision encore en_attente.
Cette règle est écrite une seule fois : scope `Reservation::awaitingCouncilDecision()`.

GET /api/reservations?council=a_decider        → ces réservations (paginé, combinable avec les autres filtres ; autre valeur : 422)
GET /api/council-decisions?decision_status=…   → décisions approuve, refuse ou en_attente (autre valeur : 422)

Le compteur `to_process.council_pending` de GET /api/dashboard utilise le même scope : le chiffre du tableau de bord
et la liste ne peuvent pas diverger.

3.2 Trajets passés

« Passé » = la date du trajet est strictement avant aujourd'hui à Bruxelles (`Reservation::hasTripDatePassed()`,
`Reservation::localToday()`) ; le jour même du trajet n'est pas passé.

- Un trajet passé n'apparaît plus dans « à décider » ni dans `council_pending`.
- POST /api/council-decisions pour un trajet passé : 422 sur `reservation`.
- PATCH /api/council-decisions/{id} pour un trajet passé : statut, date et référence sont figés (422 sur `reservation`
  si l'un d'eux change) ; seules les `notes` sont mises à jour, sans réappliquer les conséquences ni régénérer la
  délibération (événement `council_decision.notes_updated`).

4. Décisions du Collège

Certaines réservations ne sont pas automatiquement acceptées.

Elles doivent passer par une décision administrative.

La décision du Collège permet de savoir si la demande est :

approuvée ;
refusée ;
en attente ;
éventuellement modifiée ou commentée.
5. Lien avec les réservations

Une délibération est généralement liée à une réservation.

La réservation contient les informations métier :

organisation demandeuse ;
demandeur ;
date du trajet ;
lieu de départ ;
destination ;
horaires ;
nombre de passagers ;
motif ;
statut.

La délibération formalise la décision administrative prise sur cette demande.

6. Réservations communales et automatiques

Certaines réservations, comme les demandes communales ou scolaires, peuvent suivre un circuit d'approbation automatique selon les règles métier.

Dans ce cas, il faut bien distinguer :

l'approbation automatique de la réservation ;
la génération éventuelle d'un document administratif ;
le besoin réel ou non d'une délibération.

Selon les décisions métier retenues, les écoles ou demandes communales peuvent ne pas nécessiter de délibération classique.

Ce point doit rester cohérent avec le cahier des charges et les modèles administratifs disponibles.

7. Délibération d'accord

Une délibération d'accord est générée lorsqu'une décision administrative est favorable.

Elle doit reprendre les informations nécessaires à la compréhension de la décision.

Exemples :

identification de la demande ;
organisation concernée ;
objet de la demande ;
date du trajet ;
décision favorable ;
informations administratives utiles ;
références éventuelles.
8. Délibération de refus

Une délibération de refus peut être nécessaire lorsqu'une demande passant par le Collège est refusée.

Ce cas doit être traité séparément d'une délibération d'accord.

Une délibération de refus devrait contenir :

la demande concernée ;
l'organisation ou le demandeur ;
la décision de refus ;
le motif ou contexte du refus si nécessaire ;
les informations administratives utiles.

Le modèle de délibération de refus doit être confirmé selon les documents officiels disponibles.

9. Génération manuelle

La génération d'une délibération peut être déclenchée manuellement par endpoint API.

Exemple :

POST /api/council-decisions/{id}/generate-document

Cette route permet de générer le document correspondant à une décision donnée.

10. Génération automatique

Une génération automatique peut être envisagée lorsqu'une décision change de statut.

Il faut cependant éviter de générer un document à chaque modification.

La règle souhaitable est :

Ancien statut ≠ approuvé/refusé
et nouveau statut = approuvé/refusé
→ génération possible du document

Cela évite de créer plusieurs documents inutiles à chaque sauvegarde de la décision.

11. Versioning

Le versioning permet d'éviter l'écrasement d'un document existant.

Exemple :

deliberation-2026-001-v001.pdf
deliberation-2026-001-v002.pdf

Selon la règle métier retenue, une délibération peut :

être générée une seule fois ;
ou être régénérée avec une nouvelle version si une correction administrative est nécessaire.

Le plus important est d'éviter d'écraser silencieusement un document déjà produit.

12. Snapshot administratif

Une délibération générée représente l'état d'une décision à un moment donné.

Si la réservation ou la décision change après génération, le document déjà généré reste une trace historique.

Une nouvelle génération doit produire une nouvelle version ou être clairement contrôlée.

13. Service documentaire

La génération des délibérations s'intègre au module Documents.

Processus général :

Controller
↓
DeliberationDocumentService
↓
Construction du contexte
↓
Template PDF
↓
Stockage fichier
↓
Création Document
↓
Association à la décision/réservation
14. Service principal

Le service principal est :

app/Services/Documents/DeliberationDocumentService.php

Son rôle est de gérer la logique de génération du document.

Il peut notamment :

vérifier si la décision est générable ;
construire le nom du fichier ;
gérer le versioning ;
préparer les données ;
générer le PDF ;
créer l'entrée Document ;
associer le document à la réservation ou à la décision.
15. Templates

Les templates de délibération se trouvent dans les vues documentaires.

Exemple :

resources/views/documents/deliberations/...

Le template doit gérer la présentation.

La logique métier doit rester dans les services.

16. Contexte de génération

Le contexte de génération contient les données nécessaires au template.

Exemples :

numéro ou référence de décision ;
date de décision ;
réservation concernée ;
organisation ;
demandeur ;
trajet ;
motif ;
statut ;
texte administratif ;
informations de signature ou validation.
17. Accès aux délibérations

L'accès aux délibérations doit être contrôlé.

Admin et agent communal

Ils peuvent consulter les délibérations administratives.

Demandeur ou organisation

L'accès dépend des règles métier retenues.

Un utilisateur ne doit voir que les documents liés à ses demandes ou à son organisation si cette visibilité est autorisée.

Chauffeur

Un chauffeur n'a généralement pas besoin d'accéder aux délibérations, sauf règle spécifique.

18. Sécurité

Les délibérations peuvent contenir des informations administratives sensibles.

Il faut donc éviter :

d'exposer le chemin interne du fichier ;
de rendre les documents publics sans contrôle ;
de laisser un utilisateur accéder à une délibération qui ne le concerne pas.

Le téléchargement doit passer par une route contrôlée du module Documents.

19. Logs

Les actions importantes peuvent être journalisées.

Exemples :

génération de délibération ;
échec de génération ;
accès refusé ;
document téléchargé ;
régénération ;
changement de statut de décision.

Les logs permettent de garder une trace technique et métier.

20. Notifications futures

Le module Notifications pourra être utilisé pour informer les utilisateurs concernés.

Exemples :

une demande a été approuvée ;
une demande a été refusée ;
une délibération est disponible ;
une correction administrative a été effectuée.

Les destinataires devront être définis selon le rôle et la relation avec la réservation.

21. Tests

Les tests liés aux délibérations doivent vérifier :

la génération d'une délibération ;
l'impossibilité de générer si le statut ne le permet pas ;
le versioning ;
le stockage du document ;
l'absence d'exposition du chemin interne ;
les droits d'accès ;
les cas d'accord ;
les cas de refus si implémentés ;
l'association avec la réservation ou la décision.
22. Limites actuelles

Certaines limites peuvent rester à confirmer :

modèle exact de délibération de refus ;
règle métier pour les demandes communales ;
génération automatique ou uniquement manuelle ;
versioning systématique ou génération unique ;
niveau d'accès pour les organisations ;
notifications associées.
23. Améliorations futures

Améliorations possibles :

finaliser les délibérations de refus ;
automatiser la génération au changement de statut ;
notifier les utilisateurs concernés ;
améliorer les templates PDF ;
ajouter une recherche par décision ;
ajouter une recherche par réservation ;
ajouter un statut documentaire : actif, remplacé, archivé ;
améliorer l'audit des téléchargements.
24. Résumé final

Le module Délibérations permet de transformer une décision administrative en document officiel.

Il est lié aux réservations, aux décisions du Collège et au module Documents.

Les points importants sont la sécurité d'accès, le versioning, la génération contrôlée et la cohérence avec les règles métier.

Le module est déjà bien avancé, mais les cas de refus et la génération automatique doivent être confirmés ou finalisés selon les besoins du projet.
