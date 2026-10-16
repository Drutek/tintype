import { spawnSync } from 'node:child_process';
import { appendFileSync, existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, relative, resolve } from 'node:path';
import pc from 'picocolors';
import { Api, type BuildSummary } from './api.js';
import { appliesTo, resolveOptions, type Options } from './config.js';
import { changedFiles, detectGit, git, globMatch } from './git.js';
import { affectedStories, loadStats } from './turbosnap.js';
import { zipDirectory } from './zip.js';

export const VERSION = '0.1.0';

export interface Logger {
  info(msg: string): void;
  warn(msg: string): void;
  error(msg: string): void;
}

const consoleLogger: Logger = {
  info: (m) => console.log(m),
  warn: (m) => console.warn(pc.yellow(m)),
  error: (m) => console.error(pc.red(m)),
};

function packageManager(cwd: string): string {
  if (existsSync(join(cwd, 'pnpm-lock.yaml'))) return 'pnpm';
  if (existsSync(join(cwd, 'yarn.lock'))) return 'yarn';
  if (existsSync(join(cwd, 'bun.lockb')) || existsSync(join(cwd, 'bun.lock'))) return 'bun';
  // Workspace: look upwards for a lockfile.
  let dir = resolve(cwd);
  for (let i = 0; i < 5; i++) {
    const parent = resolve(dir, '..');
    if (parent === dir) break;
    dir = parent;
    if (existsSync(join(dir, 'pnpm-lock.yaml'))) return 'pnpm';
    if (existsSync(join(dir, 'yarn.lock'))) return 'yarn';
  }
  return 'npm';
}

export function buildStorybook(script: string, outDir: string, statsJson: boolean, log: Logger, cwd = process.cwd()): void {
  const pkg = JSON.parse(readFileSync(join(cwd, 'package.json'), 'utf8')) as { scripts?: Record<string, string> };
  if (!pkg.scripts?.[script]) throw new Error(`package.json has no "${script}" script. Pass --build-script-name or --storybook-build-dir.`);
  const pm = packageManager(cwd);
  const args = ['run', script, '--', '--output-dir', outDir, ...(statsJson ? ['--stats-json', outDir] : [])];
  log.info(pc.dim(`$ ${pm} ${args.join(' ')}`));
  const res = spawnSync(pm, args, { cwd, stdio: ['ignore', 'inherit', 'inherit'], shell: process.platform === 'win32' });
  if (res.status !== 0) throw new Error(`Storybook build failed (exit ${res.status})`);
}

function summaryLine(s: BuildSummary): string {
  const c = s.counts;
  return `${c.snapshots ?? 0} snapshots · ${c.changed ?? 0} changed · ${c.new ?? 0} new · ${c.errors ?? 0} errors · ${c.unchanged ?? 0} unchanged${c.inconsistent ? ` · ${c.inconsistent} inconsistent` : ''}${c.removed ? ` · ${c.removed} removed` : ''}`;
}

function stepSummary(s: BuildSummary): void {
  const file = process.env.GITHUB_STEP_SUMMARY;
  if (!file) return;
  const rows = s.changes.slice(0, 50).map((c) => `| ${c.title} / ${c.name} | ${c.mode} | ${c.browser} | ${c.status} |`);
  appendFileSync(
    file,
    [`### Tintype build ${s.number}: ${s.status}`, '', summaryLine(s), '', `[Review build](${s.url}) · [Storybook](${s.storybookUrl})`, '',
      ...(rows.length ? ['| Story | Mode | Browser | Status |', '|---|---|---|---|', ...rows, ''] : [])].join('\n'),
  );
}

export async function run(flags: Partial<Options>, log: Logger = consoleLogger, cwd = process.cwd()): Promise<number> {
  const opts = resolveOptions(flags);
  if (!opts.projectToken) {
    log.error('Missing project token. Pass --project-token or set TINTYPE_PROJECT_TOKEN.');
    return 3;
  }
  if (!opts.server) {
    log.error('Missing server URL. Pass --server or set TINTYPE_SERVER.');
    return 3;
  }
  const info = detectGit({ branchName: opts.branchName, cwd });
  log.info(`${pc.bold('Tintype')} ${VERSION} · ${info.branch} @ ${info.commit.slice(0, 8)}${info.prNumber ? ` · PR #${info.prNumber}` : ''}`);
  if (info.shallow) log.warn('Shallow clone detected: baselines may fall back to the default branch. Use `fetch-depth: 0` with actions/checkout.');

  if (appliesTo(opts.skip, info.branch, globMatch)) {
    log.info(`Skipping build for branch ${info.branch} (--skip).`);
    return 0;
  }

  const api = new Api(opts.server, opts.projectToken);
  let tmp: string | null = null;
  let dir = opts.storybookBuildDir ? resolve(cwd, opts.storybookBuildDir) : '';
  const turbo = appliesTo(opts.onlyChanged, info.branch, globMatch);
  try {
    if (!dir) {
      tmp = mkdtempSync(join(tmpdir(), 'tintype-'));
      dir = join(tmp, 'storybook-static');
      buildStorybook(opts.buildScriptName!, dir, turbo, log, cwd);
    }
    if (!existsSync(join(dir, 'index.json')) || !existsSync(join(dir, 'iframe.html'))) {
      log.error(`${dir} does not look like a Storybook build (index.json / iframe.html missing).`);
      return 3;
    }

    const options: Record<string, unknown> = {
      autoAcceptChanges: appliesTo(opts.autoAcceptChanges, info.branch, globMatch),
      onlyStoryNames: opts.onlyStoryNames?.length ? opts.onlyStoryNames : undefined,
    };

    if (turbo) {
      const ts = await turboSnap(api, info, dir, opts, cwd, log);
      if (ts) options.turboSnap = ts;
    }

    if (opts.dryRun) {
      log.info('Dry run: not uploading.');
      return 0;
    }

    const build = await api.createBuild({
      commit: info.commit,
      branch: info.branch,
      prNumber: info.prNumber,
      commits: info.commits,
      ci: ciInfo(info.repository),
      cliVersion: VERSION,
      options,
    });
    log.info(`Build ${pc.bold(String(build.number))} created → ${pc.cyan(build.url)}`);

    const { bytes, files } = zipDirectory(dir);
    log.info(`Uploading ${files} files (${(bytes.byteLength / 1024 / 1024).toFixed(1)} MB)…`);
    await api.upload(build.upload, bytes);
    await api.uploaded(build.id);

    if (appliesTo(opts.exitOnceUploaded, info.branch, globMatch)) {
      log.info('Uploaded. Not waiting for results (--exit-once-uploaded).');
      return 0;
    }

    const status = await waitForBuild(api, build.id, log, (opts.timeoutMinutes ?? 30) * 60_000);
    report(status, log);
    stepSummary(status);
    if (status.exitCode === 1 && appliesTo(opts.exitZeroOnChanges, info.branch, globMatch)) return 0;
    return status.exitCode;
  } finally {
    if (tmp) rmSync(tmp, { recursive: true, force: true });
  }
}

async function turboSnap(api: Api, info: ReturnType<typeof detectGit>, dir: string, opts: Options, cwd: string, log: Logger) {
  const modules = loadStats(dir);
  if (!modules) {
    log.warn('TurboSnap: preview-stats.json missing (build with --stats-json); capturing everything.');
    return null;
  }
  const { commits } = await api.baselineCommits({ commit: info.commit, branch: info.branch, commits: info.commits });
  if (!commits.length) {
    log.info('TurboSnap: no baseline build yet; capturing everything.');
    return null;
  }
  const root = git(['rev-parse', '--show-toplevel'], cwd);
  const prefix = relative(root, cwd).split('\\').join('/');
  const changed = new Set<string>();
  for (const base of commits) {
    const files = changedFiles(base, cwd);
    if (!files) {
      log.warn(`TurboSnap: baseline commit ${base.slice(0, 8)} is not in local history; capturing everything.`);
      return null;
    }
    for (const f of files) {
      if (!prefix) changed.add(f);
      else if (f.startsWith(`${prefix}/`)) changed.add(f.slice(prefix.length + 1));
      else if (f === 'package.json' || /lock/.test(f)) changed.add(f.split('/').pop()!); // root lockfile change
    }
  }
  const index = JSON.parse(readFileSync(join(dir, 'index.json'), 'utf8')) as { entries: Record<string, { importPath: string; type?: string }> };
  const storyImportPaths = [...new Set(Object.values(index.entries).filter((e) => e.type !== 'docs').map((e) => e.importPath))];
  const result = affectedStories({ modules, changed: [...changed], storyImportPaths, externals: opts.externals, untraced: opts.untraced });
  log.info(`TurboSnap: ${result.reason}${result.affectedImportPaths === null ? ' → capturing everything' : ''}`);
  return result.affectedImportPaths === null ? null : { affectedImportPaths: result.affectedImportPaths, changedFiles: changed.size };
}

function ciInfo(repository: string | null): Record<string, unknown> {
  const e = process.env;
  if (e.GITHUB_ACTIONS) {
    return { provider: 'github-actions', repository, runId: e.GITHUB_RUN_ID, runUrl: `${e.GITHUB_SERVER_URL}/${e.GITHUB_REPOSITORY}/actions/runs/${e.GITHUB_RUN_ID}`, actor: e.GITHUB_ACTOR };
  }
  if (e.GITLAB_CI) return { provider: 'gitlab', repository: e.CI_PROJECT_PATH, runUrl: e.CI_PIPELINE_URL };
  if (e.CI) return { provider: 'ci', repository };
  return { provider: 'local', repository };
}

export async function waitForBuild(api: Api, id: number, log: Logger, timeoutMs: number): Promise<BuildSummary> {
  const deadline = Date.now() + timeoutMs;
  let last = '';
  for (;;) {
    const s = await api.status(id);
    const line = `${s.status}${s.progress.jobs ? ` (${s.progress.done}/${s.progress.jobs} shards)` : ''}`;
    if (line !== last) {
      log.info(pc.dim(`  … ${line}`));
      last = line;
    }
    if (!['uploading', 'queued', 'capturing'].includes(s.status)) return s;
    if (Date.now() > deadline) throw new Error(`Timed out waiting for build ${s.number}`);
    await new Promise((r) => setTimeout(r, 2000));
  }
}

export function report(s: BuildSummary, log: Logger): void {
  const color = s.exitCode === 0 ? pc.green : s.exitCode === 1 ? pc.yellow : pc.red;
  log.info('');
  log.info(color(pc.bold(`Build ${s.number}: ${s.status}`)) + (s.autoAccepted ? pc.dim(` (auto-accepted: ${s.autoAccepted})`) : ''));
  log.info(summaryLine(s));
  if (s.error) log.error(s.error);
  for (const c of s.changes.slice(0, 20)) {
    const tag = c.status === 'error' ? pc.red('error  ') : c.status === 'new' ? pc.cyan('new    ') : pc.yellow('changed');
    log.info(`  ${tag} ${c.title} / ${c.name} [${c.mode}, ${c.browser}]${c.error ? pc.dim(` — ${c.error}`) : ''}`);
  }
  if (s.changes.length > 20) log.info(pc.dim(`  … and ${s.changes.length - 20} more`));
  log.info(`Review: ${pc.cyan(s.url)}`);
}
