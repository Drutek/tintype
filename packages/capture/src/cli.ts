#!/usr/bin/env node
import { Command } from 'commander';
import { captureAll, determinism } from './standalone.js';
import { CaptureWorker } from './worker.js';
import type { BrowserName } from './types.js';

const program = new Command('tintype-capture').description('Deterministic Storybook screenshot capture');

const collect = (v: string, prev: string[] = []) => [...prev, v];

program
  .command('run')
  .description('Capture every story once')
  .requiredOption('-s, --storybook <dirOrUrl>', 'static Storybook directory or URL')
  .option('-o, --out <dir>', 'output directory', 'out')
  .option('-b, --browser <name>', 'chromium | firefox | webkit', 'chromium')
  .option('-c, --concurrency <n>', 'parallel stories', (v) => Number.parseInt(v, 10), 4)
  .option('--only <glob>', 'only capture matching story ids/names (repeatable)', collect)
  .action(async (o) => {
    const { results } = await captureAll({ storybook: o.storybook, out: o.out, browser: o.browser as BrowserName, concurrency: o.concurrency, only: o.only, log: console.log });
    const errors = results.filter((r) => r.status === 'error').length;
    console.log(`Done: ${results.length} snapshots, ${errors} errors → ${o.out}`);
  });

program
  .command('determinism')
  .description('Capture N times and report pixel differences between runs')
  .requiredOption('-s, --storybook <dirOrUrl>', 'static Storybook directory or URL')
  .option('-n, --runs <n>', 'number of runs', (v) => Number.parseInt(v, 10), 20)
  .option('-o, --out <dir>', 'output directory for report + inconsistent pairs', 'out/determinism')
  .option('-b, --browser <name>', 'chromium | firefox | webkit', 'chromium')
  .option('-c, --concurrency <n>', 'parallel stories', (v) => Number.parseInt(v, 10), 4)
  .option('--only <glob>', 'only matching stories (repeatable)', collect)
  .action(async (o) => {
    const report = await determinism({ storybook: o.storybook, runs: o.runs, out: o.out, browser: o.browser as BrowserName, concurrency: o.concurrency, only: o.only, log: console.log });
    console.log(`\n${report.consistent}/${report.snapshots} snapshots identical across ${report.runs} runs (${report.browser} ${report.browserVersion}, ${report.platform}/${report.arch}, ${Math.round(report.durationMs / 1000)}s)`);
    for (const i of report.inconsistent) console.log(`  INCONSISTENT ${i.key}: ${i.distinctHashes} variants, max ${i.maxDiffPixels}px (${(i.maxDiffRatio * 100).toFixed(4)}%)`);
    for (const e of report.errors) console.log(`  ERROR ${e}`);
    if (report.excluded.length) console.log(`  (excluded ${report.excluded.length} render-error snapshots; ${report.maskedOnly.length} differed only inside ignore regions)`);
    process.exitCode = report.inconsistent.length ? 1 : 0;
  });

program
  .command('worker')
  .description('Lease capture jobs from a Tintype server (env: TINTYPE_SERVER, TINTYPE_WORKER_TOKEN)')
  .option('--server <url>', 'server URL', process.env.TINTYPE_SERVER)
  .option('--token <token>', 'worker token', process.env.TINTYPE_WORKER_TOKEN)
  .option('--id <id>', 'worker id', process.env.TINTYPE_WORKER_ID)
  .option('--browsers <list>', 'comma-separated browsers', process.env.TINTYPE_BROWSERS ?? 'chromium')
  .option('-c, --concurrency <n>', 'parallel stories', (v) => Number.parseInt(v, 10), Number.parseInt(process.env.TINTYPE_CONCURRENCY ?? '4', 10))
  .option('--exit-when-idle <polls>', 'exit after N empty polls (0 = run forever)', (v) => Number.parseInt(v, 10), 0)
  .action(async (o) => {
    if (!o.server || !o.token) throw new Error('--server and --token (or TINTYPE_SERVER / TINTYPE_WORKER_TOKEN) are required');
    const worker = new CaptureWorker({
      server: o.server,
      token: o.token,
      workerId: o.id,
      browsers: String(o.browsers).split(',').map((b: string) => b.trim()) as BrowserName[],
      image: process.env.TINTYPE_CAPTURE_IMAGE_TAG ?? process.env.TINTYPE_CAPTURE_IMAGE,
      concurrency: o.concurrency,
      exitWhenIdle: o.exitWhenIdle,
      pollMs: Number.parseInt(process.env.TINTYPE_POLL_MS ?? '2000', 10),
    });
    const stop = () => {
      console.log('stopping after current job…');
      worker.stop();
    };
    process.on('SIGTERM', stop);
    process.on('SIGINT', stop);
    await worker.run();
  });

program.parseAsync().catch((e) => {
  console.error(e);
  process.exit(2);
});
