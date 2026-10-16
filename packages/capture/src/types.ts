import type { Rect } from '@tintype/diff';

export type BrowserName = 'chromium' | 'firefox' | 'webkit';

export interface StoryIndexEntry {
  id: string;
  title: string;
  name: string;
  importPath: string;
  type: 'story' | 'docs';
  tags?: string[];
  componentPath?: string;
}

export interface Viewport {
  width: number;
  height: number;
}

/** A resolved capture target: a story rendered in one mode. */
export interface CaptureMode {
  /** Stable key; `default` when no modes are configured. */
  key: string;
  /** Human name as configured (e.g. "dark desktop"). */
  name: string;
  viewport: Viewport;
  globals: Record<string, unknown>;
}

/** Story parameters under `parameters.tintype`. */
export interface SnapshotParams {
  disableSnapshot?: boolean;
  delay?: number;
  diffThreshold?: number;
  diffIncludeAntiAliasing?: boolean;
  pauseAnimationAtEnd?: boolean;
  ignoreSelectors?: string[];
  viewports?: number[];
  modes?: Record<string, Record<string, unknown> & { viewport?: unknown; disable?: boolean }>;
  /** Override the frozen clock for this story (ISO string). */
  fixedTime?: string;
  cropToViewport?: boolean;
  /** Set false to skip accessibility checks for this story. */
  a11y?: boolean;
}

export interface CaptureOptions {
  /** Base URL that serves the static Storybook (must end with `/`). */
  storybookUrl: string;
  browser?: BrowserName;
  defaultViewport?: Viewport;
  deviceScaleFactor?: number;
  /** Default frozen time for Date. */
  fixedTime?: string;
  /** Hostnames (glob-ish, `*.example.com`) allowed for external requests. */
  allowedHosts?: string[];
  /** Per-story timeout in ms. */
  timeoutMs?: number;
  locale?: string;
  timezoneId?: string;
  /** Run axe-core accessibility checks after each screenshot. */
  a11y?: boolean;
}

export interface A11yViolation {
  id: string;
  impact: string | null;
  help: string;
  helpUrl: string;
  nodes: number;
  targets: string[];
}

export interface CaptureResult {
  storyId: string;
  browser: BrowserName;
  mode: CaptureMode;
  image: Buffer | null;
  sha256: string | null;
  width: number;
  height: number;
  ignoreRegions: Rect[];
  params: SnapshotParams;
  /** `skipped` when disableSnapshot; `error` when render/play failed. */
  status: 'captured' | 'skipped' | 'error';
  error?: string;
  /** Interaction-test / addon reports emitted with STORY_FINISHED. */
  reports?: unknown[];
  /** axe-core violations (when a11y is enabled and not disabled for the story). */
  a11y?: A11yViolation[] | null;
  durationMs: number;
}
