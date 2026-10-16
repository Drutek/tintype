#!/usr/bin/env bash
# End-to-end: real Laravel server + real capture worker + real CLI against the
# fixture Storybook. Usage: scripts/e2e.sh   (env: E2E_WORKER=native|container)
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WEB=$ROOT/apps/web
PORT=${E2E_PORT:-8123}
WORK=$(mktemp -d)
export APP_ENV=local APP_DEBUG=true APP_URL=http://127.0.0.1:$PORT
export DB_CONNECTION=sqlite DB_DATABASE=$WORK/e2e.sqlite
export QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log CACHE_STORE=file SESSION_DRIVER=file
export TINTYPE_DISK_DRIVER=local TINTYPE_DISK_ROOT=$WORK/storage
export TINTYPE_WORKER_TOKEN=e2e-worker-token TINTYPE_ALLOW_ANY_ARCH=true TINTYPE_SHARD_SIZE=10
export PHP_CLI_SERVER_WORKERS=8
touch "$DB_DATABASE"
if lsof -ti "tcp:$PORT" -sTCP:LISTEN >/dev/null 2>&1; then echo "port $PORT is already in use"; exit 1; fi

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true
  # php -S worker children outlive the parent; free the port explicitly.
  lsof -ti "tcp:$PORT" -sTCP:LISTEN 2>/dev/null | xargs kill 2>/dev/null || true
  [ -n "${WORKER_PID:-}" ] && kill "$WORKER_PID" 2>/dev/null || true
  [ "${E2E_KEEP:-}" = 1 ] || rm -rf "$WORK"
}
trap cleanup EXIT

log() { printf '\n\033[1m▶ %s\033[0m\n' "$*"; }

log "Preparing server ($WORK)"
(cd "$WEB" && php artisan migrate:fresh --force -q)
(cd "$WEB" && php artisan tintype:admin admin@example.com --password=password --org=Acme -q)
TOKEN=$(cd "$WEB" && php artisan tintype:project acme Fixture --json | tail -1 | python3 -c 'import json,sys;print(json.load(sys.stdin)["token"])')
HOST_IP=${E2E_HOST_IP:-192.168.64.1}
if [ "${E2E_WORKER:-native}" = container ]; then
  BIND=0.0.0.0
  export TINTYPE_INTERNAL_URL=http://$HOST_IP:$PORT   # what the containerised worker can reach
else
  BIND=127.0.0.1
fi
(cd "$WEB" && php artisan serve --host="$BIND" --port="$PORT" >"$WORK/server.log" 2>&1) &
SERVER_PID=$!
for _ in $(seq 1 50); do curl -sf "http://127.0.0.1:$PORT/up" >/dev/null && break; sleep 0.2; done

log "Starting capture worker"
if [ "${E2E_WORKER:-native}" = container ]; then
  RUNTIME=${CONTAINER_RUNTIME:-container}
  $RUNTIME run --rm --arch amd64 --rosetta -e TINTYPE_SERVER=http://$HOST_IP:$PORT -e TINTYPE_WORKER_TOKEN -e TINTYPE_POLL_MS=500 \
    ${E2E_CAPTURE_IMAGE:-tintype/capture-chromium:dev} worker >"$WORK/worker.log" 2>&1 &
else
  TINTYPE_SERVER=http://127.0.0.1:$PORT TINTYPE_POLL_MS=300 node "$ROOT/packages/capture/dist/cli.js" worker >"$WORK/worker.log" 2>&1 &
fi
WORKER_PID=$!

# A throwaway git repo so each build has its own commit.
REPO=$WORK/repo
mkdir -p "$REPO" && cd "$REPO"
git init -q -b main && git config user.email e2e@example.com && git config user.name e2e
SB=$ROOT/fixtures/sample-storybook
ONLY=(--only-story-names 'components-*' --only-story-names 'foundations-*' --only-story-names 'edge-cases-media--*' \
      --only-story-names 'edge-cases-layout--*' --only-story-names 'edge-cases-motion--*' --only-story-names 'edge-cases-nondeterminism--*' \
      --only-story-names 'edge-cases-interactions--filled-in')
cli() { node "$ROOT/packages/cli/dist/cli.js" --server "http://127.0.0.1:$PORT" --project-token "$TOKEN" --storybook-build-dir "$SB/storybook-static" "${ONLY[@]}" "$@"; }
commit() { echo "$1" > state.txt && git add -A && git commit -qm "$1"; }
expect_exit() { local want=$1; shift; set +e; "$@"; local got=$?; set -e; [ "$got" = "$want" ] || { echo "expected exit $want, got $got"; tail -50 "$WORK/worker.log"; exit 1; }; }

log "Build 1: first build is auto-accepted"
commit one
expect_exit 0 cli

log "Build 2: no changes → passed"
commit two
expect_exit 0 cli

log "Build 3: change Button colour → changes to review"
cp "$SB/src/components/Button.tsx" "$WORK/Button.tsx.orig"
trap 'cp "$WORK/Button.tsx.orig" "$SB/src/components/Button.tsx"; (cd "$SB" && pnpm -s build-storybook >/dev/null 2>&1); cleanup' EXIT
sed -i.bak "s/#2563eb/#9333ea/" "$SB/src/components/Button.tsx" && rm -f "$SB/src/components/Button.tsx.bak"
(cd "$SB" && pnpm -s build-storybook >/dev/null 2>&1)
git checkout -q -b feature/purple && commit three
expect_exit 1 cli
expect_exit 0 cli --exit-zero-on-changes

if [ -n "${E2E_REVIEW_UI:-1}" ] && [ -f "$ROOT/scripts/e2e-review.mjs" ]; then
  log "Accept the changes in the review UI (Playwright)"
  node "$ROOT/scripts/e2e-review.mjs" "http://127.0.0.1:$PORT" admin@example.com password acme fixture 4 "${E2E_SCREENSHOTS:-}"
  log "Build 5: accepted baseline → passed"
  commit five
  expect_exit 0 cli
fi

log "E2E passed"
