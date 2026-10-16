# How it works

```
CLI ──create build──▶ Laravel API ──▶ Postgres (state), S3 (Storybooks, images)
 │                          ▲   │
 └──upload zip─────────────┘   └── capture_jobs ◀──lease── capture workers (Playwright, x86_64)
                                                            │ load hosted Storybook
                                                            │ screenshot, hash, diff vs baseline, retake
                                                            └──results + images──▶ API
```

1. **Create.** The CLI sends the commit, branch, PR and up to 1000 ancestor
   commits, then uploads the static Storybook as one zip.
2. **Index.** The server unpacks it, reads `index.json`, works out the ancestor
   builds, and splits the stories into capture jobs (25 stories × browser by
   default).
3. **Capture.** Workers lease a job and get, for each story, its baseline
   image hash per mode. For each story a worker:
   - renders it in a fresh browser context, with a frozen clock and a seeded
     `Math.random`;
   - blocks external network requests;
   - waits for `storyFinished` (including `play`), fonts, images and network
     quiet;
   - freezes animations, then takes the screenshot.

   If the screenshot's hash matches the baseline, the snapshot is unchanged.
   Otherwise the worker runs a pixel diff (pixelmatch, ignore regions masked),
   and **retakes** the story twice to tell a real change from flakiness.
   Leases have a heartbeat. If a worker disappears, its shard is re-queued, up
   to 3 attempts.
4. **Finalize.** Once every job is done, the server:
   - carries forward stories that weren't captured (TurboSnap,
     `--only-story-names`);
   - marks removed stories;
   - applies auto-accept rules;
   - computes the build status;
   - updates GitHub/GitLab/Bitbucket, webhooks and Slack;
   - broadcasts over Reverb.
5. **Review.** Every accept or deny is recorded, and the build status and
   checks update immediately.

Code map:
- `apps/web/app/Services/BaselineResolver.php`: ancestor builds and baselines.
- `CaptureService.php`: leasing and results.
- `BuildFinalizer.php`: finalization.
- `packages/capture/src/capture.ts`: rendering and stabilisation.
- `worker.ts`: the lease loop, diffs and retakes.
