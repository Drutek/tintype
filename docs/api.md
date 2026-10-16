# HTTP API

All endpoints return JSON. Validation errors return `422` with
`{message, errors}`.

## CLI API: `Authorization: Bearer <project token>`

| Method & path | Body / query | Response |
|---|---|---|
| `POST /api/v1/builds` | `{commit, branch, prNumber?, commits: [{sha, parents[], message?, author?, committedAt?}], ci?, cliVersion?, options?: {autoAcceptChanges?, onlyStoryNames?, turboSnap?: {affectedImportPaths[]}}}` | `201 {id, number, url, upload: {method, url, headers, authenticated?}, statusUrl}` |
| `PUT <upload.url>` | zip of the static Storybook | `200` (S3 presigned or direct) |
| `POST /api/v1/builds/{id}/uploaded` | | `202` |
| `GET /api/v1/builds/{id}` | | `{status, exitCode, url, counts, progress, baseline, changes[]}` |
| `POST /api/v1/builds/{id}/cancel` | | build summary |
| `POST /api/v1/baseline-commits` | `{commit, branch, commits}` | `{strategy, commits[]}`: the commits this build would compare against (TurboSnap) |
| `GET /api/v1/stories/{storyId}/status?branch=` | | latest snapshots of a story, with signed image URLs |

## Worker API: `Authorization: Bearer <TINTYPE_WORKER_TOKEN>`, `X-Tintype-Arch: <arch>`

| Method & path | Body | Response |
|---|---|---|
| `POST /api/internal/v1/jobs/lease` | `{workerId, browsers[], arch?, image?}` | `204`, or `{job: {id, buildId, browser, storybookUrl, stories: [{storyId, baselines: {modeKey: {snapshotId, sha, url, ignoreRegions}}}], settings, leaseSeconds}}` |
| `POST /api/internal/v1/jobs/{id}/heartbeat` | `{workerId}` | `200`, or `409` if the lease was lost |
| `POST /api/internal/v1/jobs/{id}/results` | `{workerId, complete, error?, results: [{storyId, status, modeKey, modeName, viewport, imageSha, width, height, baselineSnapshotId, comparison, diffSha, diffRatio, diffRegions, ignoreRegions, params, a11y, error, durationMs}]}` | `200` / `409` |
| `POST /api/internal/v1/images/missing` | `{shas[]}` | `{missing[]}` |
| `PUT /api/internal/v1/images/{sha}` | PNG bytes (hash-verified) | `201` |
| `GET /api/internal/v1/images/{sha}` | | PNG |

## Webhooks

**Outgoing** (project setting): `POST {event: "build.status", project, build}`,
with `X-Tintype-Signature: sha256=HMAC-SHA256(body, secret)`. The signing secret
is shown in project settings once a webhook URL is set. Webhooks are delivered
at most once per status.

**Incoming**: `POST /github/webhook`, for the GitHub App's `installation`,
`pull_request` and `check_run` events.
