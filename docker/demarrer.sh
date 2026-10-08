#!/bin/sh
set -e
# 1. Mise à jour de la base (tables, compte administrateur)
php /var/www/sql/migrer.php
# 2. Apache : un seul mode de fonctionnement (prefork, requis par PHP)
#    Corrige l'erreur « AH00534: More than one MPM loaded » rencontrée sur Railway
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod mpm_prefork >/dev/null
# 3. Railway impose le port d'écoute via la variable PORT
PORT="${PORT:-80}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
# 4. Démarrage du serveur web
exec apache2-foreground
