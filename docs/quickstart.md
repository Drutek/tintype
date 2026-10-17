# Quickstart

## 1. Run a server

```bash
git clone https://github.com/drutek/tintype && cd tintype/deploy/compose
cp .env.example .env            # set APP_URL and the secrets
docker compose run --rm web key # paste the output into APP_KEY in .env
docker compose up -d
```

Open `APP_URL`. The first account you register becomes the instance admin
and owns an organization. After that, sign-up is closed unless
`TINTYPE_OPEN_REGISTRATION=true`. Add teammates from the organization page.

## 2. Create a project

On the organization page, click **New project**. Copy the project token that
is shown once, and add it to your CI secrets as `TINTYPE_PROJECT_TOKEN`.

## 3. Run the CLI

The CLI is published to GitHub Packages. Point the `@drutek` scope at it once
per project:

```bash
echo "@drutek:registry=https://npm.pkg.github.com" >> .npmrc
```

GitHub's npm registry requires a token even for public packages. Use any token
with `read:packages`. In GitHub Actions, the job's `GITHUB_TOKEN` with
`packages: read` works. Set it as `NODE_AUTH_TOKEN`, or add
`//npm.pkg.github.com/:_authToken=${NODE_AUTH_TOKEN}` to `.npmrc`.

From your Storybook project:

```bash
TINTYPE_SERVER=https://tintype.example.com \
TINTYPE_PROJECT_TOKEN=tt_xxxxxxxx_… \
npx @drutek/tintype-cli
```

The CLI:
1. builds Storybook with your `build-storybook` script;
2. uploads it;
3. waits while capture workers screenshot every story;
4. prints a summary and exits with a [status code](configuration.md#exit-codes).

Your first build is accepted automatically and becomes the baseline. After
that, every build is compared with the baseline from its git ancestry.

## 4. Review

When a build has changes, the CLI exits `1` (use `--exit-zero-on-changes` so
CI stays green) and prints a link. Open it to review:
- step through changes with `j`/`k`;
- accept with `a`, deny with `d`;
- compare with the diff, side-by-side, swipe and spotlight views.

Accepted snapshots become the baseline for later builds on that branch.
Merging carries them forward.

## 5. Hook up GitHub (optional)

As the admin, go to **Admin → GitHub App → Create GitHub App**. Install it on
your repositories, then set the repository (`owner/name`) in the project's
settings. Every build then posts a **Visual Tests** check, and every pull
request gets a **UI Review** check.
