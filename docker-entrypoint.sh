#!/bin/sh
set -e

echo "🚀 Starting Chat Bridge Docker initialization..."

# Ensure writable paths exist and ownership is correct for app user.
echo "🔧 Preparing writable paths..."
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/storage/app/public \
         /var/www/html/database

# IMPORTANT: storage/postgres is a bind-mounted sub-path that belongs entirely
# to the postgres container (its own UID/user, e.g. UID 70 in the postgres
# image, or whatever LOCAL_UID maps to). It is excluded (via `-prune`) from
# every chown/chmod pass below.
#
# This used to chown the WHOLE storage tree to www-data, then try to
# "restore" postgres's ownership afterward with `chown -R 70:70 ... || true`.
# That restore only works if this container can chown to an arbitrary UID
# (needs root). When it can't, the restore silently no-ops and Postgres is
# left owned by www-data — which breaks it the moment its own process tries
# to read/write its data files (this is exactly what caused the
# "Permission denied" / "could not open file base/..." crash loop on
# 2026-07-09). Excluding the path up front avoids the problem instead of
# trying to undo it after the fact.
find /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database \
     -path /var/www/html/storage/postgres -prune -o \
     -exec chown www-data:www-data {} \; 2>/dev/null || true

find /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database \
     -path /var/www/html/storage/postgres -prune -o \
     -type d -exec chmod 775 {} \; 2>/dev/null || true

find /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database \
     -path /var/www/html/storage/postgres -prune -o \
     -type f -exec chmod 664 {} \; 2>/dev/null || true

# Ensure .env file has correct permissions
touch /var/www/html/.env
chmod 664 /var/www/html/.env
chown www-data:www-data /var/www/html/.env

# Clear cached framework files to avoid stale package discovery issues
rm -f /var/www/html/bootstrap/cache/*.php

# Ensure SQLite database exists with correct permissions
touch /var/www/html/database/database.sqlite
chmod 664 /var/www/html/database/database.sqlite
chown www-data:www-data /var/www/html/database/database.sqlite

# Prepare Codex CLI home/config (uses OPENAI_API_KEY via app, but CLI prefers apikey auth mode)
if [ -n "${CODEX_HOME}" ]; then
    mkdir -p "${CODEX_HOME}"
    if [ ! -f "${CODEX_HOME}/config.toml" ]; then
        cat <<EOF > "${CODEX_HOME}/config.toml"
preferred_auth_method = "apikey"
cli_auth_credentials_store = "file"
EOF
    fi
    if [ ! -d "${CODEX_HOME}/skills" ]; then
        echo "⚠️  Codex skills directory not found at ${CODEX_HOME}/skills"
    fi
    chown -R www-data:www-data "${CODEX_HOME}"
fi

# Wait for PostgreSQL to be ready
if [ "${DB_CONNECTION}" = "pgsql" ]; then
    echo "⏳ Waiting for PostgreSQL..."
    until pg_isready -h "${DB_HOST}" -U "${DB_USERNAME}"; do
        echo "PostgreSQL is unavailable - sleeping"
        sleep 2
    done
    echo "✅ PostgreSQL is ready!"
fi

# Wait for Redis to be ready
if [ "${QUEUE_CONNECTION}" = "redis" ] || [ "${CACHE_STORE}" = "redis" ]; then
    echo "⏳ Waiting for Redis..."
    until redis-cli -h "${REDIS_HOST}" ping > /dev/null 2>&1; do
        echo "Redis is unavailable - sleeping"
        sleep 2
    done
    echo "✅ Redis is ready!"
fi

# Wait for Qdrant to be ready
if [ "${QDRANT_ENABLED}" = "true" ]; then
    echo "⏳ Waiting for Qdrant..."
    until wget --spider -q "http://${QDRANT_HOST}:${QDRANT_PORT}/" > /dev/null 2>&1; do
        echo "Qdrant is unavailable - sleeping"
        sleep 2
    done
    echo "✅ Qdrant is ready!"
fi

# Run database migrations (skip for reverb service)
if [ "${SKIP_MIGRATIONS:-false}" != "true" ]; then
    echo "📊 Running database migrations..."
    php artisan migrate --force
else
    echo "⏭️  Skipping database migrations..."
fi

# Ensure seed data exists (skip when explicitly disabled)
if [ "${SKIP_SEEDING:-false}" != "true" ]; then
    echo "🌱 Seeding database..."
    php artisan db:seed --force
    echo "✅ Database seeding complete."
else
    echo "⏭️  Skipping database seeding..."
fi

# Clear and cache configuration
if [ "${APP_ENV}" = "production" ]; then
    echo "⚙️  Optimizing application for production..."
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
else
    echo "⚙️  Clearing cached configuration for non-production environment..."
    php artisan optimize:clear
fi

# Initialize Qdrant if enabled
if [ "${QDRANT_ENABLED}" = "true" ]; then
    echo "🧠 Initializing Qdrant vector database..."
    php artisan qdrant:init
fi

echo "✨ Chat Bridge is ready!"

# Execute the main command
exec "$@"
