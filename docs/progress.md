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
1. *(Done.)* The repo now lives at github.com/Drutek/tintype. Native x86_64
   determinism passed in CI: 31/31 identical over 20 runs, byte-identical to
   the Rosetta captures.
2. *(Done, 2026-10-17.)* **Live GitHub App.** The "Tintype Drutek" app was
   created through the manifest flow and installed on `Drutek/tintype` only.
   `scripts/live-github-check.sh` (draft PR #1) verified:
   - the baseline commit got Visual Tests: success ("All changes accepted");
   - the changed commit got Visual Tests: action_required ("2 changes need
     review");
   - approving on the PR page posted UI Review: success;
   - accepting the build flipped Visual Tests to success.

   The live run found two bugs that faked-HTTP tests could not catch, both now
   fixed and covered by tests:
   - The manifest conversion POST sent a `[]` body, which GitHub rejects with
     a 422. It now sends `{}`.
   - The webhook route excluded `ValidateCsrfToken`, but Laravel 13's web group
     uses `PreventRequestForgery`, so every delivery returned a 419.
3. **AWS performance acceptance (Phase 2).** Met locally: 1,280 snapshots in
   about 80s per build, 0 inconsistent over 10 builds (see
   [benchmark.md](benchmark.md)). The AWS run is deferred until credentials are
   available.
4. *(Done.)* Published to GitHub Packages as `@drutek/tintype-cli@0.1.0` and
   `@drutek/tintype-storybook-addon@0.1.0` by `.github/workflows/publish-packages.yml`.
5. *(Done, 2026-10-17.)* **Storybook versions.** Storybook 9 and 10 are
   supported. `scripts/storybook-matrix.sh` rebuilds the fixture's stories on
   Storybook 9.1.21. All 32 snapshots got the same status as on Storybook 10,
   and 31/31 were identical over 3 runs. The excluded snapshot is the story
   that deliberately throws on render. Storybook 8 is not supported. The
   pre-8.4 render-phase fallback stays in capture because it costs little.
