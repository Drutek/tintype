import { describe, expect, it } from 'vitest';
import { affectedStories } from './turbosnap.js';

const modules = [
  { name: './src/Button.tsx', reasons: [{ moduleName: './src/Button.stories.tsx' }, { moduleName: './src/Card.tsx' }] },
  { name: './src/Card.tsx', reasons: [{ moduleName: './src/Card.stories.tsx' }] },
  { name: './src/Button.stories.tsx', reasons: [] },
  { name: './src/Card.stories.tsx', reasons: [] },
  { name: './src/theme.ts', reasons: [{ moduleName: './.storybook/preview.tsx' }] },
  { name: './src/utils.ts?v=123', reasons: [{ moduleName: './src/Card.tsx' }] },
];
const storyImportPaths = ['./src/Button.stories.tsx', './src/Card.stories.tsx'];

describe('affectedStories', () => {
  it('walks reverse dependencies to story files', () => {
    expect(affectedStories({ modules, storyImportPaths, changed: ['src/Button.tsx'] }).affectedImportPaths?.sort()).toEqual([
      './src/Button.stories.tsx',
      './src/Card.stories.tsx',
    ]);
    expect(affectedStories({ modules, storyImportPaths, changed: ['src/utils.ts'] }).affectedImportPaths).toEqual(['./src/Card.stories.tsx']);
  });

  it('a changed story file affects itself', () => {
    expect(affectedStories({ modules, storyImportPaths, changed: ['src/Card.stories.tsx'] }).affectedImportPaths).toEqual(['./src/Card.stories.tsx']);
  });

  it('untracked or unrelated files affect nothing', () => {
    expect(affectedStories({ modules, storyImportPaths, changed: ['README.md'] }).affectedImportPaths).toEqual([]);
  });

  it('forces a full rebuild for config, lockfiles, externals and preview imports', () => {
    expect(affectedStories({ modules, storyImportPaths, changed: ['package.json'] }).affectedImportPaths).toBeNull();
    expect(affectedStories({ modules, storyImportPaths, changed: ['.storybook/main.ts'] }).affectedImportPaths).toBeNull();
    expect(affectedStories({ modules, storyImportPaths, changed: ['src/theme.ts'] }).affectedImportPaths).toBeNull();
    expect(affectedStories({ modules, storyImportPaths, changed: ['public/logo.png'], externals: ['public/*'] }).affectedImportPaths).toBeNull();
  });

  it('respects untraced globs', () => {
    expect(affectedStories({ modules, storyImportPaths, changed: ['package.json'], untraced: ['package.json'] }).affectedImportPaths).toEqual([]);
  });
});
