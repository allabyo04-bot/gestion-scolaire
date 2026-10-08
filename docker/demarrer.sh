#!/bin/sh
set -e
# 1. Mise à jour de la base (tables, compte administrateur)
php /var/www/sql/migrer.php
# 2. Railway impose le port d'écoute via la variable PORT
PORT="${PORT:-80}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
# 3. Démarrage du serveur web
exec apache2-foreground
