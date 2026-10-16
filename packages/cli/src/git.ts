import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

export interface GitInfo {
  commit: string;
  branch: string;
  prNumber: number | null;
  shallow: boolean;
  commits: { sha: string; parents: string[]; message?: string; author?: string; committedAt?: string }[];
  repository: string | null;
}

export function git(args: string[], cwd = process.cwd()): string {
  return execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

function tryGit(args: string[], cwd?: string): string | null {
  try {
    return git(args, cwd);
  } catch {
    return null;
  }
}

/** Parse `git log --format=%H%x00%P%x00%an%x00%cI%x00%s` output. */
export function parseLog(out: string): GitInfo['commits'] {
  return out
    .split('\n')
    .filter(Boolean)
    .map((line) => {
      const [sha = '', parents = '', author, committedAt, message] = line.split('\0');
      return { sha, parents: parents.split(' ').filter(Boolean), author, committedAt, message };
    });
}

interface GithubEvent {
  pull_request?: { number: number; head: { sha: string; ref: string } };
  number?: number;
}

function githubEvent(env: NodeJS.ProcessEnv): GithubEvent | null {
  if (!env.GITHUB_EVENT_PATH) return null;
  try {
    return JSON.parse(readFileSync(env.GITHUB_EVENT_PATH, 'utf8')) as GithubEvent;
  } catch {
    return null;
  }
}

/**
 * Commit/branch/PR detection. In GitHub Actions `pull_request` runs, HEAD is a
 * synthetic merge commit, so we report the PR head commit and branch instead
 * so the build is tied to a real commit in the branch history.
 */
export function detectGit(opts: { branchName?: string; env?: NodeJS.ProcessEnv; cwd?: string; historyLimit?: number } = {}): GitInfo {
  const env = opts.env ?? process.env;
  const cwd = opts.cwd;
  const event = githubEvent(env);
  let commit = git(['rev-parse', 'HEAD'], cwd);
  let branch = opts.branchName ?? env.TINTYPE_BRANCH ?? '';
  let prNumber: number | null = null;

  if (event?.pull_request) {
    prNumber = event.pull_request.number;
    const headSha = event.pull_request.head.sha;
    if (tryGit(['cat-file', '-e', `${headSha}^{commit}`], cwd) !== null) commit = headSha;
    branch ||= event.pull_request.head.ref;
  }
  if (!branch) branch = env.GITHUB_HEAD_REF || env.GITHUB_REF_NAME || env.CI_COMMIT_REF_NAME || env.BRANCH_NAME || '';
  if (!branch) {
    const b = tryGit(['rev-parse', '--abbrev-ref', 'HEAD'], cwd);
    branch = b && b !== 'HEAD' ? b : '';
  }
  if (!branch) {
    // Detached HEAD outside CI: pick a branch containing the commit.
    const contains = tryGit(['branch', '--format=%(refname:short)', '--contains', commit], cwd);
    branch = contains?.split('\n').find(Boolean)?.replace(/^origin\//, '') ?? 'HEAD';
  }
  if (!prNumber) {
    const m = /^refs\/pull\/(\d+)\//.exec(env.GITHUB_REF ?? '');
    if (m) prNumber = Number(m[1]);
  }

  const shallow = tryGit(['rev-parse', '--is-shallow-repository'], cwd) === 'true';
  const log = tryGit(['log', `--max-count=${opts.historyLimit ?? 1000}`, '--format=%H%x00%P%x00%an%x00%cI%x00%s', commit], cwd) ?? '';
  const repository = env.GITHUB_REPOSITORY ?? parseRemote(tryGit(['remote', 'get-url', 'origin'], cwd));

  return { commit, branch, prNumber, shallow, commits: parseLog(log), repository };
}

export function parseRemote(url: string | null): string | null {
  if (!url) return null;
  const m = /github\.com[:/]([^/]+\/[^/.]+?)(?:\.git)?$/.exec(url.trim());
  return m ? m[1]! : null;
}

/** Files changed between `base` and HEAD (for TurboSnap). */
export function changedFiles(base: string, cwd?: string): string[] | null {
  const out = tryGit(['diff', '--name-only', `${base}...HEAD`], cwd) ?? tryGit(['diff', '--name-only', base, 'HEAD'], cwd);
  return out === null ? null : out.split('\n').filter(Boolean);
}

/** Simple glob for branch patterns: `*` matches any run except nothing special. */
export function globMatch(pattern: string, value: string): boolean {
  const re = new RegExp(`^${pattern.split('*').map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*')}$`);
  return re.test(value);
}
