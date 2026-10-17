# Throughput benchmark

`scripts/bench.sh` runs N no-change builds of `fixtures/benchmark-storybook`
against a local server and worker fleet. The fixture is generated: 40
components × 8 stories = 320 stories, each with 2 modes (mobile, desktop).

```bash
pnpm --filter @tintype/benchmark-storybook build-storybook
BENCH_BUILDS=10 BENCH_WORKERS=3 BENCH_CONCURRENCY=4 BENCH_BROWSERS=chromium,firefox scripts/bench.sh
```

## Results (2026-10-17)

- **Target (Phase 2):** ≥300 stories, 2 modes, 2 browsers, under 5 minutes per
  build, with 0 inconsistent snapshots over 10 consecutive no-change builds.
- **Machine:** one Apple Silicon laptop (native arm64 browsers, development
  only); 3 Chromium + 3 Firefox workers, 4 concurrent stories each.

| Build | Time | Snapshots | Changed | Inconsistent | Errors |
|---|---|---|---|---|---|
| 1 (first, auto-accepted) | 81s | 1280 new | – | 0 | 0 |
| 2–10 (no changes) | 78–81s each | 1280 unchanged | 0 | 0 | 0 |

That's roughly 950 snapshots per minute, about 3.7× under the 5-minute target,
on a single laptop. A cloud fleet scales horizontally from there: capture
autoscaling on AWS is driven by queued shards per worker.

## What the benchmark found and fixed

The first attempt took **1,307s per build** and recorded only 1,093 of the
1,280 snapshots. Two problems:

1. **Dropped results (correctness).** The worker queued results with
   `pending.push(await evaluate(...))`. JavaScript binds `pending` before the
   `await`, so when another story flushed the queue in the meantime, the result
   went into an array that had already been sent. Fixed in `worker.ts`. The
   server now also turns any story a shard never reported into an error
   snapshot, so a lost result can't pass silently.
2. **The server as a bottleneck (throughput).** Every Storybook asset request
   from every capture page booted Laravel. Workers now download each build's
   Storybook archive once (`GET /api/internal/v1/builds/{id}/storybook.zip`) and
   serve it from localhost. Story parameters come from one `preview.extract()`
   call per shard instead of a probe render per story. The screenshots are
   byte-identical before and after (160/160 compared).

## Still to do on AWS

Run the same script's workload against `deploy/terraform/aws`, with native
x86_64 Fargate workers, to confirm the cloud numbers.
