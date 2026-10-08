#!/usr/bin/env bash
set -euo pipefail

project_dir="/var/www/mdm-2isy_vf"
nginx_site="/etc/nginx/sites-available/mdm-2isy.conf"
php_fpm_version="${MDM_PHP_FPM_VERSION:-8.3}"
php_upload_ini="/etc/php/${php_fpm_version}/fpm/conf.d/99-mdm-upload.ini"
php_fpm_service="php${php_fpm_version}-fpm"

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

echo "Application des limites d'envoi APK (250 Mio)..."
install -m 0644 "$project_dir/php-mdm-upload.ini" "$php_upload_ini"

# Conserver automatiquement la configuration Nginx precedente si la nouvelle
# version ne passe pas sa validation, afin de ne pas fragiliser le prochain
# redemarrage du serveur.
nginx_backup="$(mktemp)"
nginx_site_existed=0
if [ -f "$nginx_site" ]; then
    cp -p "$nginx_site" "$nginx_backup"
    nginx_site_existed=1
fi
install -m 0644 "$project_dir/mdm-2isy.conf" "$nginx_site"
if ! nginx -t; then
    if [ "$nginx_site_existed" -eq 1 ]; then
        cp -p "$nginx_backup" "$nginx_site"
    else
        rm -f "$nginx_site"
    fi
    rm -f "$nginx_backup"
    echo "Configuration Nginx invalide : restauration de la version precedente." >&2
    exit 1
fi
rm -f "$nginx_backup"

systemctl restart "$php_fpm_service"
systemctl reload nginx

echo "Mise à jour des permissions d'exécution Laravel..."
chown -R www-data:www-data "$project_dir/mdm-2isy-api/storage" "$project_dir/mdm-2isy-api/bootstrap/cache"

echo "Déploiement terminé. Vérifiez que 'php artisan schedule:run' est exécuté chaque minute par cron ou systemd."
