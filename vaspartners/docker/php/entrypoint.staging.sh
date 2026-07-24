#!/bin/sh
set -e

cd /var/www/html

# Queue / sidecar containers skip migrate+seed (app container owns bootstrap).
if [ "${VAS_RUN_BOOTSTRAP:-true}" = "false" ]; then
  exec "$@"
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
  if [ "$i" -gt 90 ]; then
    echo "Database not ready after 90s"
    exit 1
  fi
  sleep 1
done

echo "Waiting for redis..."
i=0
until php -r '
  $host = getenv("REDIS_HOST") ?: "redis";
  $port = (int) (getenv("REDIS_PORT") ?: 6379);
  $r = new Redis();
  try {
    if (!$r->connect($host, $port, 1.5)) {
      exit(1);
    }
    $r->ping();
    exit(0);
  } catch (Throwable $e) {
    exit(1);
  }
'; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then
    echo "Redis not ready after 60s"
    exit 1
  fi
  sleep 1
done

if [ -z "${APP_KEY:-}" ]; then
  php artisan key:generate --force
fi

php artisan migrate --force

if [ "${VAS_SEED_ON_START:-false}" = "true" ]; then
  php artisan db:seed --force
fi

php artisan filament:assets --ansi
php artisan storage:link --force 2>/dev/null || true
php artisan config:cache
php artisan route:cache 2>/dev/null || true

exec "$@"
