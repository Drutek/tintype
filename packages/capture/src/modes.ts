import { createHash } from 'node:crypto';
import type { CaptureMode, SnapshotParams, Viewport } from './types.js';

export const DEFAULT_VIEWPORT: Viewport = { width: 1200, height: 900 };

/** Pull our parameters from story parameters (`parameters.tintype`). */
export function snapshotParams(parameters: Record<string, unknown> | undefined): SnapshotParams {
  const p = parameters ?? {};
  return { ...((p.tintype as SnapshotParams) ?? {}) };
}

function stableStringify(v: unknown): string {
  if (v === null || typeof v !== 'object') return JSON.stringify(v);
  if (Array.isArray(v)) return `[${v.map(stableStringify).join(',')}]`;
  const o = v as Record<string, unknown>;
  return `{${Object.keys(o).sort().map((k) => `${JSON.stringify(k)}:${stableStringify(o[k])}`).join(',')}}`;
}

export function modeKey(viewport: Viewport, globals: Record<string, unknown>, isDefault = false): string {
  if (isDefault) return 'default';
  return createHash('sha1').update(stableStringify({ viewport, globals })).digest('hex').slice(0, 12);
}

function toPx(v: unknown): number | undefined {
  if (typeof v === 'number') return v;
  if (typeof v === 'string') {
    const n = Number.parseFloat(v);
    return Number.isFinite(n) ? n : undefined;
  }
  return undefined;
}

/**
 * Resolve a mode's `viewport` value. Accepts a number (width), `{width,height}`,
 * or a named viewport looked up in `parameters.viewport.options|viewports`.
 */
export function resolveViewport(value: unknown, parameters: Record<string, unknown>, fallback: Viewport): Viewport {
  if (value == null) return fallback;
  const width = toPx(value);
  if (width !== undefined) return { width, height: fallback.height };
  if (typeof value === 'object') {
    const v = value as { width?: unknown; height?: unknown };
    return { width: toPx(v.width) ?? fallback.width, height: toPx(v.height) ?? fallback.height };
  }
  if (typeof value === 'string') {
    const vp = (parameters.viewport ?? {}) as { options?: Record<string, unknown>; viewports?: Record<string, unknown> };
    const named = (vp.options ?? vp.viewports ?? {})[value] as { styles?: { width?: unknown; height?: unknown } } | undefined;
    if (named?.styles) return resolveViewport(named.styles, parameters, fallback);
  }
  return fallback;
}

export function resolveModes(parameters: Record<string, unknown> | undefined, fallback: Viewport = DEFAULT_VIEWPORT): CaptureMode[] {
  const params = snapshotParams(parameters);
  const modes = Object.entries(params.modes ?? {}).filter(([, m]) => m && !m.disable);
  if (modes.length) {
    return modes.map(([name, cfg]) => {
      const { viewport, disable: _d, ...globals } = cfg;
      const vp = resolveViewport(viewport, parameters ?? {}, fallback);
      return { key: modeKey(vp, globals), name, viewport: vp, globals };
    });
  }
  if (params.viewports?.length) {
    return params.viewports.map((w) => {
      const vp = { width: w, height: fallback.height };
      return { key: modeKey(vp, {}), name: `${w}px`, viewport: vp, globals: {} };
    });
  }
  return [{ key: 'default', name: 'default', viewport: fallback, globals: {} }];
}

/** Storybook URL `globals` query value, e.g. `theme:dark;locale:fr`. */
export function globalsQuery(globals: Record<string, unknown>): string {
  return Object.entries(globals)
    .filter(([, v]) => ['string', 'number', 'boolean'].includes(typeof v))
    .map(([k, v]) => `${k}:${String(v)}`)
    .join(';');
}
