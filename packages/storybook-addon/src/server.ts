import { spawn, type ChildProcess } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { detectGit } from '@tintype/cli';
import { EVENTS, type StoryStatusResponse } from './constants.js';

export interface AddonOptions {
  server?: string;
  projectToken?: string;
  /** Extra CLI flags for builds started from Storybook. */
  cliArgs?: string[];
}

interface Channel {
  on(event: string, listener: (...args: any[]) => void): void;
  emit(event: string, ...args: any[]): void;
}

export function resolveConfig(opts: AddonOptions, env: NodeJS.ProcessEnv = process.env): { server: string | null; token: string | null } {
  return {
    server: opts.server ?? env.TINTYPE_SERVER ?? null,
    token: opts.projectToken ?? env.TINTYPE_PROJECT_TOKEN ?? null,
  };
}

export async function fetchStoryStatus(server: string, token: string, storyId: string, branch: string, fetchImpl: typeof fetch = fetch): Promise<StoryStatusResponse> {
  const url = `${server.replace(/\/$/, '')}/api/v1/stories/${encodeURIComponent(storyId)}/status?branch=${encodeURIComponent(branch)}`;
  const res = await fetchImpl(url, { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  if (!res.ok) throw new Error(`Tintype responded ${res.status}`);
  return (await res.json()) as StoryStatusResponse;
}

/**
 * Wire the Storybook server channel: the manager panel asks for status and
 * triggers builds; the token stays on the Node side.
 */
export function setupServerChannel(channel: Channel, opts: AddonOptions, cwd = process.cwd()): void {
  const { server, token } = resolveConfig(opts);
  let running: ChildProcess | null = null;
  const branch = () => {
    try {
      return detectGit({ cwd }).branch;
    } catch {
      return 'main';
    }
  };

  channel.on(EVENTS.STATUS_REQUEST, async ({ storyId }: { storyId: string }) => {
    if (!server || !token) {
      channel.emit(EVENTS.STATUS_RESULT, { storyId, error: 'Set TINTYPE_SERVER and TINTYPE_PROJECT_TOKEN (or addon options) to enable visual tests.' });
      return;
    }
    try {
      channel.emit(EVENTS.STATUS_RESULT, { storyId, data: await fetchStoryStatus(server, token, storyId, branch()) });
    } catch (e) {
      channel.emit(EVENTS.STATUS_RESULT, { storyId, error: (e as Error).message });
    }
  });

  channel.on(EVENTS.RUN, () => {
    if (running) return;
    if (!server || !token) {
      channel.emit(EVENTS.RUN_DONE, { code: 3, error: 'Tintype is not configured.' });
      return;
    }
    const require = createRequire(import.meta.url);
    const cli = join(dirname(require.resolve('@tintype/cli/package.json')), 'dist/cli.js');
    running = spawn(process.execPath, [cli, '--exit-zero-on-changes', ...(opts.cliArgs ?? [])], {
      cwd,
      env: { ...process.env, TINTYPE_SERVER: server, TINTYPE_PROJECT_TOKEN: token, FORCE_COLOR: '0' },
    });
    const forward = (buf: Buffer) => {
      for (const line of buf.toString().split('\n').filter(Boolean)) channel.emit(EVENTS.RUN_PROGRESS, { line });
    };
    running.stdout?.on('data', forward);
    running.stderr?.on('data', forward);
    running.on('close', (code) => {
      running = null;
      channel.emit(EVENTS.RUN_DONE, { code });
    });
  });
}
