#!/usr/bin/env bash
# Build the sample fixture's stories against other Storybook releases and check
# that every story gets the same capture status as on the fixture's own
# Storybook 10 build, and that captures stay deterministic.
# Supported: Storybook 9 and 10. 8.x still installs here (8.3 exercises the
# pre-8.4 render-phase fallback) but is not supported.
# Usage: scripts/storybook-matrix.sh [9.1 8.6 ...]   (default: 9.1)
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
SRC=$ROOT/fixtures/sample-storybook
CAPTURE="node $ROOT/packages/capture/dist/cli.js"
RUNS=${RUNS:-5}
VERSIONS=("$@"); [ ${#VERSIONS[@]} -eq 0 ] && VERSIONS=(9.1)
WORK=${WORK:-$(mktemp -d)}; mkdir -p "$WORK"
STATUS=0
statuses() { node -e 'for (const r of require(process.argv[1]).results) console.log(r.storyId, r.mode.key, r.status)' "$1/manifest.json" | sort; }

# Reference: per-story statuses on the fixture's own Storybook 10 build.
$CAPTURE run -s "$SRC/storybook-static" -o "$WORK/ref" > "$WORK/ref.log" 2>&1 || true
statuses "$WORK/ref" > "$WORK/ref.status"

for v in "${VERSIONS[@]}"; do
  major=${v%%.*}
  dir=$WORK/sb$v
  rm -rf "$dir" && mkdir -p "$dir/.storybook"
  cp -R "$SRC/src" "$SRC/public" "$SRC/tsconfig.json" "$dir/"
  cp "$SRC/.storybook/preview.tsx" "$SRC/.storybook/preview.css" "$dir/.storybook/"
  if [ "$major" -lt 9 ]; then
    vite=^6 plugin=^4 test="@storybook/test@~$v"
    case $v in 8.[0-5]*) vite=^5 ;; esac
    # Storybook 8 ships test utilities as @storybook/test.
    grep -rl "'storybook/test'" "$dir/src" | xargs sed -i.bak "s#'storybook/test'#'@storybook/test'#" && find "$dir/src" -name '*.bak' -delete
  else
    vite=^7 plugin=^5 test=""
  fi
  cat > "$dir/.storybook/main.ts" <<'EOF'
import type { StorybookConfig } from '@storybook/react-vite';
const config: StorybookConfig = {
  stories: ['../src/**/*.stories.@(ts|tsx)'],
  framework: { name: '@storybook/react-vite', options: {} },
  staticDirs: ['../public'],
  core: { disableTelemetry: true },
};
export default config;
EOF
  echo '{"name":"sb-matrix","private":true,"type":"module"}' > "$dir/package.json"
  echo "▶ Storybook $v: install"
  (cd "$dir" && npm install --silent --no-audit --no-fund \
    "storybook@~$v" "@storybook/react-vite@~$v" $test \
    react@^19 react-dom@^19 @fontsource/inter@^5 "vite@$vite" "@vitejs/plugin-react@$plugin" typescript@^5 > "$dir/install.log" 2>&1) || { tail -15 "$dir/install.log"; exit 1; }
  echo "▶ Storybook $v: build ($(cd "$dir" && node -p 'require("storybook/package.json").version'))"
  (cd "$dir" && npx storybook build -o storybook-static --quiet >/dev/null 2>&1)
  echo "▶ Storybook $v: statuses vs Storybook 10"
  $CAPTURE run -s "$dir/storybook-static" -o "$dir/run" > "$dir/run.log" 2>&1 || true
  if statuses "$dir/run" | diff "$WORK/ref.status" - > "$dir/status.diff"; then
    echo "    $(wc -l < "$WORK/ref.status" | tr -d ' ') snapshots, same statuses as Storybook 10"
  else
    STATUS=1; echo "  ✗ statuses differ"; sed 's/^/    /' "$dir/status.diff" | head -20
  fi
  echo "▶ Storybook $v: determinism ×$RUNS"
  if $CAPTURE determinism -s "$dir/storybook-static" -n "$RUNS" -o "$dir/out" > "$dir/determinism.log" 2>&1; then
    tail -3 "$dir/determinism.log" | sed 's/^/    /'
  else
    STATUS=1; echo "  ✗ failed"; tail -20 "$dir/determinism.log" | sed 's/^/    /'
  fi
done
echo "work dir: $WORK"
exit $STATUS
