export type BuildStatus =
    | 'uploading'
    | 'queued'
    | 'capturing'
    | 'pending'
    | 'accepted'
    | 'denied'
    | 'passed'
    | 'broken'
    | 'failed'
    | 'canceled';

export type SnapshotStatus =
    | 'new'
    | 'unchanged'
    | 'changed'
    | 'error'
    | 'skipped'
    | 'removed'
    | 'inconsistent';

export type ReviewState = 'pending' | 'accepted' | 'denied' | null;
export type Role = 'owner' | 'admin' | 'reviewer' | 'viewer';

export interface Rect {
    x: number;
    y: number;
    width: number;
    height: number;
}

export interface OrgRef {
    id: number;
    name: string;
    slug: string;
}

export interface ProjectRef {
    id: number;
    name: string;
    slug: string;
    defaultBranch: string;
    repository: string | null;
}

export interface BuildCounts {
    snapshots: number;
    changed: number;
    new: number;
    errors: number;
    unchanged: number;
    skipped: number;
    removed: number;
    inconsistent: number;
    stories: number;
}

export interface BuildRow {
    number: number;
    status: BuildStatus;
    branch: string;
    commit: string;
    prNumber: number | null;
    createdAt: string;
    counts: BuildCounts;
}

export interface BuildDetail extends BuildRow {
    id: number;
    error: string | null;
    baselineStrategy: string | null;
    baselineBuilds: { number: number; branch: string; commit: string }[];
    captureImage: string | null;
    ci: Record<string, string> | null;
    autoAccepted: string | null;
    storybookUrl: string;
    startedAt: string | null;
    completedAt: string | null;
    progress: { total: number; done: number };
}

export interface SnapshotProps {
    id: number;
    storyId: string;
    title: string;
    name: string;
    importPath: string | null;
    browser: string;
    mode: string;
    modeKey: string;
    viewport: { width: number; height: number } | null;
    status: SnapshotStatus;
    review: ReviewState;
    reviewedBy: string | null;
    image: string | null;
    width: number | null;
    height: number | null;
    baseline: {
        id: number;
        image: string | null;
        width: number | null;
        height: number | null;
        buildId: number;
    } | null;
    diff: string | null;
    diffRatio: number | null;
    diffRegions: Rect[];
    ignoreRegions: Rect[];
    error: string | null;
    inherited: boolean;
    commentCount: number;
    needsReview: boolean;
    history: {
        decision: string;
        source: string;
        user: string | null;
        at: string;
    }[];
}

export interface CommentProps {
    id: number;
    snapshotId: number | null;
    body: string;
    user: string | null;
    createdAt: string;
    resolved: boolean;
    replies: { id: number; body: string; user: string | null; createdAt: string }[];
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
    total: number;
}
