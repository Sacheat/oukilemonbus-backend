# Déploiement en production

Deux options, selon ce que permet la VM :

| Option | Quand | Dossier |
|---|---|---|
| **Docker** (recommandée) | Docker est installé ou installable sur la VM | [`deploy/docker/`](docker/) |
| Sans Docker | Docker interdit : PHP, Nginx et MariaDB installés directement | [`deploy/native/`](native/) |

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
docker compose --env-file .env -f deploy/docker/docker-compose.prod.yml exec -u www-data -e HOME=/tmp app php artisan tinker
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
# artisan toujours en www-data (-u www-data) : un log créé par root bloquerait l'écriture des logs de l'API
dc exec -u www-data app php artisan app:health       # santé complète
dc exec -u www-data app php artisan queue:failed     # jobs en échec
dc exec -u www-data app php artisan queue:retry all  # les relancer
dc exec app sh -c 'tail -n 100 storage/logs/laravel-$(date +%F).log'
```

---

## Option sans Docker

Tout est installé directement sur la VM : Nginx, PHP 8.4 (php-fpm), MariaDB, Supervisor pour le worker de
file et cron pour le scheduler. Les commandes ci-dessous visent **Debian 13**, qui fournit PHP 8.4 dans ses
paquets officiels.

| Fichier | Installé dans | Rôle |
|---|---|---|
| [`native/nginx-bus-communal.conf`](native/nginx-bus-communal.conf) | `/etc/nginx/sites-available/bus-communal` | Site de l'API |
| [`php/production.ini`](php/production.ini) | `/etc/php/8.4/fpm/conf.d/99-bus-communal.ini` | Réglages PHP (communs avec Docker) |
| [`native/supervisor-bus-communal-worker.conf`](native/supervisor-bus-communal-worker.conf) | `/etc/supervisor/conf.d/bus-communal-worker.conf` | Worker de file, relancé automatiquement |
| [`native/cron-bus-communal`](native/cron-bus-communal) | `/etc/cron.d/bus-communal` | Scheduler (`schedule:run` chaque minute) |
| [`native/deploy.sh`](native/deploy.sh) | — | Installation et mises à jour |

**Utilisateurs :** le code appartient à l'utilisateur qui déploie (celui qui fait `git pull`, avec `sudo`).
php-fpm, le worker, le cron et toutes les commandes `artisan` tournent en `www-data`, seul propriétaire de
`storage/` et `bootstrap/cache/`. Lancer `php artisan` sans `sudo -u www-data` peut créer un fichier de log
que l'application ne pourra plus écrire.

### Prérequis sur la VM

```bash
sudo apt update
sudo apt install -y nginx mariadb-server supervisor cron git unzip composer \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-zip php8.4-gd \
    php8.4-intl php8.4-bcmath php8.4-curl php8.4-opcache
php -v   # doit afficher 8.4
```

- **Ubuntu 24.04** n'a que PHP 8.3 : ajouter d'abord le dépôt `ppa:ondrej/php`
  (`sudo add-apt-repository ppa:ondrej/php && sudo apt update`), puis la même commande.
- **MariaDB déjà présent sur la VM :** retirer `mariadb-server` de la liste et créer la base dans l'existant.
- **Nginx déjà présent :** retirer `nginx`, on y ajoute simplement un site.

### Première installation

```bash
# 1. Base de données (remplacer le mot de passe, à reporter dans DB_PASSWORD)
sudo mariadb -e "CREATE DATABASE bus_communal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'bus_user'@'localhost' IDENTIFIED BY 'mot-de-passe-base';
  GRANT ALL PRIVILEGES ON bus_communal.* TO 'bus_user'@'localhost';"

# 2. Code
sudo mkdir -p /opt/bus-communal && sudo chown "$USER": /opt/bus-communal
git clone git@github.com:Sacheat/bus-communal-backend.git /opt/bus-communal
cd /opt/bus-communal

# 3. Configuration : DB_HOST=localhost, remplacer chaque <...> ; les lignes « Docker uniquement » sont ignorées
cp deploy/.env.production.example .env
nano .env
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'   # à coller dans APP_KEY=
sudo chgrp www-data .env && chmod 640 .env

# 4. Droits : seul www-data écrit dans storage/ et bootstrap/cache/
sudo chown -R www-data:www-data storage bootstrap/cache

# 5. PHP et Nginx (remplacer api.exemple.be dans le fichier du site)
sudo cp deploy/php/production.ini /etc/php/8.4/fpm/conf.d/99-bus-communal.ini
sudo cp deploy/native/nginx-bus-communal.conf /etc/nginx/sites-available/bus-communal
sudo nano /etc/nginx/sites-available/bus-communal
sudo ln -s /etc/nginx/sites-available/bus-communal /etc/nginx/sites-enabled/bus-communal
sudo rm -f /etc/nginx/sites-enabled/default   # seulement si la VM ne sert rien d'autre
sudo nginx -t && sudo service nginx reload

# 6. Déployer : dépendances, migrations, rôles et permissions, caches, vérification
deploy/native/deploy.sh --no-pull

# 7. Worker et scheduler, une fois la base prête
sudo cp deploy/native/supervisor-bus-communal-worker.conf /etc/supervisor/conf.d/bus-communal-worker.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo cp deploy/native/cron-bus-communal /etc/cron.d/bus-communal
sudo supervisorctl status   # bus-communal-worker doit être RUNNING
```

Le premier administrateur se crée comme pour Docker (voir [Créer le premier administrateur](#créer-le-premier-administrateur)),
avec `sudo -u www-data env HOME=/tmp php artisan tinker` (`HOME=/tmp` : tinker a besoin d'un dossier où écrire
sa config, et `www-data` n'en a pas).

**HTTPS :** `sudo apt install -y certbot python3-certbot-nginx && sudo certbot --nginx -d api.exemple.be`.
Certbot ajoute le certificat au site et le renouvelle tout seul.

### Mettre à jour

```bash
cd /opt/bus-communal
deploy/native/deploy.sh
```

Le script met l'API en maintenance, fait `git pull --ff-only`, `composer install --no-dev`, les migrations et
le seeder des rôles, remet les caches, recharge php-fpm (pour OPcache), demande au worker de redémarrer
après son job en cours (`queue:restart`, Supervisor le relance), puis sort de maintenance. En cas d'erreur,
l'API ne reste pas en maintenance.

Après une modification du `.env` seul : `deploy/native/deploy.sh --no-pull`.

Si la VM a un autre utilisateur web ou une autre version de php-fpm :
`APP_USER=nginx PHP_FPM_SERVICE=php8.4-fpm deploy/native/deploy.sh`. Penser alors à adapter `user=` dans la
config Supervisor et l'utilisateur de la ligne cron.

### Commandes utiles

```bash
sudo supervisorctl status                              # worker
sudo tail -f /var/log/supervisor/bus-communal-worker.log  # jobs traités, FAIL
sudo -u www-data php artisan app:health                # santé complète
sudo -u www-data php artisan schedule:list             # tâches planifiées
sudo -u www-data php artisan queue:failed              # jobs en échec
sudo -u www-data php artisan queue:retry all           # les relancer
tail -n 100 storage/logs/laravel-$(date +%F).log
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

Chaque nuit à 03:30, le scheduler lance `backup:run` : une archive **chiffrée** (base + fichiers,
`*.tar.gz.enc`) dans `storage/app/private/backups` (le volume `storage` avec Docker), gardée 14 jours
(`BACKUP_RETENTION_DAYS`). En production, un backup non chiffré est refusé.

**1. Clé de chiffrement** (une fois) :

```bash
dc exec -u www-data app php artisan backup:generate-key   # sans Docker : php artisan backup:generate-key
```

Copier la clé dans `.env` (`BACKUP_ENCRYPTION_ENABLED=true`, `BACKUP_ENCRYPTION_KEY=base64:…`), puis redéployer.
**La ranger aussi hors de la VM** (gestionnaire de mots de passe de la commune) : sans elle, aucun backup n'est
récupérable, et changer de clé rend les anciennes archives illisibles avec la nouvelle.

**2. Copie hors de la VM** : l'archive est sur la même VM, elle protège d'une erreur, pas d'une perte de la VM.
`deploy/backup-offsite.sh` copie les archives chiffrées (et elles seules) vers une destination
[rclone](https://rclone.org/) : NAS de la commune, stockage S3, autre serveur en SFTP…

```bash
sudo apt install rclone && rclone config        # créer la destination, ex. « nas-commune »
deploy/backup-offsite.sh nas-commune:bus-communal/backups   # test à la main
```

Cron sur la VM (root pour lire le volume Docker), après le backup de 03:30 :

```cron
15 4 * * * root /opt/bus-communal/deploy/backup-offsite.sh nas-commune:bus-communal/backups >> /var/log/bus-communal-offsite.log 2>&1
```

`OFFSITE_RETENTION_DAYS` (défaut 30) supprime sur la destination les archives plus anciennes.

**Commandes utiles** :

```bash
dc exec -u www-data app php artisan backup:run
dc exec -u www-data app php artisan backup:list
dc exec -u www-data app php artisan backup:decrypt storage/app/private/backups/<archive>.tar.gz.enc
```

Restauration : voir [`docs/backups.md`](../docs/backups.md).

