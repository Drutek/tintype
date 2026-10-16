import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { chromium, firefox, webkit, type Browser, type BrowserContext, type Page, type Route } from 'playwright';
import { pngSize, sha256 } from '@tintype/diff';
import { DEFAULT_VIEWPORT, globalsQuery, resolveModes, snapshotParams } from './modes.js';
import { freezeAnimations, initScript, measure, nextFrames, readParameters, settleAssets } from './page-scripts.js';
import type { A11yViolation, BrowserName, CaptureMode, CaptureOptions, CaptureResult, SnapshotParams } from './types.js';

let axeSource: string | null = null;
function axe(): string {
  axeSource ??= readFileSync(createRequire(import.meta.url).resolve('axe-core/axe.min.js'), 'utf8');
  return axeSource;
}

/** Run axe-core against the story root and summarise violations. */
export async function runA11y(page: Page): Promise<A11yViolation[] | null> {
  try {
    await page.addScriptTag({ content: axe() });
    return await page.evaluate(async () => {
      const w = window as unknown as { axe: { run: (ctx: unknown, opts: unknown) => Promise<{ violations: any[] }> } };
      const root = document.querySelector('#storybook-root') ?? document.body;
      const res = await w.axe.run(root, { resultTypes: ['violations'] });
      return res.violations.map((v) => ({
        id: v.id as string,
        impact: (v.impact ?? null) as string | null,
        help: v.help as string,
        helpUrl: v.helpUrl as string,
        nodes: v.nodes.length as number,
        targets: v.nodes.slice(0, 5).map((n: { target: string[] }) => n.target.join(' ')),
      }));
    });
  } catch {
    return null;
  }
}

export const CHROMIUM_ARGS = [
  '--disable-gpu',
  '--use-angle=swiftshader',
  '--enable-unsafe-swiftshader',
  '--font-render-hinting=none',
  '--disable-lcd-text',
  '--disable-font-subpixel-positioning',
  '--force-color-profile=srgb',
  '--hide-scrollbars',
  '--disable-skia-runtime-opts',
  '--disable-partial-raster',
  '--disable-renderer-backgrounding',
  '--disable-background-timer-throttling',
  '--disable-backgrounding-occluded-windows',
  '--mute-audio',
];

export const DEFAULT_FIXED_TIME = '2024-01-01T00:00:00.000Z';

/** Extra attempts for navigation timeouts / crashes before reporting an error. */
export const INFRA_RETRIES = 2;

export async function launchBrowser(name: BrowserName = 'chromium'): Promise<Browser> {
  switch (name) {
    case 'firefox':
      return firefox.launch({
        firefoxUserPrefs: {
          'gfx.webrender.software': true,
          'layout.css.devPixelsPerPx': '1.0',
          'ui.prefersReducedMotion': 1,
          'font.size.systemFontScale': 100,
        },
      });
    case 'webkit':
      return webkit.launch();
    default:
      return chromium.launch({ args: CHROMIUM_ARGS });
  }
}

function seedFor(storyId: string, modeKey: string): number {
  return createHash('sha1').update(`${storyId}|${modeKey}`).digest().readUInt32BE(0);
}

function hostAllowed(host: string, allowed: string[]): boolean {
  return allowed.some((pattern) => {
    if (pattern === '*') return true;
    if (pattern.startsWith('*.')) return host === pattern.slice(2) || host.endsWith(pattern.slice(1));
    return host === pattern;
  });
}

type CachedResponse = { status: number; headers: Record<string, string>; body: Buffer };

/**
 * Captures stories from one static Storybook. Holds a browser and a
 * per-session cache of allow-listed external responses so retakes see
 * identical bytes.
 */
export class StoryCapturer {
  private browser: Browser | null = null;
  private readonly cache = new Map<string, Promise<CachedResponse | null>>();
  readonly opts: Required<Omit<CaptureOptions, 'allowedHosts'>> & { allowedHosts: string[] };

  constructor(opts: CaptureOptions) {
    this.opts = {
      browser: 'chromium',
      defaultViewport: DEFAULT_VIEWPORT,
      deviceScaleFactor: 1,
      fixedTime: DEFAULT_FIXED_TIME,
      timeoutMs: 30_000,
      a11y: false,
      locale: 'en-US',
      timezoneId: 'UTC',
      allowedHosts: [],
      ...opts,
      storybookUrl: opts.storybookUrl.endsWith('/') ? opts.storybookUrl : `${opts.storybookUrl}/`,
    };
  }

  async start(): Promise<void> {
    this.browser ??= await launchBrowser(this.opts.browser);
  }

  async close(): Promise<void> {
    await this.browser?.close();
    this.browser = null;
  }

  get browserVersion(): string {
    return this.browser?.version() ?? 'unknown';
  }

  private async route(route: Route): Promise<void> {
    const req = route.request();
    const url = new URL(req.url());
    const sbOrigin = new URL(this.opts.storybookUrl).origin;
    if (url.origin === sbOrigin || url.protocol === 'data:' || url.protocol === 'blob:') return route.continue();
    if (!hostAllowed(url.hostname, this.opts.allowedHosts)) return route.abort('blockedbyclient');
    if (req.method() !== 'GET') return route.continue();
    let entry = this.cache.get(req.url());
    if (!entry) {
      entry = route
        .fetch()
        .then(async (res) => ({ status: res.status(), headers: res.headers(), body: await res.body() }))
        .catch(() => null);
      this.cache.set(req.url(), entry);
    }
    const cached = await entry;
    if (!cached) return route.abort('failed');
    return route.fulfill(cached);
  }

  private async newContext(viewport: { width: number; height: number }, fixedTime: string): Promise<BrowserContext> {
    if (!this.browser) await this.start();
    const ctx = await this.browser!.newContext({
      viewport,
      deviceScaleFactor: this.opts.deviceScaleFactor,
      reducedMotion: 'reduce',
      colorScheme: 'light',
      forcedColors: 'none',
      locale: this.opts.locale,
      timezoneId: this.opts.timezoneId,
      serviceWorkers: 'block',
      ignoreHTTPSErrors: true,
    });
    await ctx.route('**/*', (r) => this.route(r));
    await ctx.clock.setFixedTime(new Date(fixedTime));
    return ctx;
  }

  private storyUrl(storyId: string, globals: Record<string, unknown>): string {
    const q = new URLSearchParams({ id: storyId, viewMode: 'story' });
    const g = globalsQuery(globals);
    if (g) q.set('globals', g);
    return `${this.opts.storybookUrl}iframe.html?${q.toString()}`;
  }

  private async load(page: Page, storyId: string, mode: CaptureMode): Promise<{ errors: string[]; reports: unknown[]; status: string | null }> {
    await page.addInitScript(initScript, { seed: seedFor(storyId, mode.key) });
    await page.goto(this.storyUrl(storyId, mode.globals), { waitUntil: 'load', timeout: this.opts.timeoutMs });
    await page.waitForFunction(() => (window as any).__tintype?.finished === true, undefined, { timeout: this.opts.timeoutMs, polling: 50 });
    return page.evaluate(() => {
      const s = (window as any).__tintype;
      return { errors: s.errors as string[], reports: s.reports as unknown[], status: s.status as string | null };
    });
  }

  private async waitForNetworkQuiet(page: Page, quietMs = 200, maxMs = 5000): Promise<void> {
    await page
      .waitForFunction(
        (q) => {
          const s = (window as any).__tintype;
          return s.inflight === 0 && performance.now() - s.lastNetwork >= q;
        },
        quietMs,
        { timeout: maxMs, polling: 50 },
      )
      .catch(() => undefined);
  }

  /** Read a story's parameters and resolve its modes (one quick render). */
  async probe(storyId: string): Promise<{ parameters: Record<string, unknown>; params: SnapshotParams; modes: CaptureMode[]; error?: string }> {
    const ctx = await this.newContext(this.opts.defaultViewport, this.opts.fixedTime);
    try {
      const page = await ctx.newPage();
      const def: CaptureMode = { key: 'default', name: 'default', viewport: this.opts.defaultViewport, globals: {} };
      const state = await this.load(page, storyId, def);
      const { parameters } = await page.evaluate(readParameters);
      return {
        parameters,
        params: snapshotParams(parameters),
        modes: resolveModes(parameters, this.opts.defaultViewport),
        error: state.status === 'error' ? state.errors.join('\n') || 'Story failed' : undefined,
      };
    } finally {
      await ctx.close();
    }
  }

  /** Capture one story in one mode. */
  async capture(storyId: string, mode: CaptureMode): Promise<CaptureResult> {
    const started = Date.now();
    const base: Omit<CaptureResult, 'status' | 'durationMs'> = {
      storyId,
      browser: this.opts.browser,
      mode,
      image: null,
      sha256: null,
      width: 0,
      height: 0,
      ignoreRegions: [],
      params: {},
    };
    let ctx: BrowserContext | null = null;
    try {
      // Fixed time can be overridden per story, which we only know after a render;
      // callers pass it through mode.globals.__fixedTime when probing found one.
      const fixedTime = (mode.globals.__fixedTime as string | undefined) ?? this.opts.fixedTime;
      const globals = { ...mode.globals };
      delete globals.__fixedTime;
      ctx = await this.newContext(mode.viewport, fixedTime);
      const page = await ctx.newPage();
      const state = await this.load(page, storyId, { ...mode, globals });
      const { parameters, layout } = await page.evaluate(readParameters);
      const params = snapshotParams(parameters);
      if (params.disableSnapshot) {
        return { ...base, params, status: 'skipped', durationMs: Date.now() - started };
      }
      await page.evaluate(settleAssets);
      await this.waitForNetworkQuiet(page);
      if (params.delay && params.delay > 0) await page.waitForTimeout(Math.min(params.delay, 15_000));
      await page.evaluate(settleAssets);
      await page.evaluate(freezeAnimations, params.pauseAnimationAtEnd === true);
      await page.evaluate(nextFrames, 2);

      const geo = await page.evaluate(measure, {
        fullscreen: layout === 'fullscreen' || params.cropToViewport === true,
        ignoreSelectors: params.ignoreSelectors ?? [],
      });
      const image = await page.screenshot({
        clip: geo.clip,
        fullPage: true,
        animations: 'allow',
        caret: 'hide',
        scale: 'device',
        type: 'png',
      });
      const { width, height } = pngSize(image);
      const a11y = this.opts.a11y && params.a11y !== false ? await runA11y(page) : null;
      const dsf = this.opts.deviceScaleFactor;
      const failed = state.status === 'error' || state.errors.length > 0;
      return {
        ...base,
        params,
        image,
        sha256: sha256(image),
        width,
        height,
        ignoreRegions: geo.ignore.map((r) => ({ x: r.x * dsf, y: r.y * dsf, width: r.width * dsf, height: r.height * dsf })),
        reports: state.reports,
        a11y,
        status: failed ? 'error' : 'captured',
        error: failed ? state.errors.join('\n') || 'Story failed' : undefined,
        durationMs: Date.now() - started,
      };
    } catch (e) {
      // Exceptions here are infrastructure problems (navigation timeouts, browser crashes),
      // not story errors, which are reported via the Storybook channel above.
      return { ...base, status: 'error', error: (e as Error).message, infraError: true, durationMs: Date.now() - started };
    } finally {
      await ctx?.close().catch(() => undefined);
    }
  }

  /** Probe then capture every mode for a story. */
  async captureStory(storyId: string): Promise<CaptureResult[]> {
    const probeOnce = () => this.probe(storyId).catch((e: Error) => ({ error: e.message, failed: true, params: {} as SnapshotParams, modes: [] as CaptureMode[], parameters: {} }));
    let probe: Awaited<ReturnType<typeof probeOnce>> = await probeOnce();
    for (let attempt = 1; 'failed' in probe && attempt <= INFRA_RETRIES; attempt++) {
      await new Promise((res) => setTimeout(res, 1000 * attempt));
      probe = await probeOnce();
    }
    if (probe.params.disableSnapshot) {
      return [{
        storyId,
        browser: this.opts.browser,
        mode: { key: 'default', name: 'default', viewport: this.opts.defaultViewport, globals: {} },
        image: null, sha256: null, width: 0, height: 0, ignoreRegions: [], params: probe.params,
        status: 'skipped', durationMs: 0,
      }];
    }
    const modes = probe.modes.length ? probe.modes : resolveModes({}, this.opts.defaultViewport);
    const results: CaptureResult[] = [];
    for (const mode of modes) {
      const m = probe.params.fixedTime ? { ...mode, globals: { ...mode.globals, __fixedTime: probe.params.fixedTime } } : mode;
      let r = await this.capture(storyId, m);
      // Retry infrastructure failures (e.g. a slow server timing out navigation); story errors are final.
      for (let attempt = 1; r.infraError && attempt <= INFRA_RETRIES; attempt++) {
        await new Promise((res) => setTimeout(res, 1000 * attempt));
        r = await this.capture(storyId, m);
      }
      results.push({ ...r, mode });
    }
    return results;
  }
}

/** Run `fn` over items with bounded concurrency, preserving order. */
export async function mapLimit<T, R>(items: T[], limit: number, fn: (item: T, index: number) => Promise<R>): Promise<R[]> {
  const out = new Array<R>(items.length);
  let next = 0;
  const workers = Array.from({ length: Math.max(1, Math.min(limit, items.length)) }, async () => {
    while (next < items.length) {
      const i = next++;
      out[i] = await fn(items[i]!, i);
    }
  });
  await Promise.all(workers);
  return out;
}
