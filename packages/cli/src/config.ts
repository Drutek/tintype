import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

export interface Options {
  server?: string;
  projectToken?: string;
  buildScriptName?: string;
  storybookBuildDir?: string;
  branchName?: string;
  exitZeroOnChanges?: boolean | string;
  exitOnceUploaded?: boolean | string;
  autoAcceptChanges?: boolean | string;
  onlyStoryNames?: string[];
  skip?: boolean | string;
  onlyChanged?: boolean | string;
  externals?: string[];
  untraced?: string[];
  traceChanged?: boolean;
  debug?: boolean;
  dryRun?: boolean;
  configFile?: string;
  timeoutMinutes?: number;
}

/** Keys accepted from tintype.config.json. */
const KEYS: (keyof Options)[] = [
  'server', 'projectToken', 'buildScriptName', 'storybookBuildDir', 'branchName', 'exitZeroOnChanges',
  'exitOnceUploaded', 'autoAcceptChanges', 'onlyStoryNames', 'skip', 'onlyChanged', 'externals', 'untraced', 'debug', 'timeoutMinutes',
];

export function loadConfigFile(path?: string, cwd = process.cwd()): Partial<Options> {
  const candidates = path ? [path] : ['tintype.config.json'];
  for (const c of candidates) {
    const full = resolve(cwd, c);
    if (!existsSync(full)) {
      if (path) throw new Error(`Config file ${path} not found`);
      continue;
    }
    const raw = JSON.parse(readFileSync(full, 'utf8')) as Record<string, unknown>;
    const out: Partial<Options> = {};
    for (const k of KEYS) if (raw[k] !== undefined) (out as Record<string, unknown>)[k] = raw[k];
    if (typeof raw.onlyStoryNames === 'string') out.onlyStoryNames = [raw.onlyStoryNames];
    return out;
  }
  return {};
}

/** CLI flags > env > config file. */
export function resolveOptions(flags: Partial<Options>, env: NodeJS.ProcessEnv = process.env): Options {
  const file = loadConfigFile(flags.configFile);
  const merged: Options = { ...file };
  for (const [k, v] of Object.entries(flags)) if (v !== undefined) (merged as Record<string, unknown>)[k] = v;
  merged.projectToken ??= env.TINTYPE_PROJECT_TOKEN;
  merged.server ??= env.TINTYPE_SERVER;
  merged.buildScriptName ??= 'build-storybook';
  return merged;
}

/** A flag that is either boolean or a branch glob: true when it applies to `branch`. */
export function appliesTo(flag: boolean | string | undefined, branch: string, match: (p: string, v: string) => boolean): boolean {
  if (flag === undefined || flag === false) return false;
  if (flag === true || flag === '') return true;
  return String(flag).split(',').some((p) => match(p.trim(), branch));
}
