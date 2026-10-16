import { arch, hostname } from 'node:os';
import { setTimeout as sleep } from 'node:timers/promises';
import { compare, sha256, type Rect } from '@tintype/diff';
import { mapLimit, StoryCapturer } from './capture.js';
import type { BrowserName, CaptureMode, CaptureResult } from './types.js';

export interface WorkerConfig {
  server: string;
  token: string;
  workerId?: string;
  browsers?: BrowserName[];
  image?: string;
  concurrency?: number;
  /** Exit after this many idle polls (0 = never; useful for tests/CI). */
  exitWhenIdle?: number;
  pollMs?: number;
  log?: (msg: string) => void;
}

interface BaselineRef {
  snapshotId: number;
  sha: string;
  url: string;
  ignoreRegions: Rect[];
}

interface LeasedJob {
  id: number;
  buildId: number;
  attempt: number;
  browser: BrowserName;
  storybookUrl: string;
  stories: { storyId: string; baselines: Record<string, BaselineRef> }[];
  settings: {
    diffThreshold: number;
    includeAntiAliasing: boolean;
    allowedHosts: string[];
    fixedTime: string;
    deviceScaleFactor: number;
    viewport: { width: number; height: number };
  };
  leaseSeconds: number;
}

export interface ResultPayload {
  storyId: string;
  status: CaptureResult['status'];
  modeKey: string;
  modeName: string;
  viewport: { width: number; height: number };
  imageSha: string | null;
  width: number;
  height: number;
  baselineSnapshotId: number | null;
  comparison: 'new' | 'unchanged' | 'changed' | 'inconsistent' | null;
  diffSha: string | null;
  diffRatio: number | null;
  diffRegions: Rect[];
  ignoreRegions: Rect[];
  params: Record<string, unknown>;
  error: string | null;
  durationMs: number;
}

const RETAKES = 2;

export class CaptureWorker {
  private readonly cfg: Required<Omit<WorkerConfig, 'image'>> & { image: string | null };
  private stopping = false;
  private readonly baselineCache = new Map<string, Buffer>();

  constructor(cfg: WorkerConfig) {
    const defined = Object.fromEntries(Object.entries(cfg).filter(([, v]) => v !== undefined && !Number.isNaN(v))) as WorkerConfig;
    this.cfg = {
      workerId: `${hostname()}-${process.pid}`,
      browsers: ['chromium'],
      concurrency: 4,
      exitWhenIdle: 0,
      pollMs: 2000,
      log: (m) => console.log(m),
      ...defined,
      image: cfg.image ?? null,
      server: cfg.server.replace(/\/$/, ''),
    };
  }

  stop(): void {
    this.stopping = true;
  }

  private async api(method: string, path: string, body?: unknown, raw?: Buffer): Promise<Response> {
    const headers: Record<string, string> = {
      Authorization: `Bearer ${this.cfg.token}`,
      Accept: 'application/json',
      'X-Tintype-Arch': arch(),
    };
    let payload: BodyInit | undefined;
    if (raw) {
      headers['Content-Type'] = 'image/png';
      payload = new Uint8Array(raw);
    } else if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    for (let attempt = 0; ; attempt++) {
      try {
        const res = await fetch(`${this.cfg.server}/api/internal/v1/${path}`, { method, headers, body: payload });
        if (res.status >= 500 && attempt < 3) throw new Error(`HTTP ${res.status}`);
        return res;
      } catch (e) {
        if (attempt >= 3) throw e;
        await sleep(500 * 2 ** attempt);
      }
    }
  }

  private identity() {
    return { workerId: this.cfg.workerId, browsers: this.cfg.browsers, arch: arch(), image: this.cfg.image ?? undefined };
  }

  async run(): Promise<void> {
    let idle = 0;
    this.cfg.log(`tintype worker ${this.cfg.workerId} (${arch()}) polling ${this.cfg.server} for ${this.cfg.browsers.join(',')}`);
    while (!this.stopping) {
      let res: Response;
      try {
        res = await this.api('POST', 'jobs/lease', this.identity());
      } catch (e) {
        this.cfg.log(`lease failed: ${(e as Error).message}`);
        await sleep(this.cfg.pollMs);
        continue;
      }
      if (res.status === 204) {
        idle++;
        if (this.cfg.exitWhenIdle && idle >= this.cfg.exitWhenIdle) return;
        await sleep(this.cfg.pollMs);
        continue;
      }
      if (!res.ok) {
        this.cfg.log(`lease rejected: ${res.status} ${await res.text()}`);
        await sleep(this.cfg.pollMs * 5);
        continue;
      }
      idle = 0;
      const { job } = (await res.json()) as { job: LeasedJob };
      await this.process(job);
    }
  }

  async process(job: LeasedJob): Promise<void> {
    const started = Date.now();
    this.cfg.log(`job ${job.id} (build ${job.buildId}, ${job.browser}, ${job.stories.length} stories, attempt ${job.attempt})`);
    const capturer = new StoryCapturer({
      storybookUrl: job.storybookUrl,
      browser: job.browser,
      allowedHosts: job.settings.allowedHosts,
      fixedTime: job.settings.fixedTime,
      deviceScaleFactor: job.settings.deviceScaleFactor,
      defaultViewport: job.settings.viewport,
    });
    let lost = false;
    const beat = setInterval(async () => {
      try {
        const r = await this.api('POST', `jobs/${job.id}/heartbeat`, { workerId: this.cfg.workerId });
        if (r.status === 409) lost = true;
      } catch {
        /* next beat retries */
      }
    }, Math.max(5, Math.floor(job.leaseSeconds / 3)) * 1000);

    let pending: ResultPayload[] = [];
    const flush = async (complete: boolean, error?: string) => {
      const batch = pending;
      pending = [];
      const r = await this.api('POST', `jobs/${job.id}/results`, { workerId: this.cfg.workerId, results: batch, complete, error });
      if (r.status === 409) lost = true;
    };

    try {
      await capturer.start();
      await mapLimit(job.stories, this.cfg.concurrency, async (story) => {
        if (lost || this.stopping) return;
        const results = await capturer.captureStory(story.storyId);
        for (const r of results) pending.push(await this.evaluate(capturer, job, r, story.baselines[r.mode.key] ?? null));
        if (pending.length >= 10) await flush(false);
      });
      if (!lost) await flush(true);
      this.cfg.log(`job ${job.id} done in ${Date.now() - started}ms${lost ? ' (lease lost)' : ''}`);
    } catch (e) {
      this.cfg.log(`job ${job.id} failed: ${(e as Error).stack ?? e}`);
      if (!lost) await flush(true, (e as Error).message).catch(() => undefined);
    } finally {
      clearInterval(beat);
      await capturer.close().catch(() => undefined);
    }
  }

  private async baselineImage(ref: BaselineRef): Promise<Buffer> {
    const cached = this.baselineCache.get(ref.sha);
    if (cached) return cached;
    const res = await fetch(ref.url, { headers: { Authorization: `Bearer ${this.cfg.token}`, 'X-Tintype-Arch': arch() } });
    if (!res.ok) throw new Error(`baseline ${ref.sha} download failed (${res.status})`);
    const buf = Buffer.from(await res.arrayBuffer());
    if (sha256(buf) !== ref.sha) throw new Error(`baseline ${ref.sha} hash mismatch`);
    if (this.baselineCache.size > 500) this.baselineCache.delete(this.baselineCache.keys().next().value!);
    this.baselineCache.set(ref.sha, buf);
    return buf;
  }

  private async upload(images: Buffer[]): Promise<void> {
    const bySha = new Map(images.map((b) => [sha256(b), b]));
    const res = await this.api('POST', 'images/missing', { shas: [...bySha.keys()] });
    const { missing } = (await res.json()) as { missing: string[] };
    for (const sha of missing) {
      const put = await this.api('PUT', `images/${sha}`, undefined, bySha.get(sha)!);
      if (!put.ok) throw new Error(`upload ${sha} failed: ${put.status} ${await put.text()}`);
    }
  }

  /** Compare with the baseline; retake on difference to detect flakiness. */
  async evaluate(capturer: StoryCapturer, job: LeasedJob, r: CaptureResult, baseline: BaselineRef | null): Promise<ResultPayload> {
    const out: ResultPayload = {
      storyId: r.storyId,
      status: r.status,
      modeKey: r.mode.key,
      modeName: r.mode.name,
      viewport: r.mode.viewport,
      imageSha: r.sha256,
      width: r.width,
      height: r.height,
      baselineSnapshotId: baseline?.snapshotId ?? null,
      comparison: null,
      diffSha: null,
      diffRatio: null,
      diffRegions: [],
      ignoreRegions: r.ignoreRegions,
      params: r.params as Record<string, unknown>,
      error: r.error ?? null,
      durationMs: r.durationMs,
    };
    if (!r.image) return out;
    const uploads: Buffer[] = [r.image];
    if (r.status === 'captured') {
      if (!baseline) out.comparison = 'new';
      else if (baseline.sha === r.sha256) out.comparison = 'unchanged';
      else {
        const base = await this.baselineImage(baseline);
        const opts = {
          threshold: (r.params.diffThreshold as number | undefined) ?? job.settings.diffThreshold,
          includeAntiAliasing: (r.params.diffIncludeAntiAliasing as boolean | undefined) ?? job.settings.includeAntiAliasing,
          ignoreRegions: [...r.ignoreRegions, ...(baseline.ignoreRegions ?? [])],
        };
        const d = compare(base, r.image, opts);
        if (!d.changed) out.comparison = 'unchanged';
        else {
          // Retake: a real change reproduces exactly; flakiness doesn't.
          let consistent = true;
          for (let i = 0; i < RETAKES && consistent; i++) {
            const again = await capturer.capture(r.storyId, r.mode as CaptureMode);
            if (!again.image) consistent = false;
            else if (again.sha256 !== r.sha256) consistent = !compare(r.image, again.image, { threshold: 0, ignoreRegions: opts.ignoreRegions }).changed;
          }
          out.comparison = consistent ? 'changed' : 'inconsistent';
          out.diffRatio = d.diffRatio;
          out.diffRegions = d.regions;
          if (d.diffImage) {
            out.diffSha = sha256(d.diffImage);
            uploads.push(d.diffImage);
          }
        }
      }
    }
    await this.upload(uploads);
    return out;
  }
}
