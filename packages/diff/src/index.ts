import { createHash } from 'node:crypto';
import pixelmatch from 'pixelmatch';
import { PNG } from 'pngjs';

export interface Rect {
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface DiffOptions {
  /** Per-pixel colour distance threshold, 0..1 (YIQ distance). */
  threshold?: number;
  /** When true, anti-aliased pixels count as changes. */
  includeAntiAliasing?: boolean;
  /** Regions (in image coordinates) excluded from comparison. */
  ignoreRegions?: Rect[];
  /** Cell size used to cluster changed pixels into rectangles. */
  regionCellSize?: number;
}

export interface DiffResult {
  changed: boolean;
  dimensionsChanged: boolean;
  diffPixels: number;
  /** diffPixels / (width * height) of the compared canvas. */
  diffRatio: number;
  width: number;
  height: number;
  /** Transparent PNG with only the changed pixels painted, same size as the canvas. */
  diffImage: Buffer | null;
  /** Bounding boxes of clusters of changed pixels (for spotlighting). */
  regions: Rect[];
}

export const DEFAULT_THRESHOLD = 0.063;

export function sha256(buf: Buffer | Uint8Array): string {
  return createHash('sha256').update(buf).digest('hex');
}

export function readPng(buf: Buffer): PNG {
  return PNG.sync.read(buf);
}

export function pngSize(buf: Buffer): { width: number; height: number } {
  // IHDR is always the first chunk: width/height at byte offsets 16 and 20.
  if (buf.length < 24 || buf.readUInt32BE(12) !== 0x49484452) {
    throw new Error('Not a PNG');
  }
  return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) };
}

function padTo(png: PNG, width: number, height: number): Buffer {
  if (png.width === width && png.height === height) return Buffer.from(png.data);
  const out = Buffer.alloc(width * height * 4, 0);
  for (let y = 0; y < png.height; y++) {
    png.data.copy(out, y * width * 4, y * png.width * 4, (y + 1) * png.width * 4);
  }
  return out;
}

function clampRect(r: Rect, width: number, height: number): Rect | null {
  const x1 = Math.max(0, Math.floor(r.x));
  const y1 = Math.max(0, Math.floor(r.y));
  const x2 = Math.min(width, Math.ceil(r.x + r.width));
  const y2 = Math.min(height, Math.ceil(r.y + r.height));
  if (x2 <= x1 || y2 <= y1) return null;
  return { x: x1, y: y1, width: x2 - x1, height: y2 - y1 };
}

/** Copy `from`'s pixels into `to` inside each region so they compare equal. */
function maskRegions(from: Buffer, to: Buffer, width: number, height: number, regions: Rect[]): void {
  for (const raw of regions) {
    const r = clampRect(raw, width, height);
    if (!r) continue;
    for (let y = r.y; y < r.y + r.height; y++) {
      const start = (y * width + r.x) * 4;
      from.copy(to, start, start, start + r.width * 4);
    }
  }
}

/** Cluster changed pixels from a diff mask into bounding rectangles. */
export function clusterRegions(mask: Uint8Array, width: number, height: number, cell = 16): Rect[] {
  const cols = Math.ceil(width / cell);
  const rows = Math.ceil(height / cell);
  const hit = new Uint8Array(cols * rows);
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if (mask[(y * width + x) * 4 + 3]! > 0) hit[Math.floor(y / cell) * cols + Math.floor(x / cell)] = 1;
    }
  }
  const seen = new Uint8Array(cols * rows);
  const regions: Rect[] = [];
  for (let i = 0; i < hit.length; i++) {
    if (!hit[i] || seen[i]) continue;
    let minC = cols, minR = rows, maxC = 0, maxR = 0;
    const stack = [i];
    seen[i] = 1;
    while (stack.length) {
      const j = stack.pop()!;
      const c = j % cols;
      const r = Math.floor(j / cols);
      minC = Math.min(minC, c); maxC = Math.max(maxC, c);
      minR = Math.min(minR, r); maxR = Math.max(maxR, r);
      for (let dr = -1; dr <= 1; dr++) {
        for (let dc = -1; dc <= 1; dc++) {
          const nr = r + dr, nc = c + dc;
          if (nr < 0 || nc < 0 || nr >= rows || nc >= cols) continue;
          const k = nr * cols + nc;
          if (hit[k] && !seen[k]) { seen[k] = 1; stack.push(k); }
        }
      }
    }
    const x = minC * cell;
    const y = minR * cell;
    regions.push({ x, y, width: Math.min(width, (maxC + 1) * cell) - x, height: Math.min(height, (maxR + 1) * cell) - y });
  }
  return regions;
}

export function diffPngs(baseline: Buffer, current: Buffer, opts: DiffOptions = {}): DiffResult {
  const a = readPng(baseline);
  const b = readPng(current);
  const width = Math.max(a.width, b.width);
  const height = Math.max(a.height, b.height);
  const dimensionsChanged = a.width !== b.width || a.height !== b.height;

  const dataA = padTo(a, width, height);
  const dataB = padTo(b, width, height);
  if (opts.ignoreRegions?.length) maskRegions(dataA, dataB, width, height, opts.ignoreRegions);

  const out = new PNG({ width, height });
  const diffPixels = pixelmatch(dataA, dataB, out.data, width, height, {
    threshold: opts.threshold ?? DEFAULT_THRESHOLD,
    includeAA: opts.includeAntiAliasing ?? false,
    diffMask: true,
    diffColor: [255, 0, 255],
    diffColorAlt: [255, 0, 255],
  });
  const changed = dimensionsChanged || diffPixels > 0;
  return {
    changed,
    dimensionsChanged,
    diffPixels,
    diffRatio: width * height === 0 ? 0 : diffPixels / (width * height),
    width,
    height,
    diffImage: changed ? PNG.sync.write(out) : null,
    regions: changed ? clusterRegions(out.data, width, height, opts.regionCellSize) : [],
  };
}

/** Cheap equality first (hash), pixel diff only when bytes differ. */
export function compare(baseline: Buffer, current: Buffer, opts: DiffOptions = {}): DiffResult {
  if (sha256(baseline) === sha256(current)) {
    const { width, height } = pngSize(current);
    return { changed: false, dimensionsChanged: false, diffPixels: 0, diffRatio: 0, width, height, diffImage: null, regions: [] };
  }
  return diffPngs(baseline, current, opts);
}
