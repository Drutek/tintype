# CI recipes

Tintype needs the **git history** to find baselines. Always fetch it in full.

## GitHub Actions

```yaml
name: Visual tests
on: [push, pull_request]
jobs:
  tintype:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { fetch-depth: 0 }
      - uses: actions/setup-node@v4
        with: { node-version: 22 }
      - run: npm ci
      - run: npx @tintype/cli --exit-zero-on-changes --auto-accept-changes main
        env:
          TINTYPE_SERVER: https://tintype.example.com
          TINTYPE_PROJECT_TOKEN: ${{ secrets.TINTYPE_PROJECT_TOKEN }}
```

On `pull_request` events the CLI reports the PR **head** commit and branch, not
GitHub's synthetic merge commit, and links the build to the PR.

Install the GitHub App to get a check run. Make it a required check, so merging
waits until changes are accepted.

## GitLab CI

```yaml
visual-tests:
  image: node:22
  variables:
    GIT_DEPTH: 0
  script:
    - npm ci
    - npx @tintype/cli --exit-zero-on-changes
```

Set `TINTYPE_SERVER` and `TINTYPE_PROJECT_TOKEN` as CI/CD variables. For a
commit status on the merge request, open the project's settings:
1. set **Status reporting** to GitLab;
2. enter the project path;
3. add an access token with the `api` scope.

## Bitbucket Pipelines

```yaml
pipelines:
  default:
    - step:
        image: node:22
        clone: { depth: full }
        script:
          - npm ci
          - npx @tintype/cli --exit-zero-on-changes
```

For build statuses, set **Status reporting** to Bitbucket and add a repository
access token.

## Speeding builds up

- `--only-changed` (TurboSnap) captures only the stories affected by the files
  that changed since the baseline commit. The rest are carried over unchanged.
  It needs the Storybook stats file, which the CLI requests automatically when
  it builds Storybook.
  - A change to `package.json`, a lockfile, or anything in `.storybook/` forces
    a full build.
  - So does a change to a file matching `--externals`.
- `--only-story-names 'Components/Button/*'` captures a subset.
- Add more capture workers (`docker compose up -d --scale capture=8`) or raise
  `capture_max` on AWS.
