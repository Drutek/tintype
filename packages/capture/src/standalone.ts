import { mkdir, writeFile } from 'node:fs/promises';
import { arch, platform } from 'node:os';
import { join } from 'node:path';
import { diffPngs } from '@tintype/diff';
import { mapLimit, StoryCapturer } from './capture.js';
import { loadStoryIndex, matchesStory, serveDirectory } from './storybook.js';
import type { BrowserName, CaptureResult } from './types.js';

export interface RunOptions {
  storybook: string;
  out?: string;
  browser?: BrowserName;
  concurrency?: number;
  only?: string[];
  log?: (msg: string) => void;
}

async function openStorybook(src: string): Promise<{ url: string; close: () => Promise<void> }> {
  if (/^https?:\/\//.test(src)) return { url: src.endsWith('/') ? src : `${src}/`, close: async () => undefined };
  return serveDirectory(src);
}

export function fileNameFor(r: Pick<CaptureResult, 'storyId' | 'mode' | 'browser'>): string {
  return `${r.storyId}__${r.browser}__${r.mode.key}.png`;
}

/** Capture every story once; optionally write PNGs + manifest to `out`. */
export async function captureAll(opts: RunOptions): Promise<{ results: CaptureResult[]; browserVersion: string }> {
  const log = opts.log ?? (() => undefined);
  const sb = await openStorybook(opts.storybook);
  const capturer = new StoryCapturer({ storybookUrl: sb.url, browser: opts.browser });
  try {
    await capturer.start();
    const stories = (await loadStoryIndex(sb.url)).filter((s) => matchesStory(s, opts.only));
    if (stories[0]) await capturer.prefetchParameters(stories[0].id);
    log(`Capturing ${stories.length} stories with ${opts.browser ?? 'chromium'} ${capturer.browserVersion}`);
    const nested = await mapLimit(stories, opts.concurrency ?? 4, async (s) => {
      const rs = await capturer.captureStory(s.id);
      for (const r of rs) log(`  ${r.status.padEnd(8)} ${s.id} [${r.mode.name}] ${r.width}x${r.height} ${r.durationMs}ms${r.error ? ` — ${r.error.split('\n')[0]}` : ''}`);
      return rs;
    });
    const results = nested.flat();
    if (opts.out) {
      await mkdir(opts.out, { recursive: true });
      for (const r of results) if (r.image) await writeFile(join(opts.out, fileNameFor(r)), r.image);
      const manifest = results.map(({ image: _i, ...rest }) => ({ ...rest, file: rest.sha256 ? fileNameFor(rest) : null }));
      await writeFile(join(opts.out, 'manifest.json'), JSON.stringify({ browserVersion: capturer.browserVersion, platform: platform(), arch: arch(), results: manifest }, null, 2));
    }
    return { results, browserVersion: capturer.browserVersion };
  } finally {
    await capturer.close();
    await sb.close();
  }
}

export interface DeterminismReport {
  runs: number;
  browser: string;
  browserVersion: string;
  platform: string;
  arch: string;
  snapshots: number;
  consistent: number;
  inconsistent: { key: string; distinctHashes: number; maxDiffPixels: number; maxDiffRatio: number }[];
  errors: string[];
  /** Render-error snapshots (not comparable by design). */
  excluded: string[];
  /** Bytes differed only inside ignore regions. */
  maskedOnly: string[];
  durationMs: number;
  /** Map of snapshot key -> sha256 from the first run, for cross-environment comparison. */
  hashes: Record<string, string | null>;
}

/** Capture `runs` times and report snapshots whose pixels differ between runs. */
export async function determinism(opts: RunOptions & { runs: number }): Promise<DeterminismReport> {
  const log = opts.log ?? (() => undefined);
  const started = Date.now();
  const byKey = new Map<string, CaptureResult[]>();
  let browserVersion = 'unknown';
  for (let i = 0; i < opts.runs; i++) {
    log(`Run ${i + 1}/${opts.runs}`);
    const { results, browserVersion: v } = await captureAll({ ...opts, out: undefined, log: () => undefined });
    browserVersion = v;
    for (const r of results) {
      const k = `${r.storyId}__${r.mode.key}`;
      byKey.set(k, [...(byKey.get(k) ?? []), r]);
    }
  }
  const report: DeterminismReport = {
    runs: opts.runs,
    browser: opts.browser ?? 'chromium',
    browserVersion,
    platform: platform(),
    arch: arch(),
    snapshots: 0,
    consistent: 0,
    inconsistent: [],
    errors: [],
    excluded: [],
    maskedOnly: [],
    durationMs: 0,
    hashes: {},
  };
  for (const [key, rs] of byKey) {
    report.hashes[key] = rs[0]?.sha256 ?? null;
    const errs = rs.filter((r) => r.status === 'error' && !/Play function failed|Story threw|Story errored/.test(r.error ?? ''));
    if (errs.length) report.errors.push(`${key}: ${errs[0]!.error}`);
    // Render errors show Storybook's error overlay (with URLs/ports); they are never diffed.
    if (rs.some((r) => r.status === 'error' && /Story threw|Story errored|Story missing/.test(r.error ?? ''))) {
      report.excluded.push(key);
      continue;
    }
    const hashes = new Set(rs.map((r) => r.sha256));
    if (hashes.size <= 1) {
      report.consistent++;
      continue;
    }
    const first = rs.find((r) => r.image)!;
    let maxDiffPixels = 0;
    let maxDiffRatio = 0;
    for (const r of rs) {
      if (!r.image || !first.image || r.sha256 === first.sha256) continue;
      // Strictest comparison, but honour ignore regions exactly as the server would.
      const d = diffPngs(first.image, r.image, { threshold: 0, includeAntiAliasing: true, ignoreRegions: [...first.ignoreRegions, ...r.ignoreRegions] });
      if (!d.changed) continue;
      maxDiffPixels = Math.max(maxDiffPixels, d.diffPixels);
      maxDiffRatio = Math.max(maxDiffRatio, d.diffRatio);
      if (opts.out) {
        await mkdir(join(opts.out, 'inconsistent'), { recursive: true });
        await writeFile(join(opts.out, 'inconsistent', `${key}__a.png`), first.image);
        await writeFile(join(opts.out, 'inconsistent', `${key}__b.png`), r.image);
      }
    }
    if (maxDiffPixels === 0) {
      report.consistent++;
      report.maskedOnly.push(key);
      continue;
    }
    report.inconsistent.push({ key, distinctHashes: hashes.size, maxDiffPixels, maxDiffRatio });
  }
  report.snapshots = byKey.size - report.excluded.length;
  report.durationMs = Date.now() - started;
  if (opts.out) {
    await mkdir(opts.out, { recursive: true });
    await writeFile(join(opts.out, 'determinism.json'), JSON.stringify(report, null, 2));
  }
  return report;
}
