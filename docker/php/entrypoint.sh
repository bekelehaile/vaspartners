#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  echo "Missing backend/.env — copy from .env.example and set FAYDA_* first."
  exit 1
fi

# Vendor lives in a named volume on first boot
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist --no-ansi
fi

# Wait for Postgres via PgBouncer
echo "Waiting for database..."
i=0
until php -r '
  try {
    new PDO(
      "pgsql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT") . ";dbname=" . getenv("DB_DATABASE"),
      getenv("DB_USERNAME"),
      getenv("DB_PASSWORD")
    );
    exit(0);
  } catch (Throwable $e) {
    exit(1);
  }
'; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then
    echo "Database not ready after 60s"
    exit 1
  fi
  sleep 1
done

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "" ]; then
  php artisan key:generate --force
fi

php artisan migrate --force

if [ "${VAS_SEED_ON_START:-true}" = "true" ]; then
  php artisan db:seed --force
fi

exec "$@"
