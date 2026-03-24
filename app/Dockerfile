# Laravel App Dockerfile — Multi-stage build for ECS Fargate
#
# Stage 1: Install PHP + Composer dependencies and build frontend assets
# Stage 2: Production image with PHP-FPM + nginx via supervisord

# ============================================================
# Stage 1: Build
# ============================================================
FROM php:8.4-cli-alpine AS builder

WORKDIR /app

# Install PHP extensions needed for Laravel + PostgreSQL
RUN apk add --no-cache \
        libpq-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        intl \
        mbstring \
        opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP dependencies (production only)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist

# Install Node.js and build frontend assets
RUN apk add --no-cache nodejs npm
COPY package.json ./
RUN npm install
COPY resources/ resources/
COPY vite.config.js ./
RUN npm run build

# Copy remaining application source
COPY . .

# Run post-install Laravel scripts
RUN composer dump-autoload --optimize

# ============================================================
# Stage 2: Production
# ============================================================
FROM php:8.4-fpm-alpine AS production

WORKDIR /var/www/html

# Install runtime dependencies and PHP extensions
RUN apk add --no-cache \
        nginx \
        supervisor \
        libpq \
        icu-libs \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        libpq-dev \
        icu-dev \
        oniguruma-dev \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        intl \
        mbstring \
        opcache \
    && apk del .build-deps

# Copy built application from builder stage
COPY --from=builder /app /var/www/html

# Ensure storage and cache directories are writable
RUN mkdir -p storage/logs \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# nginx configuration
RUN cat > /etc/nginx/http.d/default.conf <<'NGINX'
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX

# supervisord configuration to run both nginx and PHP-FPM
RUN cat > /etc/supervisord.conf <<'SUPERVISOR'
[supervisord]
nodaemon=true
logfile=/dev/stdout
logfile_maxbytes=0

[program:php-fpm]
command=php-fpm --nodaemonize
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0

[program:nginx]
command=nginx -g "daemon off;"
autostart=true
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
SUPERVISOR

# OPcache configuration for production performance
RUN cat > /usr/local/etc/php/conf.d/opcache.ini <<'OPCACHE'
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0
OPCACHE

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
