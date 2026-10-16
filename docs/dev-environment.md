# Local development environment

These instructions target macOS on Apple Silicon without Docker. Docker works
too: every image is a standard OCI image.

## Prerequisites

- PHP 8.4+ with `pdo_sqlite`/`pdo_pgsql`, `zip`, `pcntl`; Composer
- Node 22+ with corepack (`corepack enable` gives pnpm 12)
- Apple `container` CLI 1.1+ (`container system start`)

## Apple `container` notes

- Run the amd64 capture image with `--arch amd64 --rosetta`. Without
  `--rosetta`, amd64 images fall back to much slower emulation.
- There is no compose. `scripts/dev-up.sh` starts Postgres, Redis and MinIO as
  individual containers; `scripts/dev-down.sh` stops them.
- `container build --arch amd64 -f packages/capture/docker/Dockerfile .`
  builds the capture image. `apt-get --snapshot` works inside the build.
- Volumes: `-v host:container[:ro]`.
- Containers reach the host through the address shown by `container network ls`
  gateway (`192.168.64.1` on the default network), not `host.docker.internal`.

## Fast inner loop

Run everything natively: SQLite, the `database` queue, local disk, and the
capture worker on native arm64 Chromium with `TINTYPE_ALLOW_ANY_ARCH=true`:

```
cd apps/web && composer run dev        # web + queue + vite
pnpm --filter @tintype/capture build
TINTYPE_SERVER=http://localhost:8000 TINTYPE_WORKER_TOKEN=dev node packages/capture/dist/cli.js worker
```

## Known issue: MinIO image pull

Under `container` 1.1.0, pulling `quay.io/minio/minio` hung on this machine,
while Postgres and Redis from Docker Hub pulled fine. MinIO is optional for
local development: leave `TINTYPE_DISK_DRIVER=local` (the default) and images
and Storybooks are stored under `storage/app/tintype`. The S3 code path is
exercised in docker compose and on AWS.
