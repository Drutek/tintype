import { PNG } from 'pngjs';
import { describe, expect, it } from 'vitest';
import { clusterRegions, compare, diffPngs, pngSize, sha256 } from './index.js';

function solid(width: number, height: number, rgb: [number, number, number], paint?: (x: number, y: number) => [number, number, number] | null): Buffer {
  const png = new PNG({ width, height });
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const c = paint?.(x, y) ?? rgb;
      const i = (y * width + x) * 4;
      png.data[i] = c[0]; png.data[i + 1] = c[1]; png.data[i + 2] = c[2]; png.data[i + 3] = 255;
    }
  }
  return PNG.sync.write(png);
}

describe('compare', () => {
  it('short-circuits identical bytes', () => {
    const a = solid(10, 10, [255, 255, 255]);
    const r = compare(a, Buffer.from(a));
    expect(r.changed).toBe(false);
    expect(r.width).toBe(10);
  });

  it('detects a changed block and clusters it', () => {
    const a = solid(64, 64, [255, 255, 255]);
    const b = solid(64, 64, [255, 255, 255], (x, y) => (x >= 40 && x < 50 && y >= 8 && y < 12 ? [0, 0, 0] : null));
    const r = diffPngs(a, b);
    expect(r.changed).toBe(true);
    expect(r.diffPixels).toBe(40);
    expect(r.regions).toEqual([{ x: 32, y: 0, width: 32, height: 16 }]);
    expect(r.diffImage).not.toBeNull();
  });

  it('ignores masked regions', () => {
    const a = solid(32, 32, [255, 255, 255]);
    const b = solid(32, 32, [255, 255, 255], (x, y) => (x < 5 && y < 5 ? [0, 0, 0] : null));
    expect(diffPngs(a, b, { ignoreRegions: [{ x: 0, y: 0, width: 5, height: 5 }] }).changed).toBe(false);
  });

  it('treats sub-threshold colour noise as unchanged', () => {
    const a = solid(16, 16, [200, 200, 200]);
    const b = solid(16, 16, [201, 200, 200]);
    expect(diffPngs(a, b).changed).toBe(false);
  });

  it('flags dimension changes', () => {
    const r = diffPngs(solid(10, 10, [0, 0, 0]), solid(10, 12, [0, 0, 0]));
    expect(r.dimensionsChanged).toBe(true);
    expect(r.changed).toBe(true);
    expect(r.height).toBe(12);
  });
});

describe('helpers', () => {
  it('reads png size from header', () => {
    expect(pngSize(solid(7, 3, [0, 0, 0]))).toEqual({ width: 7, height: 3 });
  });
  it('hashes', () => {
    expect(sha256(Buffer.from('a'))).toHaveLength(64);
  });
  it('merges adjacent cells into one region', () => {
    const w = 64, h = 16;
    const mask = new Uint8Array(w * h * 4);
    for (const x of [1, 17, 33]) mask[(2 * w + x) * 4 + 3] = 255;
    expect(clusterRegions(mask, w, h)).toEqual([{ x: 0, y: 0, width: 48, height: 16 }]);
  });
});
