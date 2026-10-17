# Configuration reference

## CLI (`npx @drutek/tintype-cli`)

| Flag | Env | Description |
|---|---|---|
| `--server <url>` | `TINTYPE_SERVER` | Tintype server URL |
| `-t, --project-token <t>` | `TINTYPE_PROJECT_TOKEN` | Project token |
| `-b, --build-script-name <name>` | | package.json script that builds Storybook (default `build-storybook`) |
| `-d, --storybook-build-dir <dir>` | | Use an existing static build instead of building |
| `--branch-name <name>` | `TINTYPE_BRANCH` | Override the detected branch |
| `--exit-zero-on-changes [branch]` | | Exit 0 even when there are changes to review |
| `--exit-once-uploaded [branch]` | | Don't wait for results |
| `--auto-accept-changes [branch]` | | Accept all changes (e.g. `main`) |
| `--only-story-names <glob>` | | Capture only matching story ids or `Title/Name` (repeatable) |
| `--only-changed [branch]` | | TurboSnap: capture only affected stories |
| `--externals <glob>` | | Non-bundled files whose change forces a full build (repeatable) |
| `--untraced <glob>` | | Changed files to ignore for TurboSnap (repeatable) |
| `--skip [branch]` | | Skip the build (exit 0) |
| `--timeout-minutes <n>` | | Max wait for results (default 30) |
| `--dry-run` | | Build and analyse, but don't upload |
| `--config-file <path>` | | Default `tintype.config.json` |

Where a flag takes `[branch]`, you can pass a glob or a comma-separated list
(e.g. `main,release/*`). The flag then applies only on matching branches.

`tintype.config.json` accepts the same options in camelCase, e.g.
`{ "onlyChanged": true, "externals": ["public/**"] }`.

### Exit codes

| Code | Meaning |
|---|---|
| 0 | Passed, accepted, or skipped |
| 1 | Changes are waiting for review, or were denied |
| 2 | Stories errored, or the build failed |
| 3 | Configuration or authentication error |

## Story parameters

Set these under `parameters.tintype`, in a story, a component meta, or
`preview.ts`:

```ts
export const Primary = {
  parameters: {
    tintype: {
      modes: {
        mobile: { viewport: { width: 375, height: 667 } },
        'dark desktop': { viewport: 1280, theme: 'dark' }, // other keys become globals
      },
      delay: 300,
      diffThreshold: 0.1,
      pauseAnimationAtEnd: true,
      ignoreSelectors: ['.timestamp'],
    },
  },
};
```

| Parameter | Description |
|---|---|
| `disableSnapshot` | Don't capture this story |
| `modes` | Capture once per mode. `viewport` is a width, `{width,height}`, or a named viewport from `parameters.viewport`; other keys are Storybook globals |
| `viewports` | Shorthand: list of widths |
| `delay` | Extra ms to wait before capturing (max 15000) |
| `diffThreshold` | Per-pixel colour threshold 0–1 (project default 0.063) |
| `diffIncludeAntiAliasing` | Count anti-aliased pixels as changes |
| `pauseAnimationAtEnd` | Jump animations to their end state (default: paused at frame 0) |
| `ignoreSelectors` | CSS selectors excluded from comparison |
| `cropToViewport` | Capture the viewport instead of the story's bounding box |
| `fixedTime` | ISO date for the frozen clock (default `2024-01-01T00:00:00Z`) |
| `a11y` | `false` turns accessibility checks off for this story |

Elements with the class `tintype-ignore`, or with `data-tintype="ignore"`, are
always excluded from comparison.

## Project settings (UI)

- **Comparison:**
  - which browsers to capture (Chromium, Firefox, WebKit);
  - diff threshold and anti-aliasing;
  - default viewport;
  - device scale factor;
  - the frozen date.
- **Network:** external hosts allowed to load during capture. Everything else
  is blocked.
- **Automation:**
  - auto-accept on the default branch;
  - **Rebaseline** (accept the next default-branch build wholesale, after a
    capture-image upgrade);
  - build retention.
- **Visibility and checks:**
  - whether the hosted Storybook is public;
  - the UI Review check;
  - accessibility checks.
- **Integrations:**
  - status reporting (GitHub, GitLab or Bitbucket);
  - an outgoing webhook, signed with `X-Tintype-Signature` (HMAC-SHA256);
  - a Slack incoming webhook.

## Server environment

| Variable | Default | Description |
|---|---|---|
| `APP_URL` | | Public URL |
| `APP_KEY` | | Encryption key (`docker compose run --rm web key`) |
| `TINTYPE_WORKER_TOKEN` | | Shared secret for capture workers |
| `TINTYPE_INTERNAL_URL` | `APP_URL` | URL workers use to reach the server (e.g. `http://web:8080`) |
| `TINTYPE_DISK_DRIVER` | `local` | `local` or `s3` (`TINTYPE_S3_BUCKET`, `_KEY`, `_SECRET`, `_REGION`, `_ENDPOINT`, `_PATH_STYLE`) |
| `TINTYPE_SHARD_SIZE` | 25 | Stories per capture job |
| `TINTYPE_ALLOW_ANY_ARCH` | false | Accept results from non-x86_64 workers (development only) |
| `TINTYPE_OPEN_REGISTRATION` | false | Allow self sign-up |
| `TINTYPE_CAPTURE_IMAGE_VERSION` | 1 | Informational major version |
| `TINTYPE_OIDC_ISSUER`, `_CLIENT_ID`, `_CLIENT_SECRET`, `_LABEL`, `_AUTO_JOIN_ORG` | | OpenID Connect sign-in |
| `TINTYPE_REVERB_PUBLIC_HOST`, `_PORT`, `_SCHEME` | | Where browsers connect for live updates |
| `TINTYPE_CLOUDWATCH_NAMESPACE` | | Publish queue depth for autoscaling |
| `GITHUB_API_URL`, `GITHUB_WEB_URL` | github.com | For GitHub Enterprise Server |
