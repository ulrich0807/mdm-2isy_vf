#!/usr/bin/env bash
set -euo pipefail

project_dir="/var/www/mdm-2isy_vf"

echo "Démarrage du déploiement..."
cd "$project_dir"
git pull --ff-only origin master

echo "Mise à jour de l'API Laravel..."
cd "$project_dir/mdm-2isy-api"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
# La commande est relançable : un lien déjà présent n'est pas une anomalie de
# déploiement et ne doit pas masquer le résultat des étapes suivantes.
if [ ! -L public/storage ]; then
    php artisan storage:link
fi
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Construction du frontend Angular..."
cd "$project_dir/mdm-2isy-front"
npm ci
npm run build -- --configuration production

echo "Mise à jour des permissions d'exécution Laravel..."
chown -R www-data:www-data "$project_dir/mdm-2isy-api/storage" "$project_dir/mdm-2isy-api/bootstrap/cache"

echo "Déploiement terminé. Vérifiez que 'php artisan schedule:run' est exécuté chaque minute par cron ou systemd."
