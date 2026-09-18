#!/bin/bash
echo "Démarrage du déploiement..."
cd /var/www/mdm-2isy_vf
git pull origin master

echo "Mise à jour de l'API (Laravel)..."
cd mdm-2isy-api
composer install --no-interaction --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Mise à jour du Frontend (Angular)..."
cd ../mdm-2isy-front
npm install
npm run build -- --configuration production

echo "Mise à jour des permissions..."
chown -R www-data:www-data /var/www/mdm-2isy_vf

echo "Déploiement terminé avec succès !"
