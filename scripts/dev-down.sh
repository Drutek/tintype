#!/usr/bin/env bash
set -uo pipefail
RUNTIME=${CONTAINER_RUNTIME:-container}
for n in tintype-pg tintype-redis tintype-minio; do $RUNTIME stop "$n" >/dev/null 2>&1 && echo "stopped $n"; done
