import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { zipSync, type Zippable } from 'fflate';

const STORED = new Set(['.png', '.jpg', '.jpeg', '.gif', '.webp', '.avif', '.woff', '.woff2', '.mp4', '.webm', '.zip', '.gz']);

function walk(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) walk(full, out);
    else out.push(full);
  }
  return out;
}

/** Zip a static Storybook directory. Already-compressed assets are stored, not deflated. */
export function zipDirectory(dir: string): { bytes: Uint8Array; files: number } {
  const files = walk(dir);
  const tree: Zippable = {};
  for (const file of files) {
    const rel = relative(dir, file).split(sep).join('/');
    const ext = rel.slice(rel.lastIndexOf('.')).toLowerCase();
    tree[rel] = [readFileSync(file), { level: STORED.has(ext) ? 0 : 6 }];
  }
  return { bytes: zipSync(tree), files: files.length };
}
