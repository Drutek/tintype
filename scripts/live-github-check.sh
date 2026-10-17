#!/usr/bin/env bash
# Live GitHub App check against a running, publicly reachable Tintype install.
# Prereqs: the GitHub App is created (Admin → GitHub App) and installed on $REPO,
# and the project's repository is set to $REPO.
# Usage: TINTYPE_SERVER=https://… TINTYPE_PROJECT_TOKEN=tt_… scripts/live-github-check.sh
set -euo pipefail
unset GITHUB_ACTIONS GITHUB_REF GITHUB_REF_NAME GITHUB_HEAD_REF GITHUB_EVENT_PATH 2>/dev/null || true
ROOT=$(cd "$(dirname "$0")/.." && pwd)
REPO=${REPO:-Drutek/tintype}
BRANCH=${BRANCH:-tintype-live-check-$(date +%s)}
WORK=$(mktemp -d)
SB=$ROOT/fixtures/sample-storybook
CLI="node $ROOT/packages/cli/dist/cli.js --server $TINTYPE_SERVER --project-token $TINTYPE_PROJECT_TOKEN --only-story-names components-button--*"

gh repo clone "$REPO" "$WORK/repo" -- --depth 50 -q
cd "$WORK/repo"
git checkout -q -b "$BRANCH"
echo "live check $(date -u +%FT%TZ)" > .tintype-live-check
git add .tintype-live-check && git commit -qm "Tintype live check (baseline)"
git push -q -u origin "$BRANCH"
PR=$(gh pr create --repo "$REPO" --head "$BRANCH" --title "Tintype live check" --body "Automated check of the Tintype GitHub App (Visual Tests + UI Review). Safe to close." --draft | grep -oE '[0-9]+$')
echo "PR #$PR"

echo "▶ build 1 (baseline)"
$CLI --storybook-build-dir "$SB/storybook-static" || true
B1=$(git rev-parse HEAD)

echo "▶ build 2 (purple button)"
cp "$SB/src/components/Button.tsx" "$WORK/Button.orig"
trap 'cp "$WORK/Button.orig" "$SB/src/components/Button.tsx"; (cd "$SB" && pnpm -s build-storybook >/dev/null 2>&1)' EXIT
sed -i.bak "s/#2563eb/#9333ea/" "$SB/src/components/Button.tsx" && rm -f "$SB/src/components/Button.tsx.bak"
(cd "$SB" && pnpm -s build-storybook >/dev/null 2>&1)
echo "change $(date +%s)" >> .tintype-live-check && git commit -qam "Tintype live check (change)" && git push -q
$CLI --storybook-build-dir "$SB/storybook-static" --exit-zero-on-changes
B2=$(git rev-parse HEAD)

for sha in "$B1" "$B2"; do
  echo "check runs on ${sha:0:8}:"
  gh api "repos/$REPO/commits/$sha/check-runs" --jq '.check_runs[] | "  \(.name): \(.status) \(.conclusion // "-") — \(.output.title)"'
done
echo "PR=$PR BRANCH=$BRANCH HEAD=$B2"
