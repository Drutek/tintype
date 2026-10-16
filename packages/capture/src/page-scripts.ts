/**
 * Code that runs inside the Storybook preview iframe. Kept as plain functions
 * (no closures over Node scope) so Playwright can serialise them.
 */

export interface InitConfig {
  seed: number;
}

/** Installed before any page script runs. */
export function initScript(cfg: InitConfig): void {
  // Seeded PRNG (mulberry32) so Math.random is deterministic per story.
  let s = cfg.seed >>> 0;
  Math.random = () => {
    s = (s + 0x6d2b79f5) >>> 0;
    let t = s;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };

  type State = {
    finished: boolean;
    status: 'success' | 'error' | null;
    errors: string[];
    reports: unknown[];
    inflight: number;
    lastNetwork: number;
  };
  const state: State = { finished: false, status: null, errors: [], reports: [], inflight: 0, lastNetwork: performance.now() };
  (window as unknown as { __tintype: State }).__tintype = state;

  // Track in-flight fetch/XHR so we can wait for network quiet.
  const bump = (d: number) => {
    state.inflight += d;
    state.lastNetwork = performance.now();
  };
  const origFetch = window.fetch;
  window.fetch = function (this: unknown, ...args: Parameters<typeof fetch>) {
    bump(1);
    return origFetch.apply(this, args).finally(() => bump(-1));
  } as typeof fetch;
  const origSend = XMLHttpRequest.prototype.send;
  XMLHttpRequest.prototype.send = function (...args: Parameters<XMLHttpRequest['send']>) {
    bump(1);
    this.addEventListener('loadend', () => bump(-1), { once: true });
    return origSend.apply(this, args);
  };

  // Hook the Storybook channel the moment it is assigned.
  let channel: { on: (e: string, fn: (...a: any[]) => void) => void } | undefined;
  const attach = (ch: typeof channel) => {
    if (!ch) return;
    const err = (label: string) => (e: unknown) => {
      const msg = (e as { message?: string; description?: string; title?: string })?.message
        ?? (e as { description?: string })?.description
        ?? (e as { title?: string })?.title
        ?? String(e);
      state.errors.push(`${label}: ${msg}`);
    };
    ch.on('storyErrored', err('Story errored'));
    ch.on('storyThrewException', err('Story threw'));
    ch.on('playFunctionThrewException', err('Play function failed'));
    ch.on('storyMissing', () => {
      state.errors.push('Story missing');
      state.status = 'error';
      state.finished = true;
    });
    ch.on('storyFinished', (p: { status: 'success' | 'error'; reporters?: unknown[] }) => {
      state.status = p?.status ?? 'success';
      state.reports = p?.reporters ?? [];
      state.finished = true;
    });
  };
  const KEY = '__STORYBOOK_ADDONS_CHANNEL__';
  const existing = (window as unknown as Record<string, typeof channel>)[KEY];
  if (existing) attach(existing);
  else {
    Object.defineProperty(window, KEY, {
      configurable: true,
      get: () => channel,
      set: (v) => {
        channel = v;
        attach(v);
      },
    });
  }

  // Global stabilising CSS: no transitions, no caret, no scrollbars.
  const css = `*,*::before,*::after{transition-duration:0s!important;transition-delay:0s!important;caret-color:transparent!important;scroll-behavior:auto!important}
::-webkit-scrollbar{display:none!important}html{scrollbar-width:none!important}`;
  const addStyle = () => {
    const el = document.createElement('style');
    el.setAttribute('data-tintype', '');
    el.textContent = css;
    (document.head ?? document.documentElement).appendChild(el);
  };
  if (document.head) addStyle();
  else document.addEventListener('DOMContentLoaded', addStyle, { once: true });
}

/** Read the current story's parameters in a JSON-safe form. */
export function readParameters(): { parameters: Record<string, unknown>; layout: string | null } {
  const preview = (window as unknown as { __STORYBOOK_PREVIEW__?: any }).__STORYBOOK_PREVIEW__;
  const params = preview?.currentRender?.story?.parameters ?? {};
  const safe = JSON.parse(
    JSON.stringify(params, (_k, v) => (typeof v === 'function' || typeof v === 'symbol' ? undefined : v)),
  );
  return { parameters: safe, layout: typeof params.layout === 'string' ? params.layout : null };
}

/** Make lazy images eager and wait for images + fonts. */
export async function settleAssets(): Promise<void> {
  document.querySelectorAll('img[loading="lazy"]').forEach((img) => ((img as HTMLImageElement).loading = 'eager'));
  await document.fonts.ready;
  const imgs = Array.from(document.images);
  await Promise.all(
    imgs.map(async (img) => {
      if (!img.complete) {
        await new Promise((r) => {
          img.addEventListener('load', r, { once: true });
          img.addEventListener('error', r, { once: true });
        });
      }
      try {
        await img.decode();
      } catch {
        /* broken images are captured as-is */
      }
    }),
  );
  await document.fonts.ready;
}

/** Pause animations at their start (or end when requested); finish transitions. */
export function freezeAnimations(atEnd: boolean): void {
  for (const a of document.getAnimations()) {
    const isTransition = typeof CSSTransition !== 'undefined' && a instanceof CSSTransition;
    const timing = a.effect?.getComputedTiming();
    const finite = timing && Number.isFinite(timing.endTime as number);
    try {
      if (isTransition || (atEnd && finite)) a.finish();
      else {
        a.pause();
        a.currentTime = 0;
      }
    } catch {
      a.cancel();
    }
  }
  // Pause <video> and reset to first frame.
  document.querySelectorAll('video').forEach((v) => {
    v.pause();
    v.currentTime = 0;
  });
}

export function nextFrames(n: number): Promise<void> {
  return new Promise((resolve) => {
    const step = (left: number) => (left <= 0 ? resolve() : requestAnimationFrame(() => step(left - 1)));
    step(n);
  });
}

export interface Geometry {
  clip: { x: number; y: number; width: number; height: number };
  ignore: { x: number; y: number; width: number; height: number }[];
}

/** Work out what to crop to, and where the ignore regions are within the crop. */
export function measure(args: { fullscreen: boolean; ignoreSelectors: string[] }): Geometry {
  const sx = window.scrollX;
  const sy = window.scrollY;
  const doc = document.documentElement;
  const pageW = Math.max(doc.scrollWidth, window.innerWidth);
  const pageH = Math.max(doc.scrollHeight, window.innerHeight);
  const errorShown = document.body.classList.contains('sb-show-errordisplay');

  let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
  const add = (r: DOMRect) => {
    if (r.width <= 0 || r.height <= 0) return;
    x1 = Math.min(x1, r.left + sx);
    y1 = Math.min(y1, r.top + sy);
    x2 = Math.max(x2, r.right + sx);
    y2 = Math.max(y2, r.bottom + sy);
  };
  if (!args.fullscreen && !errorShown) {
    const root = document.querySelector('#storybook-root') ?? document.querySelector('#root');
    if (root) {
      add(root.getBoundingClientRect());
      // Include overflow from descendants (e.g. absolutely positioned popovers).
      root.querySelectorAll('*').forEach((el) => {
        const st = getComputedStyle(el);
        if (st.position === 'absolute' || st.position === 'fixed') add(el.getBoundingClientRect());
      });
    }
    // Portals rendered as direct children of <body>.
    Array.from(document.body.children).forEach((el) => {
      if (el.id === 'storybook-root' || el.id === 'storybook-docs' || el.id === 'root') return;
      if (['SCRIPT', 'STYLE', 'LINK', 'TEMPLATE', 'NOSCRIPT'].includes(el.tagName)) return;
      if (el.classList.contains('sb-wrapper') || el.className.toString().startsWith('sb-')) return;
      const st = getComputedStyle(el);
      if (st.display === 'none' || st.visibility === 'hidden') return;
      add(el.getBoundingClientRect());
    });
  }
  let clip: Geometry['clip'];
  if (!Number.isFinite(x1)) {
    clip = { x: 0, y: 0, width: args.fullscreen ? pageW : window.innerWidth, height: args.fullscreen ? pageH : window.innerHeight };
  } else {
    const cx = Math.max(0, Math.floor(x1));
    const cy = Math.max(0, Math.floor(y1));
    clip = { x: cx, y: cy, width: Math.max(1, Math.min(pageW, Math.ceil(x2)) - cx), height: Math.max(1, Math.min(pageH, Math.ceil(y2)) - cy) };
  }

  const selectors = ['.tintype-ignore', '[data-tintype="ignore"]', ...args.ignoreSelectors];
  const ignore: Geometry['ignore'] = [];
  for (const sel of selectors) {
    let els: NodeListOf<Element>;
    try {
      els = document.querySelectorAll(sel);
    } catch {
      continue;
    }
    els.forEach((el) => {
      const r = el.getBoundingClientRect();
      if (r.width <= 0 || r.height <= 0) return;
      ignore.push({ x: r.left + sx - clip.x, y: r.top + sy - clip.y, width: r.width, height: r.height });
    });
  }
  return { clip, ignore };
}
