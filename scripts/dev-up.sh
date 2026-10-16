#!/usr/bin/env bash
# Start Postgres, Redis and MinIO with Apple's `container` CLI (no compose needed).
# Usage: scripts/dev-up.sh            Stop with: scripts/dev-down.sh
set -euo pipefail
RUNTIME=${CONTAINER_RUNTIME:-container}

if [ "$RUNTIME" = container ]; then
  container system status >/dev/null 2>&1 || container system start
fi
# Named volumes: bind mounts break Postgres' chown on startup.
for v in tintype-pg tintype-minio; do
  $RUNTIME volume inspect "$v" >/dev/null 2>&1 || $RUNTIME volume create "$v" >/dev/null
done

start() { # name image args...
  local name=$1; shift
  if $RUNTIME inspect "$name" >/dev/null 2>&1; then
    $RUNTIME start "$name" >/dev/null 2>&1 || true
  else
    $RUNTIME run -d --name "$name" "$@" >/dev/null
  fi
  echo "started $name"
}

start tintype-pg -e POSTGRES_PASSWORD=tintype -e POSTGRES_USER=tintype -e POSTGRES_DB=tintype \
  -v tintype-pg:/var/lib/postgresql/data -e PGDATA=/var/lib/postgresql/data/pgdata -p 5432:5432 postgres:16-alpine
start tintype-redis -p 6379:6379 redis:7-alpine
start tintype-minio -e MINIO_ROOT_USER=tintype -e MINIO_ROOT_PASSWORD=tintype-secret \
  -v tintype-minio:/data -p 9000:9000 -p 9001:9001 quay.io/minio/minio:latest server /data --console-address :9001

cat <<MSG

Postgres  127.0.0.1:5432  tintype/tintype db=tintype
Redis     127.0.0.1:6379
MinIO     http://127.0.0.1:9000 (console :9001) tintype/tintype-secret

apps/web/.env:
  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=tintype DB_USERNAME=tintype DB_PASSWORD=tintype
  REDIS_HOST=127.0.0.1 QUEUE_CONNECTION=redis
  TINTYPE_DISK_DRIVER=s3 TINTYPE_S3_ENDPOINT=http://127.0.0.1:9000 TINTYPE_S3_PATH_STYLE=true
  TINTYPE_S3_KEY=tintype TINTYPE_S3_SECRET=tintype-secret TINTYPE_S3_BUCKET=tintype
MSG
