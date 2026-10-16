# Progress log

How each milestone was verified, and what is still open.

## 2026-10-16

### Verified locally (macOS arm64 host, Apple `container` 1.1.0 for amd64 images)
- **Determinism:** see [determinism.md](determinism.md) for Chromium (20 runs),
  Firefox (5 runs) and WebKit (5 runs) in amd64 containers.
- **Test suites:**
  - PHP: 83 tests (74 pass, 9 skipped for disabled Fortify features), plus
    PHPStan level 8 at 0 errors and Pint clean.
  - TypeScript packages: Vitest suites for diff, capture, CLI and addon, plus
    `tsc`.
- **End-to-end (`scripts/e2e.sh`), native worker.** Real Laravel server,
  capture worker and CLI against the fixture Storybook:
  1. Build 1 is auto-accepted (first build).
  2. Build 2 passes (26–29 unchanged).
  3. A Button colour change gives build 3 pending, with exactly the 3
     Button-dependent stories changed.
  4. Playwright accepts the changes in the review UI.
  5. Build 5 passes.
  6. The Storybook addon panel shows live build status, and its signed
     snapshot image loads.
- **End-to-end, containerised worker.** Same flow with the worker in the amd64
  capture image, reaching the host server through `TINTYPE_INTERNAL_URL`. See
  Passed with the same results as the native run.
- **Web image:** `apps/web/docker/Dockerfile` builds for amd64. The container
  serves `/up` and `/register` and runs its migrations.
- **Terraform:** `terraform validate` passes (hashicorp/terraform:1.9 in a
  container).

### Open items (need resources outside this machine)
1. **Push the rewritten history.** Local `main` is a fresh history. The public
   repo still has earlier commits, and the agent wasn't allowed to force-push:
   `git push --force -u origin main`. That first push also runs the CI and
   native-x86_64 determinism workflows and publishes the images to GHCR.
2. **Live GitHub App.** Create the app from Admin → GitHub App on an install
   reachable from GitHub. Then confirm check runs and UI Review checks on a real
   PR. The API calls are covered by tests with faked HTTP.
3. **AWS performance acceptance (Phase 2).** Apply `deploy/terraform/aws`, then
   time a ≥300-story Storybook with 2 modes × 2 browsers. Target: under 5
   minutes, with 0 inconsistent snapshots over 10 no-change builds.
4. **npm publish.** `@tintype/cli` and `@tintype/storybook-addon` are ready
   to publish, but haven't been published yet.
5. **Storybook 8/9 matrix.** Capture supports pre-8.4 Storybook (no
   `storyFinished` event) through the render-phase fallback. Only Storybook 10
   is exercised by the fixture so far.
