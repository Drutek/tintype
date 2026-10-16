import { describe, expect, it } from 'vitest';
import { DEFAULT_VIEWPORT, globalsQuery, modeKey, resolveModes, snapshotParams } from './modes.js';

describe('resolveModes', () => {
  it('returns the default mode when nothing is configured', () => {
    expect(resolveModes({})).toEqual([{ key: 'default', name: 'default', viewport: DEFAULT_VIEWPORT, globals: {} }]);
  });

  it('expands modes with viewports and globals', () => {
    const modes = resolveModes({
      tintype: {
        modes: {
          mobile: { viewport: { width: 375, height: 667 } },
          dark: { viewport: 1280, theme: 'dark' },
          off: { disable: true },
        },
      },
    });
    expect(modes.map((m) => m.name)).toEqual(['mobile', 'dark']);
    expect(modes[1]).toMatchObject({ viewport: { width: 1280, height: 900 }, globals: { theme: 'dark' } });
    expect(modes[0]!.key).toHaveLength(12);
  });

  it('resolves named viewports', () => {
    const modes = resolveModes({
      viewport: { options: { iphone: { styles: { width: '390px', height: '844px' } } } },
      tintype: { modes: { phone: { viewport: 'iphone' } } },
    });
    expect(modes[0]!.viewport).toEqual({ width: 390, height: 844 });
  });

  it('supports legacy viewports', () => {
    expect(resolveModes({ tintype: { viewports: [320, 1024] } }).map((m) => m.name)).toEqual(['320px', '1024px']);
  });

  it('mode keys are stable regardless of key order', () => {
    const vp = { width: 1, height: 2 };
    expect(modeKey(vp, { a: 1, b: 2 })).toBe(modeKey(vp, { b: 2, a: 1 }));
  });

  it('reads parameters.tintype', () => {
    expect(snapshotParams({ tintype: { delay: 2 } }).delay).toBe(2);
    expect(snapshotParams(undefined)).toEqual({});
  });

  it('builds globals query', () => {
    expect(globalsQuery({ theme: 'dark', obj: {}, n: 2 })).toBe('theme:dark;n:2');
  });
});
