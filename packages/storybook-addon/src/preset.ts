import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setupServerChannel, type AddonOptions } from './server.js';

export function managerEntries(entry: string[] = []): string[] {
  return [...entry, fileURLToPath(new URL('./manager.js', import.meta.url))];
}

export async function experimental_serverChannel<T extends { on: (...a: any[]) => void; emit: (...a: any[]) => void }>(channel: T, options: AddonOptions & { configDir?: string }): Promise<T> {
  // The project root (where package.json and .git live) is the parent of the config dir.
  const cwd = options.configDir ? dirname(options.configDir) : process.cwd();
  setupServerChannel(channel, options, cwd);
  return channel;
}
