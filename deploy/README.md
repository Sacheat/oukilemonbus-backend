# Déploiement en production

Deux options, selon ce que permet la VM :

| Option | Quand | Dossier |
|---|---|---|
| **Docker** (recommandée) | Docker est installé ou installable sur la VM | [`deploy/docker/`](docker/) |
| Sans Docker | Docker interdit : PHP, Nginx et MariaDB installés directement | `deploy/native/` (à venir) |

Les deux utilisent le même modèle de configuration : [`deploy/.env.production.example`](.env.production.example).

## Avant de commencer : questions pour l'hébergeur

| Question | Ce que ça change |
|---|---|
| Docker est-il installé, ou puis-je l'installer ? Ai-je un accès SSH avec `sudo` ? | Option Docker ou sans Docker |
| Un Nginx tourne-t-il déjà sur les ports 80 et 443 (autres projets) ? | HTTPS par Caddy (ports libres) ou par le Nginx existant (voir [HTTPS](#https)) |
| Un MariaDB existe-t-il déjà, et faut-il l'utiliser ? | Docker : on a le nôtre, rien à faire. Sans Docker : base à créer dans l'existant |
| Quel domaine (ou sous-domaine) pour l'API et pour le frontend ? Qui gère le DNS ? | `APP_URL`, `FRONTEND_URL`, HTTPS, et les enregistrements SPF/DKIM de Brevo |
| La VM est-elle sauvegardée ? Où stocker une copie des backups hors de la VM ? | Voir [Backups](#backups) |
| RAM et disque disponibles ? | 2 Go de RAM et 10 Go de disque suffisent pour toute la stack |

---

## Option Docker

### Ce qui tourne

| Service | Rôle |
|---|---|
| `app` | php-fpm : l'API Laravel |
| `queue` | Worker de file (`critical,default,low`) : emails, exports planning, récapitulatifs |
| `scheduler` | Tâches planifiées (`schedule:work`) : feuilles de route, rappels, clôtures, purges |
| `web` | Nginx de la stack, sur `127.0.0.1:8080` uniquement (jamais exposé directement) |
| `mariadb` | Base de données, non exposée hors de la stack |
| `caddy` | Optionnel (profil `https`) : HTTPS automatique Let's Encrypt quand les ports 80/443 sont libres |

Le code est copié dans l'image : modifier un fichier sur la VM ne change rien tant qu'on ne relance pas `deploy.sh`.
Les données vivent dans des volumes Docker qui survivent aux déploiements : `storage` (documents générés,
justificatifs, backups, logs) et `mariadb_data` (base).

### Prérequis sur la VM

- Docker Engine et le plugin Compose v2 (`docker compose version`) ;
- `git` ;
- l'utilisateur de déploiement dans le groupe `docker` (ou `sudo`).

### Première installation

```bash
# 1. Récupérer le code
git clone git@github.com:Sacheat/bus-communal-backend.git /opt/bus-communal
cd /opt/bus-communal

# 2. Configuration : remplacer chaque <...> (domaines, mots de passe, Brevo, clés ORS/GeoNames)
cp deploy/.env.production.example .env
chmod 600 .env
nano .env

# 3. Générer APP_KEY puis la coller dans .env (APP_KEY=base64:...)
docker compose --env-file .env -f deploy/docker/docker-compose.prod.yml build app
docker compose --env-file .env -f deploy/docker/docker-compose.prod.yml run --rm --no-deps app php artisan key:generate --show

# 4. Déployer : build, base, migrations, rôles et permissions, démarrage, vérification
deploy/docker/deploy.sh --no-pull
```

`deploy.sh` termine par `php artisan app:health` : base, cache, worker, stockage, SMTP et API externes
doivent être en `OK`.

#### Créer le premier administrateur

La production ne crée aucun compte de test. Le premier admin se crée une fois, en remplaçant les valeurs :

```bash
docker compose --env-file .env -f deploy/docker/docker-compose.prod.yml exec app php artisan tinker
```
```php
$u = App\Models\User::create(['first_name' => 'Prénom', 'last_name' => 'Nom', 'email' => 'admin@exemple.be',
    'phone' => '0470000000', 'password' => Hash::make('mot-de-passe-long'), 'active' => true]);
$u->forceFill(['email_verified_at' => now()])->save();
$u->assignRole('admin');
```

Les autres comptes se créent ensuite depuis l'application.

### Mettre à jour

```bash
cd /opt/bus-communal
deploy/docker/deploy.sh
```

Le script fait `git pull --ff-only`, reconstruit les images, lance les migrations et le seeder des rôles
(idempotent), puis recrée les conteneurs. Le worker termine son job en cours avant de s'arrêter
(`stop_grace_period: 190s`) et repart avec le nouveau code : pas de `queue:restart` à faire.

Après une modification du `.env` seul : `deploy/docker/deploy.sh --no-pull` (la config est mise en cache au
démarrage des conteneurs, elle doit être relue).

### HTTPS

**Cas 1 : les ports 80 et 443 de la VM sont libres.** Remplir `CADDY_DOMAIN=api.exemple.be` dans `.env` :
`deploy.sh` active alors Caddy, qui obtient et renouvelle le certificat tout seul. Le domaine doit déjà pointer
vers l'IP de la VM.

**Cas 2 : un Nginx tourne déjà sur la VM.** Laisser `CADDY_DOMAIN` vide et ajouter ce bloc au Nginx existant
(certificat via `certbot --nginx`, par exemple) :

```nginx
server {
    listen 443 ssl;
    server_name api.exemple.be;
    # ssl_certificate / ssl_certificate_key : gérés par certbot

    client_max_body_size 20M;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
    }
}
```

Dans les deux cas, Laravel voit la vraie IP du visiteur et le `https` : `bootstrap/app.php` fait confiance aux
proxies des réseaux privés (Docker, proxy local). Sans ça, la limite de tentatives de connexion serait partagée
par tous les utilisateurs.

### Commandes utiles

```bash
alias dc='docker compose --env-file .env -f deploy/docker/docker-compose.prod.yml'

dc ps                                    # état des services
dc logs -f queue                         # sortie du worker (jobs traités, FAIL)
dc logs -f scheduler                     # tâches planifiées lancées
dc exec app php artisan app:health       # santé complète
dc exec app php artisan queue:failed     # jobs en échec
dc exec app php artisan queue:retry all  # les relancer
dc exec app sh -c 'tail -n 100 storage/logs/laravel-$(date +%F).log'
```

---

## Emails (Brevo)

1. Créer un compte Brevo, puis **SMTP & API > SMTP** : identifiant et clé SMTP dans `MAIL_USERNAME` et
   `MAIL_PASSWORD`.
2. **Expéditeurs, domaines > Domaines** : ajouter le domaine de `MAIL_FROM_ADDRESS` et créer dans son DNS les
   enregistrements SPF, DKIM et DMARC que Brevo affiche. Sans eux, les emails partent en spam.
3. Vérifier : `app:health` doit afficher `Emails (SMTP) : OK`, puis tester « mot de passe oublié ».

L'offre gratuite est limitée en nombre d'emails par jour (environ 300 au moment de l'écriture). Un email refusé
fait échouer son job : il apparaît dans `queue:failed` et dans les logs.

## Backups

`php artisan backup:run` crée une archive (base + fichiers) dans le volume `storage`, donc **sur la même VM**.
Ça protège d'une erreur de manipulation, pas d'une perte de la VM : copier régulièrement les archives ailleurs.
La planification automatique des backups n'est pas encore en place (voir [`docs/backups.md`](../docs/backups.md)).

```bash
dc exec app php artisan backup:run
dc exec app php artisan backup:list
dc cp app:/var/www/html/storage/app/private/backups ./backups-copie
```
