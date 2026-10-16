import { existsSync, readFileSync } from 'node:fs';
import { join, posix } from 'node:path';
import { globMatch } from './git.js';

export interface StatsModule {
  id?: string | number;
  name: string;
  reasons?: { moduleName?: string }[];
}

export interface TurboSnapResult {
  /** null = capture everything (full rebuild required). */
  affectedImportPaths: string[] | null;
  reason: string;
}

const FULL_REBUILD_FILES = ['package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lockb', 'bun.lock'];

export function normalizeModuleName(name: string): string {
  let n = name.replace(/\?.*$/, '').replace(/^\.\//, '').replace(/\\/g, '/');
  // webpack "concatenated" names: "./src/a.ts + 3 modules"
  n = n.replace(/ \+ \d+ modules?$/, '');
  return n;
}

export function loadStats(storybookDir: string): StatsModule[] | null {
  const file = join(storybookDir, 'preview-stats.json');
  if (!existsSync(file)) return null;
  const json = JSON.parse(readFileSync(file, 'utf8')) as { modules?: StatsModule[] };
  return json.modules ?? null;
}

/**
 * TurboSnap: walk the reverse dependency graph from changed
 * files up to story files. `changed` and the stats module names are both
 * relative to the Storybook project root (where package.json lives).
 */
export function affectedStories(opts: {
  modules: StatsModule[];
  changed: string[];
  storyImportPaths: string[];
  configDir?: string;
  externals?: string[];
  untraced?: string[];
}): TurboSnapResult {
  const configDir = normalizeModuleName(opts.configDir ?? '.storybook');
  const changed = opts.changed
    .map(normalizeModuleName)
    .filter((f) => !(opts.untraced ?? []).some((p) => globMatch(p, f)));

  for (const f of changed) {
    if (FULL_REBUILD_FILES.includes(posix.basename(f))) return { affectedImportPaths: null, reason: `${f} changed` };
    if (f === configDir || f.startsWith(`${configDir}/`)) return { affectedImportPaths: null, reason: `Storybook config ${f} changed` };
    if ((opts.externals ?? []).some((p) => globMatch(p, f))) return { affectedImportPaths: null, reason: `external ${f} changed` };
  }

  const dependents = new Map<string, Set<string>>();
  const known = new Set<string>();
  for (const m of opts.modules) {
    const name = normalizeModuleName(m.name);
    known.add(name);
    for (const r of m.reasons ?? []) {
      if (!r.moduleName) continue;
      const by = normalizeModuleName(r.moduleName);
      if (!dependents.has(name)) dependents.set(name, new Set());
      dependents.get(name)!.add(by);
    }
  }

  const stories = new Set(opts.storyImportPaths.map(normalizeModuleName));
  const affected = new Set<string>();
  const queue = changed.filter((f) => known.has(f) || stories.has(f));
  const seen = new Set<string>();
  while (queue.length) {
    const m = queue.shift()!;
    if (seen.has(m)) continue;
    seen.add(m);
    if (stories.has(m)) affected.add(m);
    for (const d of dependents.get(m) ?? []) {
      // Changes reaching the preview entry (global decorators etc.) affect everything.
      if (d.startsWith(`${configDir}/`)) return { affectedImportPaths: null, reason: `${m} is imported by Storybook config` };
      queue.push(d);
    }
  }
  return {
    affectedImportPaths: [...affected].map((p) => `./${p}`),
    reason: `${changed.length} changed files affect ${affected.size} story files`,
  };
}
