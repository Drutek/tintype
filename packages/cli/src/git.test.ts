import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { execFileSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';
import { detectGit, globMatch, parseLog, parseRemote } from './git.js';
import { appliesTo } from './config.js';

function repo(): string {
  const dir = mkdtempSync(join(tmpdir(), 'tt-git-'));
  const g = (...a: string[]) => execFileSync('git', a, { cwd: dir, env: { ...process.env, GIT_AUTHOR_NAME: 'a', GIT_AUTHOR_EMAIL: 'a@a', GIT_COMMITTER_NAME: 'a', GIT_COMMITTER_EMAIL: 'a@a' } });
  g('init', '-q', '-b', 'main');
  writeFileSync(join(dir, 'a'), '1');
  g('add', '.');
  g('commit', '-qm', 'one');
  writeFileSync(join(dir, 'a'), '2');
  g('commit', '-qam', 'two');
  return dir;
}

describe('git detection', () => {
  it('reads commit, branch and ancestry', () => {
    const dir = repo();
    const info = detectGit({ cwd: dir, env: {} });
    expect(info.branch).toBe('main');
    expect(info.commits).toHaveLength(2);
    expect(info.commits[0]!.parents).toEqual([info.commits[1]!.sha]);
    expect(info.commits[0]!.message).toBe('two');
    expect(info.prNumber).toBeNull();
  });

  it('uses PR head from the GitHub event', () => {
    const dir = repo();
    const head = execFileSync('git', ['rev-parse', 'HEAD~1'], { cwd: dir, encoding: 'utf8' }).trim();
    const ev = join(dir, 'event.json');
    writeFileSync(ev, JSON.stringify({ pull_request: { number: 42, head: { sha: head, ref: 'feature/x' } } }));
    const info = detectGit({ cwd: dir, env: { GITHUB_EVENT_PATH: ev, GITHUB_REF: 'refs/pull/42/merge' } });
    expect(info).toMatchObject({ commit: head, branch: 'feature/x', prNumber: 42 });
  });

  it('parses helpers', () => {
    expect(parseLog('a\0b c\0me\0t\0msg\n')).toEqual([{ sha: 'a', parents: ['b', 'c'], author: 'me', committedAt: 't', message: 'msg' }]);
    expect(parseRemote('git@github.com:acme/web.git')).toBe('acme/web');
    expect(parseRemote('https://github.com/acme/web')).toBe('acme/web');
    expect(globMatch('release/*', 'release/1.2')).toBe(true);
    expect(appliesTo('main,release/*', 'release/x', globMatch)).toBe(true);
    expect(appliesTo(true, 'x', globMatch)).toBe(true);
    expect(appliesTo(undefined, 'x', globMatch)).toBe(false);
  });
});
