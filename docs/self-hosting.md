# Self-hosting

## Components

| Service | Image | Scales |
|---|---|---|
| web | `tintype-web` (`web` role) | horizontally |
| horizon (queues) | `tintype-web` (`horizon`) | 1+ |
| scheduler | `tintype-web` (`scheduler`) | exactly 1 |
| reverb (websockets) | `tintype-web` (`reverb`) | 1+ (sticky) |
| capture | `tintype-capture-<browser>` | horizontally; this is where the CPU goes |
| Postgres 16, Redis 7, S3-compatible storage | | |

## docker compose (single host)

See `deploy/compose/`. Scale capture with
`docker compose up -d --scale capture=N`.

Capture workers sit on their own network, together with `web` only. The
browser runs untrusted Storybook code, so workers can't reach Postgres, Redis
or MinIO.

## Coolify

`deploy/coolify/docker-compose.yml` is a single-domain variant for Coolify's
docker compose build pack.
- It stores images on a volume instead of MinIO, and pages poll for updates
  instead of using Reverb.
- **Set up:**
  1. Create a resource from this repo with that compose path.
  2. Give the `web` service a domain on port 8080, for example
     `https://tintype.example.com:8080`.
  3. Set `APP_URL`, `APP_KEY` (`base64:` followed by 32 random bytes),
     `TINTYPE_WORKER_TOKEN` and `POSTGRES_PASSWORD`.
  4. Deploy.
- The first account registered becomes the admin, so register before you share
  the URL.
- **Behind Cloudflare's proxy:** Cloudflare's free plan limits uploads to 100MB,
  which caps the size of an uploaded Storybook. In Full (strict) SSL mode,
  Cloudflare returns 526 until Coolify's proxy has a Let's Encrypt
  certificate. If DNS was added after the first deploy, restart the resource.

## AWS

See [`deploy/terraform/aws`](../deploy/terraform/aws/README.md):
- ECS Fargate (x86_64) with an ALB, RDS, ElastiCache and S3;
- capture workers autoscale on the number of queued shards per worker.

## Capture images and upgrades

- Capture images pin their base image by digest, and install fonts from a dated
  Ubuntu snapshot, so a given tag always renders the same pixels.
- Every build records the image it was captured with. When a baseline came
  from a different **major** version, differences are flagged as "capture
  environment changed".
- **Upgrade procedure:**
  1. Deploy the new image.
  2. In each project's settings, click **Rebaseline**.
  3. Push to the default branch. That build is accepted wholesale.
- Workers on Apple Silicon or ARM are rejected unless
  `TINTYPE_ALLOW_ANY_ARCH=true`. ARM renders text differently, so baselines
  must come from x86_64.

## Storage and retention

- Images are content-addressed (SHA-256), so identical screenshots are stored
  once.
- `tintype:gc` runs daily. It deletes builds older than the project's retention
  period, but keeps the latest build per branch and any build whose snapshots
  are still somebody's baseline. It then removes unreferenced images.

## Security notes

- Project tokens are stored hashed. Worker tokens are a single shared secret,
  so rotate it by redeploying.
- Hosted Storybooks are served under unguessable per-build paths. Unless a
  project is marked public, the entry link requires login.
- GitHub App credentials and SCM tokens are encrypted with `APP_KEY`.
- The GitHub webhook verifies `X-Hub-Signature-256`. Outgoing webhooks are
  signed (`X-Tintype-Signature`).
- An audit log records token rotations, membership, settings, GitHub and
  bulk-review actions. Admins see it under **Admin**; org owners see their
  organization's entries.
