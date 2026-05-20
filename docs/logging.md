# Strategie de logs

Ce document decrit la V1 de la strategie de logs du backend Bus Communal.

L'objectif est de faciliter le diagnostic en production sans exposer de donnees sensibles inutilement.

## Canaux

Les logs applicatifs sont separes par domaine :

| Canal Laravel | Fichier | Usage |
|---|---|---|
| `reservations` | `storage/logs/reservations-YYYY-MM-DD.log` | Creation, modification, conflits, changements de statut |
| `routing` | `storage/logs/routing-YYYY-MM-DD.log` | Calculs de distance/duree, erreurs OpenRouteService |
| `documents` | `storage/logs/documents-YYYY-MM-DD.log` | Generation des feuilles de route et deliberations |
| `jobs` | `storage/logs/jobs-YYYY-MM-DD.log` | Jobs, generations automatiques, skips, echecs |
| `laravel` | `storage/logs/laravel-YYYY-MM-DD.log` ou `laravel.log` | Logs framework et erreurs generales |

Les logs metier passent par `App\Services\Logging\DomainLogger`.

## Acces

Les fichiers de logs ne sont pas exposes par l'API.

Ils sont accessibles uniquement aux personnes ou processus ayant acces au serveur ou au systeme de fichiers du projet :

- compte systeme qui execute PHP/Laravel ;
- administrateurs serveur ;
- developpeurs ayant acces a l'environnement ;
- outils de supervision ou de backup configures explicitement.

Le serveur web doit pointer vers le dossier `public/` uniquement. Le dossier `storage/` ne doit jamais etre servi publiquement.

## Donnees autorisees

Les logs peuvent contenir :

- IDs internes ;
- statuts ;
- dates et heures de service ;
- durees estimees ;
- distances estimees ;
- nombre de conflits ;
- IDs de reservation, organisation, chauffeur, vehicule, document ;
- classe et message court d'une exception.

## Donnees interdites

Ne jamais logger :

- token API ;
- mot de passe ;
- contenu complet d'un document ;
- contenu binaire PDF ;
- payload utilisateur complet ;
- adresse complete si un ID de lieu suffit ;
- email ou telephone si ce n'est pas strictement necessaire ;
- informations medicales, scolaires sensibles ou commentaire libre non filtre.

Si une donnee personnelle doit etre journalisee pour diagnostiquer un incident, preferer un ID interne ou une version masquee.

## Configuration recommandee

En local :

```env
LOG_LEVEL=debug
LOG_DAILY_DAYS=14
LOG_RESERVATIONS_LEVEL=info
LOG_ROUTING_LEVEL=info
LOG_DOCUMENTS_LEVEL=info
LOG_JOBS_LEVEL=info
```

En production :

```env
LOG_LEVEL=info
LOG_DAILY_DAYS=14
LOG_RESERVATIONS_LEVEL=info
LOG_ROUTING_LEVEL=warning
LOG_DOCUMENTS_LEVEL=info
LOG_JOBS_LEVEL=info
```

`LOG_ROUTING_LEVEL=warning` evite de conserver chaque calcul de trajet reussi en production. Les erreurs et indisponibilites restent visibles.

## Retention

La retention est controlee par :

```env
LOG_DAILY_DAYS=14
```

La valeur recommandee est 14 jours pour commencer.

Une retention plus longue peut etre utile, mais elle doit etre decidee en fonction :

- du volume de logs ;
- de la politique de confidentialite ;
- de la capacite de stockage ;
- des obligations de conservation.

## Regles de developpement

1. Utiliser `DomainLogger` pour les logs metier.
2. Eviter les appels directs a `Log::info()` dans les services metier.
3. Toujours ajouter un contexte structure.
4. Logger les echecs avec `exception_class` et `exception_message`, pas avec des dumps complets.
5. Ne pas logger les payloads complets de requetes.
6. Ne pas logger les secrets de `.env`.
7. Garder les messages courts et stables.

## Verifications avant production

Avant de deployer :

- verifier que le vhost pointe vers `public/` ;
- verifier que `APP_DEBUG=false` ;
- verifier que `LOG_LEVEL=info` ou plus restrictif ;
- verifier que `LOG_ROUTING_LEVEL=warning` ;
- verifier que les permissions systeme empechent un acces public a `storage/logs` ;
- verifier que les backups ne publient pas les logs dans un espace accessible publiquement.
