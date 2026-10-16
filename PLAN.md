# Plan: Open-Source, Self-Hosted Visual Regression for Storybook

Project name: **Tintype** (npm scope `@tintype`, CLI binary `tintype`). Renameable before first public release.

Goal: a self-hostable, full-featured visual testing service for Storybook. Screenshots are always captured server-side in pinned containers so results are pixel-consistent.

Decisions so far (2026-10-16):

| Topic | Decision |
|---|---|
| Distribution | Open source, self-hosted. Each install is its own instance (multi-org inside one install, no billing). |
| Backend / control plane | Laravel 13, PHP 8.5, Postgres 16, Redis, Horizon, Reverb |
| Frontend | Inertia 3 + React 19 + TypeScript + Tailwind |
| Capture | Node 24 + Playwright 1.64, one pinned Docker image per browser, x86_64 only |
| Diff | `odiff-bin` (native, fast) with a SHA-256 hash short-circuit. `pixelmatch` as a pure-JS fallback. |
| Storage | Any S3-compatible service (AWS S3 / MinIO / R2), content-addressed |
| Reference deployment | AWS: ECS Fargate (web, horizon, reverb, capture), RDS, ElastiCache, S3, via Terraform |
| Single-host deployment | `docker compose` (with MinIO) |
| Git provider at launch | GitHub, via a GitHub App created per install with the App Manifest flow |
| Storybook support | Storybook 8.x–10.x (`index.json` v5) |

> **Local dev environment (Apple Silicon, no Docker):** use Apple's `container` CLI (v1.1.0, installed; start it with `container system start`).
> - The capture image is built and run with `container build/run --arch amd64 --rosetta`, so local captures use the same linux/amd64 image as production. Rosetta translation may still differ from native x86 in edge cases, so local captures are for development only and are **never** shared baselines. Real baselines come only from native x86_64 workers (CI / AWS).
> - `container` has no compose, so local services run like this: Laravel natively (PHP 8.5 + Composer are installed); Postgres, Redis and MinIO as individual `container run` services started by `scripts/dev-up.sh` (with `scripts/dev-down.sh` to stop them); the Vite dev server natively.
> - The OCI images and the Dockerfiles stay standard, so end users can still use Docker and `docker compose` (§11). CI (GitHub Actions, ubuntu x86_64) uses Docker.
> - **Fallback:** if `container` is unreliable for builds (e.g. multi-stage or network issues), install OrbStack/Colima and use the same Dockerfiles.

---

## 1. Repository layout

```
/
├── apps/web/                 Laravel 13 app (API, Inertia UI, GitHub App, jobs)
├── packages/cli/             `npx <name>`: build/upload/poll CLI (TypeScript)
├── packages/capture/         Capture worker (TypeScript, Playwright) + Dockerfiles
│   └── docker/chromium|firefox|webkit/Dockerfile
├── packages/diff/            Diff library shared by the worker (odiff wrapper, regions, masks)
├── packages/storybook-addon/ (Phase 3) Visual Tests panel addon
├── packages/shared/          Shared TS types for the API contract (generated from OpenAPI)
├── fixtures/sample-storybook/ A Storybook covering edge cases (fonts, animation, dates, images, portals)
├── deploy/compose/           docker-compose.yml + .env.example for single host
├── deploy/terraform/aws/     Reference AWS module
├── docs/                     User + operator docs (VitePress or plain MD)
└── PLAN.md
```

Tooling: pnpm workspaces for the JS packages, Composer for `apps/web`. GitHub Actions CI runs Pest, Vitest, Playwright e2e, PHPStan (level 8), ESLint and TypeScript checks.

---

## 2. Core concepts and data model

Names follow common visual-testing terminology.

| Entity | Key fields | Notes |
|---|---|---|
| `organizations` | name, slug | Multi-org within one install |
| `users` | name, email, password / GitHub OAuth | |
| `memberships` | org_id, user_id, role (owner/admin/reviewer/viewer) | |
| `projects` | org_id, name, slug, `project_token` (hashed), github_repo_id, default_branch, settings JSON (diff threshold, browsers, auto-accept rules) | |
| `commits` | project_id, sha, parent_shas[], branch, committed_at, author | Partial commit graph, uploaded by the CLI |
| `builds` | project_id, number, commit_sha, branch, pr_number, status (`uploading/queued/capturing/diffing/pending_review/accepted/denied/passed/failed/errored`), base_build_ids[], storybook_url, capture_image_digest, counts | |
| `stories` | project_id, story_id (`button--primary`), title, name, component_path, importPath, tags | Upserted per build |
| `build_stories` | build_id, story_id, parameters JSON (resolved `parameters.tintype`) | |
| `snapshots` | build_id, story_id, browser, mode (viewport+globals key), image_sha256, width, height, status (`new/unchanged/changed/removed/errored/skipped/inconsistent`), baseline_snapshot_id, diff_image_sha256, diff_ratio, error | One per story × browser × mode |
| `reviews` | snapshot_id, user_id, decision (`accepted/denied`), comment, created_at | Append-only audit log |
| `baselines` | project_id, branch, story_id, browser, mode, snapshot_id, accepted_build_id | Materialized "current accepted" pointer per branch |
| `images` | sha256, width, height, bytes, created_at | Content-addressed. S3 key `images/ab/cd/<sha>.png` |
| `capture_jobs` | build_id, shard_index, story_ids[], browser, status, lease_expires_at, attempts, worker_id | Leased by workers |
| `comments` / `review_threads` | (Phase 2) PR-level UI Review threads | |
| `github_installations` | org_id, installation_id, account | |

### Snapshot identity
`(story_id, browser, mode_key)`, where `mode_key` is a stable hash of `{viewport, globals}`. The default mode key is `default`.

---

## 3. Build lifecycle

1. **CLI `create build`**: the CLI calls `POST /api/v1/builds` with a project token and sends:
   - git info: sha, branch, PR number, committer;
   - recent ancestry: `git rev-list --parents -n 200`;
   - CLI version and a Storybook version hint.

   The server responds with the build id plus presigned upload URLs (or a single tarball URL).
2. **Upload**: the CLI runs `storybook build`, unless `--storybook-build-dir` is given. It uploads the static build to `s3://…/storybooks/<build_id>/` and calls `POST /builds/{id}/uploaded`.
3. **Index**: a Laravel job fetches `index.json` and filters to `type: story`.
   - Story parameters (`tintype: { disableSnapshot, viewports, modes, delay, diffThreshold, … }`) are not in `index.json`. They are resolved at capture time inside the browser through the preview API. The worker reports them back.
   - The job then creates `capture_jobs` shards of about 50 stories × browser.
4. **Capture**: workers lease shards and load the hosted Storybook iframe (`iframe.html?id=<id>&viewMode=story`). For each story and each mode they:
   - stabilize the page;
   - take the screenshot and hash it;
   - upload the image if its hash is new;
   - diff it against the baseline image the server supplied with the lease;
   - post the results.
5. **Baselines**: these are resolved *before* the shards are leased (see §4). Each leased story arrives with its baseline image sha.
6. **Finalize**: once all shards are done, the server aggregates the counts. It sets the build to `passed` (no changes), `pending_review` (changes or new stories) or `errored`, updates the GitHub check run, and broadcasts over Reverb.
7. **Review**: reviewers accept or deny snapshots. When every changed or new snapshot has been accepted, the build becomes `accepted` and the check turns green. A single denial makes the build `denied` and the check red.
8. **CLI exit codes**: 0 for pass or accepted, 1 for changes awaiting review (unless `--exit-zero-on-changes`), 2 for build errors, 3 for config or auth errors. `--exit-once-uploaded` skips waiting.

---

## 4. Baseline resolution (git-aware), the hardest algorithm

The rule: **a story's baseline is the most recent accepted snapshot of that story on the nearest ancestor commit(s) that has a build.**

Implementation:
1. Store the commit graph from the CLI (`commits.parent_shas`). The GitHub App backfills any missing ancestry through the API.
2. For a new build at commit C, walk C's ancestors breadth-first, limited to N = 1000 commits. Collect the *ancestor builds*: builds on the nearest ancestors that are not themselves ancestors of another collected build.
3. For each `(story, browser, mode)`, take the newest snapshot across the ancestor builds that is accepted, or that was unchanged from an accepted one. That snapshot is the baseline.
4. **Merges:** when there are multiple ancestor builds, prefer the one on the current branch. Otherwise use the newest by commit date.
5. **Squash and rebase merges:** if no ancestor build exists because history was rewritten, fall back to:
   - the latest accepted build on the PR's head branch, when the GitHub API maps the merge commit to that PR;
   - otherwise, the latest accepted build on the default branch.
6. **Accepting on a branch** only affects that branch's lineage. Merging carries the accepted baselines forward because they are ancestors. This is also why `baselines` is just a cache: the source of truth is the snapshots plus reviews.
7. **Capture image version guard:** a baseline is valid only if the major version of `capture_image_digest` matches. On a mismatch, the snapshot is marked `changed` and annotated "capture environment changed". A project admin can run "Rebaseline" to auto-accept a fresh build.

The algorithm gets extensive Pest tests with synthetic commit graphs: linear, branch, merge, squash, rebase, force-push, and orphan.

---

## 5. Capture determinism

The capture image (`packages/capture/docker/chromium/Dockerfile`) is based on `mcr.microsoft.com/playwright:v1.64.1-noble` pinned **by digest**.

**Image**
- Bundled fonts: Noto, Noto Color Emoji, Liberation, DejaVu, Inter, Roboto. Use `fontconfig` with antialiasing and hinting set explicitly, and no other system fonts.
- Environment: `TZ=UTC` and `LANG=en_US.UTF-8`. `--lang` is set per project.

**Chromium launch flags**
- `--disable-gpu`, `--use-gl=swiftshader` (or `--use-angle=swiftshader`), `--font-render-hinting=none`
- `--disable-lcd-text`, `--force-color-profile=srgb`, `--hide-scrollbars`
- `--disable-skia-runtime-opts`, `--disable-partial-raster`

**Browser context**
- Fixed `deviceScaleFactor` (default 1, configurable per project).
- `reducedMotion: 'reduce'`, with `colorScheme` taken from the mode.

**Before navigation**
- Install Playwright's clock with a fixed time (default `2024-01-01T00:00:00Z`, overridable per story), and seed `Math.random` with a mulberry32 PRNG.
- Inject CSS: `*, *::before, *::after { animation-duration: 0s !important; animation-delay: 0s !important; transition: none !important; caret-color: transparent !important; }`. A `pauseAnimationAtEnd` option jumps animations to their end state instead of zeroing them.

**Waiting for stability**
- Wait for the Storybook `storyRendered` event, or for `play` to finish, through the preview channel.
- Then wait for `document.fonts.ready`, all `<img>` elements to be `complete` and decoded, network idle (at most 500 ms, then a timeout), and the story's `delay` parameter.
- Then wait for 2 `requestAnimationFrame` ticks.

**Network**
- Block external requests unless the host is on the project's allow-list. Allowed requests are cached per build, so a retake uses the same bytes.

**Screenshot**
- Clip to the `#storybook-root` bounding box. A `layout: fullscreen` story captures the viewport.
- Use `animations: 'disabled'` and `caret: 'hide'`.

**Consistency retake ("inconsistent snapshot")**
- If a capture differs from its baseline, capture again (up to 2 more times).
- If the retakes differ from each other, mark the snapshot `inconsistent`: it shows a warning and does not count as a change.

The image is published as `ghcr.io/<org>/capture-chromium:<semver>`. Its digest is recorded on every build.

Firefox and WebKit images follow the same pattern in Phase 2.

---

## 6. Diffing

1. If `sha256(new) == sha256(baseline)`, the result is `unchanged` and no pixel work is done.
2. If the dimensions differ, the result is `changed`. Both images are padded to the larger canvas so they can be shown together.
3. Otherwise run `odiff` with `threshold` (the project or story `diffThreshold`, default 0.063) and `antialiasing: true`. Ignore regions are applied first:
   - masked out using selectors from story params, `tintype.ignoreSelectors`, and elements with the `.tintype-ignore` class or `data-tintype="ignore"`;
   - the worker resolves them to rects at capture time.
4. Outputs: diff ratio, a diff overlay PNG (changed pixels highlighted), and the bounding boxes of changed regions, which the UI uses for "spotlight".

---

## 7. Web app (Inertia + React)

**Pages**
- Auth: email/password plus "Sign in with GitHub".
- First-run setup wizard: create the admin, configure S3, then create the GitHub App via the manifest flow.
- Org dashboard listing projects.
- Project page:
  - the builds list, filterable by branch, status and PR;
  - the component library, a story tree with the latest accepted snapshot per story, linked to the hosted Storybook;
  - settings: token, browsers, viewports, threshold, allow-listed hosts, auto-accept on the default branch, and the GitHub repo link.
- Build page: header (commit, branch, PR, status, baseline builds) and a grouped list (Changed / New / Errors / Inconsistent / Unchanged / Removed).
- **Snapshot review view**, the core UX:
  - views: baseline vs. new side by side, an overlay with an opacity or swipe slider, a diff highlight toggle, and spotlight (dim the unchanged areas);
  - zoom and pan with both images synced;
  - a mode/browser switcher and a link to "Open story in hosted Storybook";
  - keyboard flow: `j/k` to move, `a` to accept, `d` to deny, `u` to undo;
  - batch actions: accept all in component or accept all in build;
  - review history for the snapshot.
- (Phase 2) PR-level "UI Review" page: a code-review-style list of changed components, threads, assignees, and an approve/request-changes state that feeds a second GitHub check.

**Realtime:** Reverb channel `builds.{id}` streams capture progress and snapshot results as they land.

**Policy:** reviewers can accept or deny, viewers have read-only access, and only admins can change project settings or rebaseline.

---

## 8. API

REST, versioned `/api/v1`, documented with OpenAPI (Scramble).

- **CLI:** authenticated by a project token. Endpoints for creating builds, uploading, and reading status.
- **Workers:** a separate worker token. Endpoints:
  - `POST /internal/v1/jobs/lease`, which returns the shard, the Storybook URL, and presigned GET/PUT URLs;
  - `POST /internal/v1/jobs/{id}/heartbeat`;
  - `POST /internal/v1/jobs/{id}/results`.

  Leases expire after 2 minutes without a heartbeat, and the job is then re-queued (max 3 attempts).
- **UI:** session auth through Inertia. A small JSON API with Sanctum backs the future addon.

---

## 9. GitHub integration

- The setup wizard uses the **App Manifest flow** to create a GitHub App owned by the installer.
- Permissions: checks (rw), pull requests (r), contents (r), metadata (r). Webhooks: `installation`, `pull_request`, `push`, `check_run`.
- Each build creates a check run named `Visual Tests` that links to the build page. It is updated on finalize and on review decisions.
- The PR number is detected from the CLI environment (`GITHUB_REF`, the `GITHUB_EVENT_PATH` payload) and from the API.
- Commit ancestry backfill uses the compare/commits API when the CLI's history is shallow. This matters because `actions/checkout` defaults to depth 1, so the docs will recommend `fetch-depth: 0`.
- (Phase 2) A second check, `UI Review`, and PR comments summarizing the changes.

---

## 10. CLI (`packages/cli`)

- Command: `npx <name> --project-token=<t> [--build-script-name build-storybook] [--storybook-build-dir dir] [--branch-name] [--exit-zero-on-changes] [--exit-once-uploaded] [--auto-accept-changes [branch-glob]] [--only-story-names glob] [--skip [branch-glob]] [--external glob] [--debug]`.
- Config file: `tintype.config.json` (same keys as the flags).
- Git detection: sha, branch (handles detached HEAD in CI), PR number, and `git rev-list` ancestry. It warns on shallow clones.
- Upload: concurrent presigned PUTs with retries, plus a progress bar. A "skip unchanged files" optimization uploads only files whose hash changed since the previous build; this comes later.
- Output: build URL, counts, and the exit codes from §3. It writes a GitHub Actions job summary when `GITHUB_STEP_SUMMARY` is set.
- Published to npm. A companion GitHub Action wraps it.

---

## 11. Deployment

**docker compose** (`deploy/compose`) runs these services: `web` (FrankenPHP/Octane), `horizon`, `reverb`, `scheduler`, `postgres`, `redis`, `minio`, `capture` (scalable with `--scale capture=N`). One command plus `.env` gets a working install.

**Terraform AWS module** (`deploy/terraform/aws`):
- network: VPC with public and private subnets, and an ALB with TLS through ACM;
- compute: ECS Fargate services for web, horizon and reverb, plus capture workers on Fargate `X86_64` that autoscale on queue depth;
- data: RDS Postgres, ElastiCache Redis, and S3 with lifecycle rules for orphaned images;
- secrets: Secrets Manager;
- monitoring: CloudWatch.

**Storage GC:** a scheduled job deletes `images` rows (and their S3 objects) that no snapshot references. Retention is configurable per project, e.g. keep 90 days of non-baseline builds.

**Health:** `/up`, the Horizon dashboard (admin-only), and worker heartbeats shown on an admin "Fleet" page.

---

## 12. Testing strategy

- **Pest (PHP):** baseline resolution (graph fixtures), the build state machine, policy/authorization, API contracts, and the GitHub webhook handlers using recorded payloads.
- **Vitest (TS):** CLI git detection and arg parsing, the diff library (golden PNG fixtures), and capture param resolution.
- **Determinism suite (CI, x86_64 runner):** capture `fixtures/sample-storybook` 10× in the image and assert 0 differing pixels across all runs. This gates every capture-image release.
- **End-to-end:** a compose stack in CI. Run the CLI against the fixture Storybook, assert the build passes, modify a story, assert `changed`, accept it through Playwright driving the review UI, then assert the next build passes.
- **Static checks:** PHPStan level 8, Pint, ESLint, and `tsc --noEmit`.

---

## 13. Milestones and acceptance criteria

Each milestone ends with green CI and a short demo note in `docs/progress.md`.

### Phase 0: Foundations and determinism spike
- [ ] `git init`, monorepo skeleton (§1), pnpm workspace, license (MIT unless changed), README, CI skeleton
- [ ] Verify Apple `container` works: `container system start`, then build and run an amd64 Playwright image with `--rosetta`, and record any quirks in `docs/dev-environment.md`
- [ ] `scripts/dev-up.sh` / `dev-down.sh` (Postgres, Redis, MinIO via `container run`)
- [ ] `fixtures/sample-storybook` (Storybook 10, React + Vite) with these edge-case stories: web fonts, CSS animation, transition, `Date.now()` rendering, `Math.random`, lazy image, SVG, emoji, portal/modal, long scroll, `play` function
- [ ] Capture Dockerfile for Chromium, pinned by digest, with fonts and flags (§5)
- [ ] `packages/capture` standalone mode: `capture --storybook <dir|url> --out <dir>` captures every story
- [ ] Determinism script: 20 runs, then pixel-compare all of them
- [ ] Determinism CI job on GitHub Actions `ubuntu-latest` (native x86_64, Docker) running the same script
- **Accept:** 20/20 identical captures for every fixture story locally (amd64 under Rosetta) **and** in CI (native x86_64), plus a note on whether the Rosetta and native captures match each other; findings written to `docs/determinism.md`

### Phase 1: MVP (end-to-end visual testing)
- [ ] Laravel 13 app: Inertia/React/TS/Tailwind scaffold, auth, orgs, projects, project tokens
- [ ] Migrations and models for §2 (except the Phase 2 tables)
- [ ] S3 abstraction (MinIO locally), content-addressed image storage
- [ ] Build API plus presigned upload, index job, sharding, worker lease/heartbeat/results API
- [ ] Capture worker service mode (lease loop), hash, upload, odiff diff, retake/inconsistent logic
- [ ] Baselines: full ancestor-walk algorithm (§4), with the default-branch fallback
- [ ] Finalize job, build state machine, Reverb progress
- [ ] Review UI: build page, snapshot view (side by side, overlay, diff), accept/deny, batch accept, keyboard flow
- [ ] CLI: create, build Storybook, upload, poll, exit codes, git detection
- [ ] GitHub App via the manifest flow, check runs on build and on review
- [ ] docker compose deployment
- [ ] E2E test (§12) passing in CI
- **Accept:** on a fresh machine, `docker compose up`, the setup wizard, and then the CLI run in a sample repo's GitHub Action produce a check run. A visual change causes a red/pending check, accepting it in the UI turns the check green, and the next build on `main` passes with the new baseline.

### Phase 2: Full feature set
- [ ] Story params: `disableSnapshot`, `delay`, `diffThreshold`, `diffIncludeAntiAliasing`, `pauseAnimationAtEnd`, `viewports` (legacy), and **modes** (viewport + globals such as theme or locale)
- [ ] Ignore regions (selectors, class, data attribute)
- [ ] Firefox and WebKit capture images; per-project browser selection
- [ ] Squash/rebase merge baseline handling through the GitHub API
- [ ] Removed-story detection, and "unchanged since last accepted" propagation
- [ ] Component library page and hosted Storybook per build (with a permalink to the latest on each branch)
- [ ] UI Review (PR page, threads, assignees, second check run)
- [ ] Auto-accept rules (`--auto-accept-changes`, default-branch policy), rebaseline after a capture image upgrade
- [ ] Storage GC and retention settings
- [ ] Terraform AWS module plus an operator guide
- [ ] Docs site: quickstart, CI recipes, configuration reference
- **Accept:** a real-world Storybook (≥300 stories, 2 modes, 2 browsers) runs end to end on AWS in under 5 minutes with the default autoscaling, and with 0 inconsistent snapshots over 10 consecutive no-change builds.

### Phase 3: Beyond parity basics
- [ ] TurboSnap: the CLI reads Vite/webpack stats, maps changed files (`git diff` against the baseline commit) to affected stories, and captures only those. The rest are marked `unchanged`, carried over.
- [ ] Storybook Visual Tests addon: run a build from Storybook and review the diffs inline
- [ ] Interaction test results (`play` failures reported as errors with a trace)
- [ ] Accessibility checks (axe-core per story, with violations tracked like diffs)
- [ ] GitLab and Bitbucket providers (behind a provider interface introduced in Phase 1)
- [ ] SSO (OIDC/SAML through Socialite providers), audit log UI
- [ ] Slack/webhook notifications

---

## 14. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Non-deterministic rendering creates false positives | Phase 0 gate, retake/inconsistent logic, pinned digests, x86-only workers, capture-env version guard |
| Baseline logic edge cases (squash merges, force pushes) | Graph-fixture test suite, GitHub API fallback, a visible "baseline build(s)" explanation on every build page |
| Arbitrary user JS in the capture workers | Workers have no network route to the DB/Redis, use a scoped short-lived token, run as non-root with seccomp, and get a fresh browser context per story |
| Storage growth | Content addressing, dedupe, GC job, S3 lifecycle |
| ARM dev machines produce different pixels | Document clearly; refuse to accept baselines from non-x86 workers (workers report their arch) |
| Storybook internals change between majors | Use only public surfaces (`index.json`, the preview channel events, `__STORYBOOK_PREVIEW__`) with a per-major adapter and a CI matrix over SB 8/9/10 |

---

## 15. Open questions (non-blocking; defaults in parentheses)

- Project name and npm package name (decide before the first npm publish)
- License (MIT)
- Should the hosted Storybook require login, or use unguessable public URLs (login required, with an optional per-project public setting)?
- Default capture device scale factor (1×, with 2× as a mode option)
