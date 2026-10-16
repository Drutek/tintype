import { describe, expect, it, vi } from 'vitest';
import { EVENTS } from './constants.js';
import { fetchStoryStatus, resolveConfig, setupServerChannel } from './server.js';

function fakeChannel() {
  const handlers = new Map<string, (...a: any[]) => void>();
  const emitted: [string, any][] = [];
  return {
    on: (e: string, h: (...a: any[]) => void) => handlers.set(e, h),
    emit: (e: string, p: any) => emitted.push([e, p]),
    fire: (e: string, p?: any) => handlers.get(e)?.(p),
    emitted,
  };
}

describe('addon server channel', () => {
  it('reports missing configuration instead of calling the API', async () => {
    const ch = fakeChannel();
    setupServerChannel(ch, {}, process.cwd());
    const prev = { s: process.env.TINTYPE_SERVER, t: process.env.TINTYPE_PROJECT_TOKEN };
    delete process.env.TINTYPE_SERVER;
    delete process.env.TINTYPE_PROJECT_TOKEN;
    await ch.fire(EVENTS.STATUS_REQUEST, { storyId: 'a--b' });
    Object.assign(process.env, { TINTYPE_SERVER: prev.s, TINTYPE_PROJECT_TOKEN: prev.t });
    expect(ch.emitted[0]?.[0]).toBe(EVENTS.STATUS_RESULT);
    expect(ch.emitted[0]?.[1].error).toMatch(/TINTYPE_SERVER/);
  });

  it('fetches story status with the project token', async () => {
    const fetchImpl = vi.fn(async () => new Response(JSON.stringify({ build: null, snapshots: [] }), { status: 200 }));
    const res = await fetchStoryStatus('https://tt.example.com/', 'tt_x', 'button--primary', 'feat/a b', fetchImpl as unknown as typeof fetch);
    expect(res.build).toBeNull();
    expect(fetchImpl).toHaveBeenCalledWith('https://tt.example.com/api/v1/stories/button--primary/status?branch=feat%2Fa%20b', expect.objectContaining({ headers: expect.objectContaining({ Authorization: 'Bearer tt_x' }) }));
  });

  it('prefers addon options over env', () => {
    expect(resolveConfig({ server: 'a', projectToken: 'b' }, { TINTYPE_SERVER: 'x', TINTYPE_PROJECT_TOKEN: 'y' })).toEqual({ server: 'a', token: 'b' });
  });
});
