import { Form, Head, Link, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { Check, ExternalLink, GitBranch, GitCommit, GitPullRequest, MessageSquare, RotateCcw, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { SnapshotViewer, VIEW_MODES, type ViewMode } from '@/components/tintype/snapshot-viewer';
import { BuildStatusBadge, shortSha, SnapshotStatusBadge, timeAgo } from '@/components/tintype/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as showBuild, review as reviewBuild } from '@/routes/builds';
import { store as storeComment, resolve as resolveComment } from '@/routes/builds/comments';
import { show as showOrg } from '@/routes/organizations';
import { show as showProject } from '@/routes/projects';
import { show as showPull } from '@/routes/pulls';
import type { BuildDetail, CommentProps, OrgRef, ProjectRef, Role, SnapshotProps, SnapshotStatus } from '@/types';

interface Props {
    organization: OrgRef;
    project: ProjectRef;
    role: Role;
    build: BuildDetail;
    snapshots: SnapshotProps[];
    comments: CommentProps[];
    selected: number | null;
}

const GROUPS: { status: SnapshotStatus[]; label: string }[] = [
    { status: ['changed'], label: 'Changes' },
    { status: ['new'], label: 'New stories' },
    { status: ['error'], label: 'Errors' },
    { status: ['inconsistent'], label: 'Inconsistent' },
    { status: ['removed'], label: 'Removed' },
    { status: ['unchanged', 'skipped'], label: 'Unchanged' },
];

const IN_PROGRESS = ['uploading', 'queued', 'capturing'];

export default function BuildShow({ organization, project, role, build, snapshots, comments, selected }: Props) {
    const canReview = role !== 'viewer';
    const [filter, setFilter] = useState<'changes' | 'all'>(
        snapshots.some((s) => s.needsReview || s.status === 'error' || s.status === 'inconsistent') ? 'changes' : 'all',
    );
    const visible = useMemo(
        () => (filter === 'all' ? snapshots : snapshots.filter((s) => !['unchanged', 'skipped'].includes(s.status))),
        [filter, snapshots],
    );
    const [currentId, setCurrentId] = useState<number | null>(selected ?? visible[0]?.id ?? null);
    const current = snapshots.find((s) => s.id === currentId) ?? visible[0] ?? null;
    const [mode, setMode] = useState<ViewMode>('diff');
    const [zoom, setZoom] = useState<number | 'fit'>('fit');
    const [spotlight, setSpotlight] = useState(false);
    const [showDiff, setShowDiff] = useState(true);
    const params = { organization: organization.slug, project: project.slug, build: build.number };

    // Live progress: websocket when Reverb is configured, polling as a fallback.
    useEcho(`builds.${build.id}`, '.build.updated', () => router.reload({ only: ['build', 'snapshots'] }));
    useEffect(() => {
        if (!IN_PROGRESS.includes(build.status)) return;
        const t = setInterval(() => router.reload({ only: ['build', 'snapshots'] }), 3000);

        return () => clearInterval(t);
    }, [build.status]);

    const decide = useCallback(
        (decision: 'accepted' | 'denied' | 'pending', ids: number[] | 'all') => {
            router.post(
                reviewBuild.url(params),
                ids === 'all' ? { decision, scope: 'all' } : { decision, snapshotIds: ids },
                { preserveScroll: true, preserveState: true, only: ['build', 'snapshots'] },
            );
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [build.number],
    );

    const move = useCallback(
        (delta: number) => {
            if (!visible.length) return;
            const i = Math.max(0, visible.findIndex((s) => s.id === current?.id));
            const next = visible[Math.min(visible.length - 1, Math.max(0, i + delta))];
            if (next) setCurrentId(next.id);
        },
        [visible, current],
    );

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement || e.metaKey || e.ctrlKey) return;
            const vm = VIEW_MODES.find((m) => m.hotkey === e.key);
            if (vm) return setMode(vm.key);
            switch (e.key) {
                case 'j':
                case 'ArrowDown':
                    e.preventDefault();
                    return move(1);
                case 'k':
                case 'ArrowUp':
                    e.preventDefault();
                    return move(-1);
                case 'a':
                    if (canReview && current?.review) {
                        decide('accepted', [current.id]);
                        move(1);
                    }
                    return;
                case 'd':
                    if (canReview && current?.review) decide('denied', [current.id]);
                    return;
                case 'u':
                    if (canReview && current?.review && current.review !== 'pending') decide('pending', [current.id]);
                    return;
                case 's':
                    return setSpotlight((v) => !v);
                case 'f':
                    return setZoom((z) => (z === 'fit' ? 1 : 'fit'));
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [move, decide, current, canReview]);

    const pendingCount = snapshots.filter((s) => s.review === 'pending').length;
    const reviewable = ['pending', 'accepted', 'denied', 'broken'].includes(build.status);
    const snapshotComments = comments.filter((c) => c.snapshotId === current?.id);

    return (
        <>
            <Head title={`Build ${build.number} · ${project.name}`} />
            <div className="flex min-h-0 flex-1 flex-col">
                <header className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-4 py-3">
                    <h1 className="text-lg font-semibold">Build {build.number}</h1>
                    <BuildStatusBadge status={build.status} />
                    <span className="flex items-center gap-1 text-sm text-muted-foreground">
                        <GitBranch className="size-4" /> {build.branch}
                    </span>
                    <span className="flex items-center gap-1 font-mono text-sm text-muted-foreground">
                        <GitCommit className="size-4" /> {shortSha(build.commit)}
                    </span>
                    {build.prNumber && (
                        <Link href={showPull({ organization: organization.slug, project: project.slug, number: build.prNumber })} className="flex items-center gap-1 text-sm hover:underline">
                            <GitPullRequest className="size-4" /> #{build.prNumber}
                        </Link>
                    )}
                    <span className="text-sm text-muted-foreground">{timeAgo(build.createdAt)}</span>
                    <div className="ml-auto flex items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <a href={build.storybookUrl} target="_blank" rel="noreferrer">
                                Storybook <ExternalLink />
                            </a>
                        </Button>
                        {canReview && reviewable && pendingCount > 0 && (
                            <>
                                <Button size="sm" variant="outline" onClick={() => decide('denied', 'all')} data-testid="deny-all">
                                    <X /> Deny all
                                </Button>
                                <Button size="sm" onClick={() => decide('accepted', 'all')} data-testid="accept-all">
                                    <Check /> Accept all ({pendingCount})
                                </Button>
                            </>
                        )}
                    </div>
                </header>

                <div className="flex flex-wrap gap-x-6 gap-y-1 border-b px-4 py-2 text-xs text-muted-foreground">
                    <span>
                        {build.counts.snapshots} snapshots · {build.counts.changed} changed · {build.counts.new} new · {build.counts.errors} errors · {build.counts.unchanged} unchanged
                        {build.counts.inconsistent > 0 && ` · ${build.counts.inconsistent} inconsistent`}
                        {build.counts.removed > 0 && ` · ${build.counts.removed} removed`}
                    </span>
                    <span>
                        Baseline:{' '}
                        {build.baselineBuilds.length
                            ? build.baselineBuilds.map((b, i) => (
                                  <span key={b.number}>
                                      {i > 0 && ', '}
                                      <Link className="underline" href={showBuild({ organization: organization.slug, project: project.slug, build: b.number })}>
                                          build {b.number}
                                      </Link>{' '}
                                      ({b.branch})
                                  </span>
                              ))
                            : 'none'}
                        {build.baselineStrategy && build.baselineStrategy !== 'ancestors' && ` · via ${build.baselineStrategy}`}
                    </span>
                    {build.autoAccepted && <span>Auto-accepted ({build.autoAccepted})</span>}
                    {build.captureImage && <span>Captured with {build.captureImage}</span>}
                    {IN_PROGRESS.includes(build.status) && (
                        <span>
                            Capturing… {build.progress.done}/{build.progress.total} shards
                        </span>
                    )}
                </div>
                {build.error && <div className="border-b bg-red-500/10 px-4 py-2 text-sm text-red-700 dark:text-red-300">{build.error}</div>}

                <div className="flex min-h-0 flex-1 flex-col md:flex-row">
                    <aside className="max-h-72 shrink-0 overflow-y-auto border-b md:max-h-none md:w-80 md:border-r md:border-b-0">
                        <div className="sticky top-0 z-10 flex gap-1 border-b bg-background p-2">
                            {(['changes', 'all'] as const).map((f) => (
                                <Button key={f} size="sm" variant={filter === f ? 'secondary' : 'ghost'} onClick={() => setFilter(f)}>
                                    {f === 'changes' ? 'Needs attention' : 'All'}
                                </Button>
                            ))}
                        </div>
                        {GROUPS.map((g) => {
                            const items = visible.filter((s) => g.status.includes(s.status));
                            if (!items.length) return null;

                            return (
                                <div key={g.label}>
                                    <div className="px-3 pt-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        {g.label} ({items.length})
                                    </div>
                                    {items.map((s) => (
                                        <button
                                            key={s.id}
                                            type="button"
                                            data-testid="snapshot-item"
                                            data-snapshot-id={s.id}
                                            onClick={() => setCurrentId(s.id)}
                                            className={cn(
                                                'flex w-full items-center justify-between gap-2 px-3 py-1.5 text-left text-sm hover:bg-accent',
                                                current?.id === s.id && 'bg-accent',
                                            )}
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate text-xs text-muted-foreground">{s.title}</span>
                                                <span className="block truncate">
                                                    {s.name}
                                                    {s.mode !== 'default' && <span className="text-muted-foreground"> · {s.mode}</span>}
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-1">
                                                {s.commentCount > 0 && <MessageSquare className="size-3 text-muted-foreground" />}
                                                <SnapshotStatusBadge status={s.status} review={s.review} />
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            );
                        })}
                        {!visible.length && (
                            <p className="p-4 text-sm text-muted-foreground">
                                {IN_PROGRESS.includes(build.status) ? 'Snapshots will appear as they are captured.' : 'Nothing needs attention. 🎉'}
                            </p>
                        )}
                    </aside>

                    <main className="flex min-w-0 flex-1 flex-col">
                        {current ? (
                            <>
                                <div className="flex flex-wrap items-center gap-2 border-b px-4 py-2">
                                    <div className="mr-auto min-w-0">
                                        <div className="truncate font-medium" data-testid="current-story">
                                            {current.title} / {current.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {current.browser} · {current.mode}
                                            {current.viewport && ` · ${current.viewport.width}×${current.viewport.height}`}
                                            {current.width && ` · ${current.width}×${current.height}px`}
                                            {current.diffRatio != null && ` · ${(current.diffRatio * 100).toFixed(2)}% changed`}
                                            {current.inherited && ' · not captured (inherited)'}
                                        </div>
                                    </div>
                                    <div className="flex rounded-md border p-0.5">
                                        {VIEW_MODES.map((m) => (
                                            <button
                                                key={m.key}
                                                type="button"
                                                title={`${m.label} (${m.hotkey})`}
                                                onClick={() => setMode(m.key)}
                                                className={cn('rounded px-2 py-1 text-xs', mode === m.key ? 'bg-secondary font-medium' : 'text-muted-foreground')}
                                            >
                                                {m.label}
                                            </button>
                                        ))}
                                    </div>
                                    <Button size="sm" variant={showDiff ? 'secondary' : 'ghost'} onClick={() => setShowDiff((v) => !v)} title="Toggle diff overlay">
                                        Highlight
                                    </Button>
                                    <Button size="sm" variant={spotlight ? 'secondary' : 'ghost'} onClick={() => setSpotlight((v) => !v)} title="Spotlight changes (s)">
                                        Spotlight
                                    </Button>
                                    <select
                                        value={String(zoom)}
                                        onChange={(e) => setZoom(e.target.value === 'fit' ? 'fit' : Number(e.target.value))}
                                        className="h-8 rounded-md border bg-background px-2 text-xs"
                                        aria-label="Zoom"
                                    >
                                        <option value="fit">Fit</option>
                                        <option value="0.5">50%</option>
                                        <option value="1">100%</option>
                                        <option value="2">200%</option>
                                        <option value="4">400%</option>
                                    </select>
                                    {canReview && current.review && reviewable && (
                                        <div className="flex gap-1">
                                            {current.review !== 'pending' && (
                                                <Button size="sm" variant="ghost" onClick={() => decide('pending', [current.id])} title="Undo (u)">
                                                    <RotateCcw />
                                                </Button>
                                            )}
                                            <Button
                                                size="sm"
                                                variant={current.review === 'denied' ? 'destructive' : 'outline'}
                                                onClick={() => decide('denied', [current.id])}
                                                title="Deny (d)"
                                                data-testid="deny"
                                            >
                                                <X /> Deny
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant={current.review === 'accepted' ? 'default' : 'outline'}
                                                onClick={() => decide('accepted', [current.id])}
                                                title="Accept (a)"
                                                data-testid="accept"
                                            >
                                                <Check /> Accept
                                            </Button>
                                        </div>
                                    )}
                                </div>
                                <div className="flex-1 overflow-auto bg-muted/30 p-4">
                                    {current.error && current.image && (
                                        <pre className="mb-3 overflow-auto rounded-md bg-red-500/10 p-3 text-xs whitespace-pre-wrap text-red-700 dark:text-red-300">{current.error}</pre>
                                    )}
                                    <SnapshotViewer snapshot={current} mode={mode} zoom={zoom} spotlight={spotlight} showDiff={showDiff} />
                                </div>
                                <div className="grid gap-4 border-t p-4 text-sm lg:grid-cols-2">
                                    <div>
                                        <h3 className="mb-2 font-medium">History</h3>
                                        {current.history.length ? (
                                            <ul className="space-y-1 text-xs text-muted-foreground">
                                                {current.history.map((h, i) => (
                                                    <li key={i}>
                                                        <span className="font-medium text-foreground capitalize">{h.decision}</span> by {h.user ?? h.source} · {timeAgo(h.at)}
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">No review activity.</p>
                                        )}
                                        <p className="mt-3 text-xs text-muted-foreground">
                                            Keys: <kbd>j</kbd>/<kbd>k</kbd> move · <kbd>a</kbd> accept · <kbd>d</kbd> deny · <kbd>u</kbd> undo · <kbd>1</kbd>–<kbd>5</kbd> views · <kbd>s</kbd> spotlight · <kbd>f</kbd> zoom
                                        </p>
                                    </div>
                                    <div>
                                        <h3 className="mb-2 font-medium">Comments</h3>
                                        <ul className="mb-2 space-y-2">
                                            {snapshotComments.map((c) => (
                                                <li key={c.id} className={cn('rounded-md border p-2', c.resolved && 'opacity-60')}>
                                                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                                                        <span>
                                                            {c.user} · {timeAgo(c.createdAt)}
                                                        </span>
                                                        {canReview && (
                                                            <button
                                                                type="button"
                                                                className="underline"
                                                                onClick={() => router.patch(resolveComment.url({ ...params, comment: c.id }), {}, { preserveScroll: true })}
                                                            >
                                                                {c.resolved ? 'Reopen' : 'Resolve'}
                                                            </button>
                                                        )}
                                                    </div>
                                                    <p className="mt-1 whitespace-pre-wrap">{c.body}</p>
                                                    {c.replies.map((r) => (
                                                        <p key={r.id} className="mt-1 border-l-2 pl-2 text-xs whitespace-pre-wrap">
                                                            <span className="text-muted-foreground">{r.user}: </span>
                                                            {r.body}
                                                        </p>
                                                    ))}
                                                </li>
                                            ))}
                                        </ul>
                                        {canReview && (
                                            <Form {...storeComment.form(params)} options={{ preserveScroll: true }} resetOnSuccess className="flex gap-2">
                                                {({ processing }) => (
                                                    <>
                                                        <input type="hidden" name="snapshot_id" value={current.id} />
                                                        <textarea name="body" required rows={1} placeholder="Comment on this snapshot…" className="min-h-9 flex-1 rounded-md border bg-background px-2 py-1.5 text-sm" />
                                                        <Button type="submit" size="sm" variant="secondary" disabled={processing}>
                                                            Post
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        )}
                                    </div>
                                </div>
                            </>
                        ) : (
                            <div className="flex flex-1 items-center justify-center p-8 text-sm text-muted-foreground">
                                {IN_PROGRESS.includes(build.status) ? 'Capturing snapshots…' : 'No snapshots in this build.'}
                            </div>
                        )}
                    </main>
                </div>
            </div>
        </>
    );
}

BuildShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: props.organization.name, href: showOrg(props.organization.slug) },
        { title: props.project.name, href: showProject({ organization: props.organization.slug, project: props.project.slug }) },
        { title: `Build ${props.build.number}`, href: '#' },
    ],
});
