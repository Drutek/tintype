export const ADDON_ID = 'tintype/visual-tests';
export const PANEL_ID = `${ADDON_ID}/panel`;

export const EVENTS = {
  STATUS_REQUEST: `${ADDON_ID}/status-request`,
  STATUS_RESULT: `${ADDON_ID}/status-result`,
  RUN: `${ADDON_ID}/run`,
  RUN_PROGRESS: `${ADDON_ID}/run-progress`,
  RUN_DONE: `${ADDON_ID}/run-done`,
} as const;

export interface StorySnapshotStatus {
  id: number;
  browser: string;
  mode: string;
  status: 'new' | 'unchanged' | 'changed' | 'error' | 'skipped' | 'removed' | 'inconsistent';
  review: 'pending' | 'accepted' | 'denied' | null;
  image: string | null;
  baseline: string | null;
  diff: string | null;
  diffRatio: number | null;
  error: string | null;
  a11y: { id: string; impact: string | null; help: string; helpUrl: string; nodes: number }[] | null;
  reviewUrl: string;
}

export interface StoryStatusResponse {
  build: { id: number; number: number; status: string; url: string; commit: string; branch: string } | null;
  snapshots: StorySnapshotStatus[];
}
