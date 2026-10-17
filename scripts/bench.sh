#!/usr/bin/env bash
# Throughput benchmark: N no-change builds of the generated 320-story Storybook,
# 2 modes x BROWSERS, against a local server and a local worker fleet.
# Env: BENCH_BUILDS (5), BENCH_WORKERS per browser (3), BENCH_CONCURRENCY (4), BENCH_BROWSERS (chromium,firefox)
set -euo pipefail
unset GITHUB_ACTIONS GITHUB_REF GITHUB_REF_NAME GITHUB_HEAD_REF GITHUB_EVENT_PATH 2>/dev/null || true
ROOT=$(cd "$(dirname "$0")/.." && pwd)
WEB=$ROOT/apps/web
PORT=${BENCH_PORT:-8140}
BUILDS=${BENCH_BUILDS:-5}
WORKERS=${BENCH_WORKERS:-3}
CONC=${BENCH_CONCURRENCY:-4}
BROWSERS=${BENCH_BROWSERS:-chromium,firefox}
WORK=$(mktemp -d)
OUT=${BENCH_OUT:-$WORK/results.json}
export APP_ENV=local APP_URL=http://127.0.0.1:$PORT DB_CONNECTION=sqlite DB_DATABASE=$WORK/bench.sqlite
export QUEUE_CONNECTION=sync BROADCAST_CONNECTION=log CACHE_STORE=file SESSION_DRIVER=file
export TINTYPE_DISK_DRIVER=local TINTYPE_DISK_ROOT=$WORK/storage TINTYPE_WORKER_TOKEN=bench TINTYPE_ALLOW_ANY_ARCH=true
export TINTYPE_SHARD_SIZE=${BENCH_SHARD_SIZE:-20} PHP_CLI_SERVER_WORKERS=24
touch "$DB_DATABASE"
PIDS=()
cleanup() {
  for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null || true; done
  lsof -ti "tcp:$PORT" -sTCP:LISTEN 2>/dev/null | xargs kill 2>/dev/null || true
}
trap cleanup EXIT

(cd "$WEB" && php artisan migrate:fresh --force -q)
TOKEN=$(cd "$WEB" && php artisan tintype:project bench Bench --json | tail -1 | python3 -c 'import json,sys;print(json.load(sys.stdin)["token"])')
(cd "$WEB" && php artisan tinker --execute "\$p=App\Models\Project::first(); \$p->settings=['browsers'=>explode(',', '$BROWSERS'), 'a11y'=>false]; \$p->save();" >/dev/null)
(cd "$WEB" && php artisan serve --host=127.0.0.1 --port="$PORT" >"$WORK/server.log" 2>&1) & PIDS+=($!)
for _ in $(seq 1 50); do curl -sf "http://127.0.0.1:$PORT/up" >/dev/null && break; sleep 0.2; done

IFS=, read -ra BLIST <<< "$BROWSERS"
for b in "${BLIST[@]}"; do
  for i in $(seq 1 "$WORKERS"); do
    TINTYPE_SERVER=http://127.0.0.1:$PORT TINTYPE_POLL_MS=300 node "$ROOT/packages/capture/dist/cli.js" worker --browsers "$b" -c "$CONC" --id "bench-$b-$i" >"$WORK/worker-$b-$i.log" 2>&1 & PIDS+=($!)
  done
done

REPO=$WORK/repo && mkdir -p "$REPO" && cd "$REPO" && git init -q -b main && git config user.email b@b && git config user.name b
SB=$ROOT/fixtures/benchmark-storybook/storybook-static
echo '[' > "$OUT"
for n in $(seq 1 "$BUILDS"); do
  echo "$n" > n.txt && git add -A && git commit -qm "build $n"
  start=$(date +%s)
  set +e
  node "$ROOT/packages/cli/dist/cli.js" --server "http://127.0.0.1:$PORT" --project-token "$TOKEN" --storybook-build-dir "$SB" --exit-zero-on-changes > "$WORK/cli-$n.log" 2>&1
  rc=$?
  set -e
  secs=$(( $(date +%s) - start ))
  summary=$(grep -E 'snapshots ·' "$WORK/cli-$n.log" | tail -1)
  status=$(grep -oE 'Build [0-9]+: [a-z]+' "$WORK/cli-$n.log" | tail -1)
  echo "build $n: ${secs}s exit=$rc $status — $summary"
  [ "$n" -gt 1 ] && echo ',' >> "$OUT"
  printf '{"build":%d,"seconds":%d,"exit":%d,"status":"%s","summary":"%s"}' "$n" "$secs" "$rc" "$status" "$summary" >> "$OUT"
done
echo ']' >> "$OUT"
echo "results: $OUT"
