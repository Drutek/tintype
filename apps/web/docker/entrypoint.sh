#!/bin/sh
set -e
cd /app
role=${1:-web}
shift || true

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is not set. Generate one with: docker run --rm tintype/web key" >&2
  [ "$role" = key ] || exit 1
fi

case "$role" in
  key)
    php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
    ;;
  web)
    if [ "${TINTYPE_AUTO_MIGRATE:-true}" = true ]; then php artisan migrate --force --isolated; fi
    php artisan optimize
    exec frankenphp php-server --root public --listen "${SERVER_NAME:-:8080}" "$@"
    ;;
  horizon)
    php artisan optimize
    exec php artisan horizon "$@"
    ;;
  queue)
    exec php artisan queue:work --tries=3 "$@"
    ;;
  reverb)
    exec php artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8081}" "$@"
    ;;
  scheduler)
    exec php artisan schedule:work "$@"
    ;;
  migrate)
    exec php artisan migrate --force "$@"
    ;;
  artisan)
    exec php artisan "$@"
    ;;
  *)
    exec "$role" "$@"
    ;;
esac
