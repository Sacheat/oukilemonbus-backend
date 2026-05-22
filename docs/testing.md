# Documentation technique — Tests automatisés

## 1. Objectif du document

Ce document présente la stratégie de tests automatisés du backend OuKiLéMonBus.

Les tests permettent de vérifier que les fonctionnalités importantes continuent de fonctionner après chaque modification du code.

Ils sont particulièrement importants dans ce projet car plusieurs modules sont liés entre eux :

- réservations ;
- planning ;
- documents ;
- feuilles de route ;
- délibérations ;
- logs ;
- permissions ;
- chauffeurs ;
- véhicules ;
- indisponibilités.

Une modification dans un module peut avoir un impact sur un autre.  
Les tests servent donc de filet de sécurité.

---

## 2. Pourquoi tester le projet

Les tests permettent de vérifier automatiquement que :

- les routes API répondent correctement ;
- les validations fonctionnent ;
- les règles métier sont respectées ;
- les permissions sont appliquées ;
- les documents sont générés correctement ;
- les conflits sont détectés ;
- le planning retourne les bonnes données ;
- les exports respectent les droits ;
- les changements futurs ne cassent pas l’existant.

Dans ce projet, les tests ont aussi servi à sécuriser les merges entre branches.

---

## 3. Types de tests utilisés

Le projet utilise principalement deux types de tests.

### 3.1 Tests Feature

Les tests Feature vérifient le comportement complet d’une fonctionnalité.

Ils testent généralement :

```text
requête HTTP
↓
route
↓
controller
↓
FormRequest
↓
service
↓
base de données
↓
réponse JSON
Exemples :

tester une route API ;
tester une création de réservation ;
tester l’accès à un document ;
tester l’export du planning ;
tester qu’un chauffeur ne voit que ses trajets.
3.2 Tests Unit

Les tests Unit vérifient une classe ou un service plus isolé.

Ils servent à tester une logique précise.

Exemples :

calcul de l’occupation du bus ;
configuration des logs ;
génération d’un contexte documentaire ;
logique de versioning ;
comportement d’un service métier.
4. Commandes principales

Tester toute l’application :

php artisan test

Tester uniquement le planning :

php artisan test --filter=PlanningApiTest

Tester le calcul d’occupation du bus :

php artisan test --filter=ReservationOccupationServiceTest

Tester les logs :

php artisan test --filter=DomainLoggerTest

Tester les feuilles de route :

php artisan test --filter=RouteSheet

Tester les délibérations :

php artisan test --filter=Deliberation
5. Tests globaux

Avant chaque commit important, il est conseillé de lancer :

php artisan test

Cela permet de vérifier que l’ensemble du projet reste stable.

Pendant le développement du module planning, les tests globaux ont permis de confirmer que les modifications n’avaient pas cassé :

les réservations ;
les documents ;
les feuilles de route ;
les délibérations ;
les logs ;
les permissions existantes.
6. Tests ciblés

Lorsqu’on travaille sur un module précis, il est plus rapide de lancer uniquement les tests concernés.

Exemple pendant le développement du planning :

php artisan test --filter=PlanningApiTest

Cette commande permet de vérifier rapidement le module en cours sans attendre toute la suite de tests.

Ensuite, avant commit ou push, il faut relancer les tests globaux.

7. Tests du module Planning

Les tests du planning se trouvent dans :

tests/Feature/PlanningApiTest.php

Ils vérifient notamment :

qu’un agent communal peut consulter le planning ;
que les dates sont obligatoires ;
que la période ne peut pas dépasser 31 jours ;
qu’un chauffeur ne voit que ses trajets ;
qu’un chauffeur ne reçoit pas les disponibilités internes ;
que le planning peut être filtré par statut ;
que les conflits apparaissent dans le planning ;
que l’export Excel fonctionne ;
que l’export chauffeur est limité à ses trajets ;
que le filtre par véhicule fonctionne ;
que le filtre par chauffeur fonctionne ;
que le filtre par organisation fonctionne.
8. Tests du calcul d’occupation

Le calcul d’occupation du bus est testé dans :

tests/Unit/ReservationOccupationServiceTest.php

Ces tests sont importants car le calcul d’occupation est utilisé par plusieurs modules :

réservations ;
conflits ;
planning ;
affectations ;
feuilles de route.

Le service vérifie notamment que le bus reste occupé après l’heure de retour demandée, car return_time représente l’heure de départ depuis la destination.

9. Tests des documents

Les tests liés aux documents vérifient :

la génération de documents ;
le stockage ;
le versioning ;
les accès ;
le téléchargement ;
l’absence d’exposition du chemin interne ;
l’association avec les réservations.

Ces tests sont importants car les documents ont une valeur administrative.

10. Tests des feuilles de route

Les tests des feuilles de route vérifient notamment :

la génération via API ;
la génération journalière ;
la génération via commande Artisan ;
le versioning ;
la liaison avec les réservations ;
les droits d’accès ;
le contenu du contexte de génération.

Les feuilles de route étant des documents officiels, ces tests sont importants pour éviter les erreurs de génération.

11. Tests des délibérations

Les tests des délibérations vérifient :

la génération d’un document ;
le lien avec une décision ;
le lien avec une réservation ;
le versioning éventuel ;
les cas d’accord ;
les cas de refus si implémentés ;
les accès aux documents générés.
12. Tests des permissions

Les tests doivent vérifier que chaque rôle voit uniquement ce qu’il est autorisé à voir.

Exemples :

un admin peut consulter les données globales ;
un agent communal peut consulter le planning complet ;
un chauffeur ne voit que ses trajets ;
un export chauffeur ne contient pas les trajets des autres chauffeurs ;
un document n’est accessible que par les utilisateurs autorisés.
13. Tests des logs

Les tests liés aux logs vérifient :

que les canaux de logs sont configurés ;
que le service DomainLogger utilise les bons canaux ;
que les logs métier peuvent être appelés sans bloquer l’exécution.

Exemple :

php artisan test --filter=DomainLoggerTest
14. Base de données de test

Les tests utilisent une base de données de test ou un environnement isolé.

Le projet utilise notamment :

DatabaseTransactions

Cela permet d’annuler les changements effectués pendant un test.

Chaque test peut donc créer ses données sans polluer durablement la base.

15. Seeders dans les tests

Certains tests doivent initialiser les rôles et permissions.

Exemple :

$this->seed(RolesAndPermissionsSeeder::class);

Cela permet aux tests d’utiliser correctement les rôles :

admin ;
agent communal ;
chauffeur ;
etc.
16. Factories

Les factories permettent de créer rapidement des données de test.

Exemples :

utilisateur ;
réservation ;
organisation ;
chauffeur ;
véhicule ;
lieu.

Elles évitent d’écrire manuellement toutes les données à chaque test.

17. Bonnes pratiques de tests

Bonnes pratiques appliquées ou recommandées :

tester les règles métier importantes ;
tester les accès selon les rôles ;
tester les cas d’erreur ;
tester les filtres API ;
tester les exports ;
tester les documents générés ;
lancer les tests ciblés pendant le développement ;
lancer tous les tests avant commit important ;
éviter les tests dépendants de l’ordre d’exécution ;
éviter de tester uniquement le “cas heureux”.
18. Cas heureux et cas d’erreur

Un bon module doit tester les deux.

Cas heureux

Exemple :

Un agent communal consulte le planning avec une période valide.

Résultat attendu :

HTTP 200 + données du planning
Cas d’erreur

Exemple :

Un utilisateur demande un planning de plus de 31 jours.

Résultat attendu :

HTTP 422 + erreur de validation
19. Tests avant merge

Avant de merger une branche dans une autre, il est conseillé de lancer :

php artisan test

Cela permet de vérifier que le merge n’a pas cassé le projet.

Pendant le développement du planning, cette stratégie a été utilisée plusieurs fois pour intégrer les changements du main.

20. Tests avant push

Avant un push important, il est conseillé de faire :

git status
php artisan test --filter=NomDuTest
php artisan test
git push

Cela évite de pousser du code cassé.

21. Résultat actuel

Au moment de la rédaction de cette documentation, les tests globaux passent.

Exemple de résultat récent :

357 tests passed
1497 assertions

Ce résultat peut évoluer avec l’ajout de nouveaux tests.

22. Limites actuelles

Certaines limites peuvent encore exister :

tous les cas métier ne sont pas forcément couverts ;
les notifications restent à tester quand elles seront finalisées ;
les backups devront être testés quand la feature sera stabilisée ;
certains tests pourraient être améliorés pour couvrir plus de cas d’erreur ;
le front React aura besoin de ses propres tests.
23. Améliorations futures

Améliorations possibles :

ajouter des tests pour les notifications ;
ajouter des tests pour les backups ;
renforcer les tests d’accès aux documents ;
ajouter des tests sur les refus de délibération ;
ajouter des tests sur les cas limites d’indisponibilité ;
ajouter des tests sur les exports filtrés ;
ajouter des tests de non-régression après chaque grosse feature.
24. Résumé final

Les tests automatisés sont essentiels pour sécuriser le projet OuKiLéMonBus.

Ils permettent de vérifier que les modules principaux fonctionnent correctement et que les modifications futures ne cassent pas l’existant.

La stratégie recommandée est simple :

tests ciblés pendant le développement
+
tests globaux avant commit/push important

Les tests sont un élément important pour la qualité du projet et pour la défense orale.
