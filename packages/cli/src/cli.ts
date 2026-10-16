#!/usr/bin/env node
import { Command } from 'commander';
import { run, VERSION } from './run.js';

const collect = (v: string, prev: string[] = []) => [...prev, v];
const boolOrGlob = (v?: string) => (v === undefined ? true : v);

const program = new Command('tintype')
  .version(VERSION)
  .description('Build your Storybook, upload it to Tintype and wait for visual test results.')
  .option('-t, --project-token <token>', 'project token (env TINTYPE_PROJECT_TOKEN)')
  .option('--server <url>', 'Tintype server URL (env TINTYPE_SERVER)')
  .option('-b, --build-script-name <name>', 'package.json script that builds Storybook', undefined)
  .option('-d, --storybook-build-dir <dir>', 'use an existing static Storybook build')
  .option('--branch-name <name>', 'override the detected branch')
  .option('--exit-zero-on-changes [branch]', 'exit 0 when there are changes to review', boolOrGlob)
  .option('--exit-once-uploaded [branch]', 'do not wait for results', boolOrGlob)
  .option('--auto-accept-changes [branch]', 'accept all changes (e.g. on main)', boolOrGlob)
  .option('--only-story-names <glob>', 'only capture matching stories (repeatable)', collect)
  .option('--only-changed [branch]', 'TurboSnap: only capture stories affected by changed files', boolOrGlob)
  .option('--externals <glob>', 'files outside the bundle whose change forces a full build (repeatable)', collect)
  .option('--untraced <glob>', 'changed files to ignore for TurboSnap (repeatable)', collect)
  .option('--skip [branch]', 'skip the build entirely', boolOrGlob)
  .option('--config-file <path>', 'config file (default tintype.config.json)')
  .option('--timeout-minutes <n>', 'max minutes to wait for results', (v) => Number.parseInt(v, 10))
  .option('--dry-run', 'build and analyse but do not upload')
  .option('--debug', 'verbose output')
  .action(async (flags) => {
    try {
      process.exitCode = await run(flags);
    } catch (e) {
      console.error((flags.debug ? (e as Error).stack : (e as Error).message) ?? e);
      process.exitCode = 2;
    }
  });

program.parseAsync();
