# Documentation technique — Module Logs

## 1. Objectif du module

Le module Logs permet de tracer certains événements importants du backend OuKiLéMonBus.

Son objectif est de faciliter :

- le diagnostic en développement ;
- le suivi des erreurs en production ;
- la compréhension des actions métier importantes ;
- l’analyse d’un incident ;
- la maintenance de l’application.

Les logs ne remplacent pas les données métier en base de données.  
Ils servent à comprendre ce qui s’est passé dans le système.

---

## 2. Principe général

Le projet sépare les logs par domaine métier.

Au lieu d’écrire tous les événements dans un seul fichier, certains événements sont envoyés vers des canaux dédiés.

Cela permet de retrouver plus facilement les informations importantes.

Exemple :

```text
Réservations → logs réservations
Documents    → logs documents
Jobs         → logs jobs
Routing      → logs routing
Laravel      → logs framework généraux
3. Service central de logging

Les logs métier passent par :

app/Services/Logging/DomainLogger.php

Ce service sert de point d’entrée unique pour journaliser les événements importants.

L’objectif est d’éviter de disperser partout des appels directs comme :

Log::info(...)

dans les services métier.

L’utilisation d’un service dédié permet :

d’harmoniser les messages ;
de structurer le contexte ;
de choisir le bon canal ;
d’éviter les données sensibles ;
de faciliter les futures modifications.
4. Canaux de logs

Les logs applicatifs sont séparés par domaine.

Canal LaravelFichierUsage
reservationsstorage/logs/reservations-YYYY-MM-DD.logCréation, modification, conflits, changements de statut
routingstorage/logs/routing-YYYY-MM-DD.logCalculs de distance/durée, erreurs OpenRouteService
documentsstorage/logs/documents-YYYY-MM-DD.logGénération des feuilles de route et délibérations
jobsstorage/logs/jobs-YYYY-MM-DD.logJobs, générations automatiques, tâches planifiées, skips, échecs
laravelstorage/logs/laravel-YYYY-MM-DD.log ou laravel.logLogs framework et erreurs générales
5. Pourquoi séparer les logs par domaine

Séparer les logs permet de gagner du temps lors d’un diagnostic.

Exemple :

problème de génération PDF → consulter documents ;
problème de réservation → consulter reservations ;
problème de job automatique → consulter jobs ;
problème de calcul de trajet → consulter routing ;
erreur Laravel générale → consulter laravel.

Cela évite de devoir fouiller un seul gros fichier contenant tous les événements.

6. Accès aux logs

Les fichiers de logs ne sont pas exposés par l’API.

Ils sont accessibles uniquement aux personnes ou processus ayant accès au serveur ou au système de fichiers du projet :

compte système qui exécute PHP/Laravel ;
administrateurs serveur ;
développeurs ayant accès à l’environnement ;
outils de supervision configurés explicitement ;
futur système de backup si configuré.

Le serveur web doit pointer vers :

public/

Le dossier suivant ne doit jamais être servi publiquement :

storage/

En particulier :

storage/logs/
7. Données autorisées dans les logs

Les logs peuvent contenir des données techniques ou métier limitées.

Exemples acceptables :

identifiants internes ;
statuts ;
dates et heures de service ;
durées estimées ;
distances estimées ;
nombre de conflits ;
ID de réservation ;
ID d’organisation ;
ID de chauffeur ;
ID de véhicule ;
ID de document ;
classe d’exception ;
message court d’une exception.

Exemple :

[
    'reservation_id' => 42,
    'status' => 'confirme',
    'driver_id' => 3,
    'vehicle_id' => 1,
]
8. Données interdites dans les logs

Certaines données ne doivent jamais être écrites dans les logs.

Ne jamais logger :

mot de passe ;
token API ;
clé secrète ;
contenu complet d’un document ;
contenu binaire PDF ;
payload utilisateur complet ;
contenu complet de .env ;
email ou téléphone si ce n’est pas strictement nécessaire ;
adresse complète si un ID de lieu suffit ;
commentaire libre non filtré ;
données personnelles sensibles.

Si une donnée personnelle doit vraiment être utile au diagnostic, il faut préférer :

un ID interne ;
une valeur masquée ;
une information minimale.

Exemple préférable :

'user_id' => 12

Plutôt que :

'email' => 'personne@example.com'
9. Configuration recommandée
En local
LOG_LEVEL=debug
LOG_DAILY_DAYS=14
LOG_RESERVATIONS_LEVEL=info
LOG_ROUTING_LEVEL=info
LOG_DOCUMENTS_LEVEL=info
LOG_JOBS_LEVEL=info

En local, le niveau debug peut être utile pour comprendre ce qui se passe.

En production
LOG_LEVEL=info
LOG_DAILY_DAYS=14
LOG_RESERVATIONS_LEVEL=info
LOG_ROUTING_LEVEL=warning
LOG_DOCUMENTS_LEVEL=info
LOG_JOBS_LEVEL=info

En production, il faut éviter de conserver trop de logs inutiles.

Par exemple :

LOG_ROUTING_LEVEL=warning

permet d’éviter de stocker chaque calcul de trajet réussi.

Les erreurs restent visibles.

10. Rétention des logs

La rétention est contrôlée par :

LOG_DAILY_DAYS=14

La valeur recommandée pour commencer est :

14 jours

Une rétention plus longue peut être envisagée, mais elle doit dépendre :

du volume de logs ;
de la capacité de stockage ;
de la politique de confidentialité ;
des besoins de diagnostic ;
des règles internes de conservation.
11. Événements à logger

Tous les événements ne doivent pas être loggés.

Il faut surtout logger :

les erreurs ;
les actions importantes ;
les changements de statut ;
les générations de documents ;
les échecs de jobs ;
les conflits métier ;
les accès refusés sensibles ;
les événements automatisés.
12. Logs liés aux réservations

Canal :

reservations

Événements pertinents :

création d’une réservation ;
mise à jour d’une réservation ;
changement de statut ;
conflit détecté ;
réservation confirmée ;
réservation refusée ;
affectation chauffeur/véhicule ;
impossibilité d’affectation.

Exemple de contexte :

[
    'reservation_id' => 42,
    'status' => 'en_conflit',
    'conflict_type' => 'schedule_overlap',
]
13. Logs liés aux documents

Canal :

documents

Événements pertinents :

génération de délibération ;
génération de feuille de route ;
régénération forcée ;
document stocké ;
document introuvable ;
erreur de génération PDF ;
téléchargement refusé.

Exemple :

[
    'document_id' => 18,
    'document_type' => 'route_sheet',
    'reservation_ids' => [12, 14, 15],
]
14. Logs liés aux jobs

Canal :

jobs

Événements pertinents :

démarrage d’un job ;
fin d’un job ;
génération automatique des feuilles de route ;
job ignoré car aucune réservation ;
job échoué ;
exception dans une tâche automatique.

Exemple :

[
    'job' => 'GenerateDailyRouteSheetsJob',
    'service_date' => '2026-06-18',
    'generated_count' => 3,
    'skipped_count' => 1,
]
15. Logs liés au routing

Canal :

routing

Événements pertinents :

échec de calcul de distance ;
API externe indisponible ;
durée non calculable ;
coordonnées manquantes ;
réponse invalide d’un service externe.

En production, il est préférable de ne pas logger tous les calculs réussis.

16. Logs Laravel généraux

Canal :

laravel

Ce canal reste utilisé pour :

erreurs framework ;
exceptions non interceptées ;
erreurs générales ;
problèmes inattendus.

Il ne remplace pas les canaux métier dédiés.

17. Règles de développement

Règles à respecter :

Utiliser DomainLogger pour les logs métier.
Éviter les appels directs à Log::info() dans les services métier.
Ajouter un contexte structuré.
Garder les messages courts.
Éviter les dumps complets.
Ne pas logger les payloads complets.
Ne pas logger les secrets.
Préférer les IDs internes.
Logger les erreurs avec exception_class et exception_message.
Ne pas exposer d’informations sensibles.
18. Exemple de bon log
$logger->reservationConflictDetected([
    'reservation_id' => $reservation->id,
    'conflict_type' => $type->value,
    'driver_id' => $reservation->driver_id,
    'vehicle_id' => $reservation->vehicle_id,
]);

Ce log est utile car il indique :

la réservation concernée ;
le type de conflit ;
les ressources concernées.

Il n’expose pas de donnée sensible inutile.

19. Exemple de mauvais log
Log::info('Payload complet', $request->all());

Problème :

peut contenir des données personnelles ;
peut contenir des champs sensibles ;
peut rendre les logs illisibles ;
peut poser des problèmes de confidentialité.
20. Exceptions

Lorsqu’une exception est loggée, il faut éviter de stocker trop d’informations.

Préférer :

[
    'exception_class' => get_class($exception),
    'exception_message' => $exception->getMessage(),
]

Éviter :

[
    'trace' => $exception->getTrace(),
    'payload' => $request->all(),
]

La trace complète peut être utile en local, mais elle doit être manipulée avec prudence en production.

21. Logs et documents officiels

Les logs ne remplacent pas les documents.

Exemple :

une feuille de route générée est un document officiel ;
le log indique seulement que cette génération a eu lieu.

Le document doit être conservé dans le module Documents.

Le log sert à comprendre l’événement technique ou métier.

22. Logs et backups

Les backups pourront inclure les logs si l’option est prévue.

Exemple annoncé :

php artisan backup:run --include-logs

Ce point doit être traité avec prudence.

Un backup contenant des logs peut contenir des informations techniques ou personnelles indirectes.

Il faudra donc :

protéger l’accès au backup ;
éviter un stockage public ;
envisager le chiffrement ;
définir une durée de conservation ;
éviter d’inclure des logs trop anciens inutilement.
23. Tests

Les tests liés aux logs doivent vérifier :

que les canaux sont configurés ;
que les logs métier utilisent le bon canal ;
que le contexte contient les clés attendues ;
que les services importants appellent bien DomainLogger ;
que les logs ne bloquent pas l’exécution métier.

Exemple de test existant :

php artisan test --filter=DomainLoggerTest
24. Vérifications avant production

Avant de déployer, vérifier :

que le vhost pointe vers public/ ;
que APP_DEBUG=false ;
que LOG_LEVEL=info ou plus restrictif ;
que LOG_ROUTING_LEVEL=warning ;
que storage/logs n’est pas accessible publiquement ;
que les permissions système sont correctes ;
que les backups ne publient pas les logs dans un espace public ;
que les logs ne contiennent pas de secrets ;
que les fichiers de logs ne grossissent pas trop vite.
25. Limites actuelles

Limites possibles de la V1 :

pas encore d’interface de consultation des logs ;
pas encore de rotation avancée hors configuration Laravel ;
pas encore d’alerting automatique ;
pas encore de centralisation externe ;
pas encore de chiffrement spécifique des logs ;
pas encore de stratégie définitive liée aux backups.
26. Améliorations futures

Améliorations possibles :

ajouter une interface admin sécurisée pour consulter certains logs ;
envoyer certains logs critiques vers un système d’alerte ;
ajouter des alertes mail ou notifications en cas d’échec de job ;
centraliser les logs sur un service externe ;
améliorer la rotation et l’archivage ;
chiffrer certains backups contenant les logs ;
ajouter plus de tests sur les événements métier loggés.
27. Résumé final

Le module Logs fournit une stratégie de journalisation par domaine.

Il facilite le diagnostic sans exposer inutilement des données sensibles.

Les règles principales sont :

logger utilement,
logger peu,
logger structuré,
ne jamais logger de secret,
ne jamais exposer storage/logs publiquement.

Le service DomainLogger doit rester le point d’entrée privilégié pour les logs métier.
