# ---------------------------------------------------------------------
# Étape 1 : construction du site (React) — le résultat est copié plus bas
# ---------------------------------------------------------------------
FROM node:22-alpine AS site
WORKDIR /front
COPY front/package.json front/package-lock.json ./
RUN npm ci
COPY front/ ./
RUN npm run build

# ---------------------------------------------------------------------
# Étape 2 : serveur web Apache + PHP (site + API dans le même service)
# ---------------------------------------------------------------------
FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
 && a2dismod -f mpm_event mpm_worker || true \
 && a2enmod mpm_prefork rewrite headers deflate
ENV TZ=Africa/Porto-Novo
COPY docker/apache.conf /etc/apache2/conf-available/ecole.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/ecole.ini
RUN a2enconf ecole
COPY api/ /var/www/html/api/
COPY --from=site /front/dist/ /var/www/html/
COPY sql/ /var/www/sql/
COPY docker/demarrer.sh /usr/local/bin/demarrer.sh
RUN sed -i 's/\r$//' /usr/local/bin/demarrer.sh \
 && chmod +x /usr/local/bin/demarrer.sh \
 && rm -f /var/www/html/api/config.local.php \
 && chown -R www-data:www-data /var/www/html
CMD ["demarrer.sh"]
