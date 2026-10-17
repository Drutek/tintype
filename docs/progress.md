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
2. **Live GitHub App.** In progress (2026-10-17):
   - A local install is exposed through a cloudflared quick tunnel, behind
     `TRUSTED_PROXIES=*`.
   - The manifest flow for **Drutek** was submitted, and GitHub stopped at its
     sudo-mode "Confirm access" passkey prompt, which only the account owner
     can complete.
   - After that: install the app on `Drutek/tintype`, then run
     `scripts/live-github-check.sh`. It opens a draft PR, runs a baseline build
     and a changed build, and prints the Visual Tests and UI Review check runs.
3. **AWS performance acceptance (Phase 2).** Met locally: 1,280 snapshots in
   about 80s per build, 0 inconsistent over 10 builds (see
   [benchmark.md](benchmark.md)). The AWS run is deferred until credentials are
   available.
4. *(Done.)* Published to GitHub Packages as `@drutek/tintype-cli@0.1.0` and
   `@drutek/tintype-storybook-addon@0.1.0` by `.github/workflows/publish-packages.yml`.
5. **Storybook 8/9 matrix.** Capture supports pre-8.4 Storybook (no
   `storyFinished` event) through the render-phase fallback. Only Storybook 10
   is exercised by the fixture so far.
