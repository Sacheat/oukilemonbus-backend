#!/bin/sh
# Démarrage des conteneurs app, queue et scheduler (en root, la commande passe ensuite en www-data si besoin).
set -e

cd /var/www/html

# storage/ est un volume : à la première création il est vide, et les dossiers attendus par Laravel doivent exister.
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs
chown -R www-data:www-data storage bootstrap/cache

# Mise en cache de la config, des routes, des vues et des événements. Faite ici en root car le .env monté depuis
# l'hôte n'est souvent lisible que par son propriétaire ; une fois la config en cache, Laravel ne relit plus le .env.
php artisan optimize --no-interaction > /dev/null
chown -R www-data:www-data bootstrap/cache storage/framework

exec "$@"
