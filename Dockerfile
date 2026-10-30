FROM webdevops/php-apache:8.5

ENV WEB_DOCUMENT_ROOT=/app/public \
    PHP_MEMORY_LIMIT=512M \
    PHP_MAX_EXECUTION_TIME=300 \
    PHP_POST_MAX_SIZE=64M \
    PHP_UPLOAD_MAX_FILESIZE=64M \
    PHP_DISMOD=ioncube \
    PHP_ENMOD=mailparse

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . /app

# Devices authenticate with HTTP Basic auth; pass the Authorization header
# through to FastCGI (PHP_AUTH_USER / HTTP_AUTHORIZATION).
RUN printf '<Directory "/app/public">\n    CGIPassAuth On\n</Directory>\n' \
    > /opt/docker/etc/httpd/vhost.common.d/11-cgipassauth.conf

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    # The code stays root-owned (read-only for the web server user). Only the
    # runtime-writable directories belong to the application user: var/
    # (Z-Push state EAS_STATE_DIR, logs EAS_LOG_DIR, auth-throttle cache) and
    # data/ (attachment storage, persistent volume). The base image ships
    # /app owned by application, hence the explicit (non-recursive) chown.
    && chown root:root /app \
    && mkdir -p /app/var/eas-state /app/var/log/eas /app/var/cache /app/data/attachments \
    && chown -R application:application /app/var /app/data
