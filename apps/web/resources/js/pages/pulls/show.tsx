import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ProjectNav, projectBreadcrumbs } from '@/components/tintype/project-nav';
import { BuildStatusBadge, timeAgo } from '@/components/tintype/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { show as showBuild } from '@/routes/builds';
import { assign as assignPull, review as reviewPull } from '@/routes/pulls';
import type { BuildRow, OrgRef, ProjectRef, Role } from '@/types';

interface Change {
    key: string;
    storyId: string;
    title: string;
    name: string;
    browser: string;
    mode: string;
    kind: 'added' | 'removed' | 'changed';
    before: string | null;
    after: string | null;
    afterSnapshotId: number | null;
    reviewState: string | null;
}

interface Props {
    organization: OrgRef;
    project: ProjectRef;
    role: Role;
    pull: {
        number: number;
        title: string | null;
        state: string;
        reviewStatus: string;
        headBranch: string | null;
        baseBranch: string;
        url: string | null;
        author: string | null;
        assignees: { id: number; name: string }[];
    };
    members: { id: number; name: string }[];
    head: BuildRow | null;
    base: BuildRow | null;
    changes: Change[];
    reviews: { id: number; state: string; body: string | null; user: string | null; createdAt: string }[];
}

const STATE_LABEL: Record<string, string> = { approved: 'approved', changes_requested: 'requested changes', commented: 'commented', assigned: 'assigned' };

export default function PullShow({ organization, project, role, pull, members, head, base, changes, reviews }: Props) {
    const [view, setView] = useState<'side' | 'after'>('side');
    const p = { organization: organization.slug, project: project.slug };
    const byComponent = changes.reduce<Record<string, Change[]>>((acc, c) => ((acc[c.title] ??= []).push(c), acc), {});

    return (
        <>
            <Head title={`PR #${pull.number} · ${project.name}`} />
            <ProjectNav organization={organization} project={project} />
            <div className="grid gap-6 p-4 md:p-6 xl:grid-cols-[1fr_320px]">
                <div className="space-y-6">
                    <div className="space-y-1">
                        <h1 className="text-xl font-semibold">
                            #{pull.number} {pull.title}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {pull.headBranch} → {pull.baseBranch}
                            {pull.author && ` · by ${pull.author}`}
                            {pull.url && (
                                <>
                                    {' · '}
                                    <a className="underline" href={pull.url} target="_blank" rel="noreferrer">
                                        GitHub
                                    </a>
                                </>
                            )}
                        </p>
                        <p className="flex flex-wrap items-center gap-2 text-sm">
                            {head ? (
                                <>
                                    Head{' '}
                                    <Link className="underline" href={showBuild({ ...p, build: head.number })}>
                                        build {head.number}
                                    </Link>{' '}
                                    <BuildStatusBadge status={head.status} />
                                </>
                            ) : (
                                'No completed build for this PR yet.'
                            )}
                            {base && (
                                <span className="text-muted-foreground">
                                    compared with {pull.baseBranch} build {base.number}
                                </span>
                            )}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">{changes.length} UI changes</span>
                        <div className="ml-auto flex rounded-md border p-0.5 text-xs">
                            {(['side', 'after'] as const).map((v) => (
                                <button key={v} type="button" onClick={() => setView(v)} className={cn('rounded px-2 py-1', view === v ? 'bg-secondary font-medium' : 'text-muted-foreground')}>
                                    {v === 'side' ? 'Before / after' : 'After only'}
                                </button>
                            ))}
                        </div>
                    </div>
                    {Object.entries(byComponent).map(([title, items]) => (
                        <section key={title} className="space-y-3">
                            <h2 className="font-semibold">{title}</h2>
                            {items.map((c) => (
                                <div key={c.key} className="rounded-xl border">
                                    <div className="flex items-center gap-2 border-b px-3 py-2 text-sm">
                                        <span className="font-medium">{c.name}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {c.mode} · {c.browser}
                                        </span>
                                        <span className="ml-auto text-xs capitalize">{c.kind}</span>
                                    </div>
                                    <div className={cn('grid gap-3 p-3', view === 'side' && 'md:grid-cols-2')}>
                                        {view === 'side' && (
                                            <figure>
                                                <figcaption className="mb-1 text-xs text-muted-foreground">Before</figcaption>
                                                {c.before ? <img src={c.before} alt="before" className="max-w-full border" /> : <div className="text-xs text-muted-foreground">—</div>}
                                            </figure>
                                        )}
                                        <figure>
                                            <figcaption className="mb-1 text-xs text-muted-foreground">After</figcaption>
                                            {c.after ? <img src={c.after} alt="after" className="max-w-full border" /> : <div className="text-xs text-muted-foreground">Removed</div>}
                                        </figure>
                                    </div>
                                </div>
                            ))}
                        </section>
                    ))}
                </div>
                <aside className="space-y-4">
                    <div className="rounded-xl border p-3">
                        <div className="mb-2 text-sm font-medium">
                            Review status: <span className="capitalize">{pull.reviewStatus.replace('_', ' ')}</span>
                        </div>
                        {role !== 'viewer' && (
                            <Form {...reviewPull.form({ ...p, number: pull.number })} resetOnSuccess className="space-y-2">
                                {({ processing }) => (
                                    <>
                                        <textarea name="body" rows={3} placeholder="Leave a comment…" className="w-full rounded-md border bg-background px-2 py-1.5 text-sm" />
                                        <div className="flex flex-wrap gap-2">
                                            <Button size="sm" name="state" value="approved" type="submit" disabled={processing}>
                                                Approve
                                            </Button>
                                            <Button size="sm" variant="outline" name="state" value="changes_requested" type="submit" disabled={processing}>
                                                Request changes
                                            </Button>
                                            <Button size="sm" variant="ghost" name="state" value="commented" type="submit" disabled={processing}>
                                                Comment
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </div>
                    <div className="rounded-xl border p-3 text-sm">
                        <div className="mb-2 font-medium">Reviewers</div>
                        <ul className="mb-2 space-y-1">
                            {pull.assignees.map((a) => (
                                <li key={a.id} className="flex items-center justify-between">
                                    {a.name}
                                    {role !== 'viewer' && (
                                        <button
                                            type="button"
                                            className="text-xs text-muted-foreground underline"
                                            onClick={() => router.post(assignPull.url({ ...p, number: pull.number }), { user_id: a.id, remove: true }, { preserveScroll: true })}
                                        >
                                            remove
                                        </button>
                                    )}
                                </li>
                            ))}
                            {!pull.assignees.length && <li className="text-xs text-muted-foreground">No reviewers assigned.</li>}
                        </ul>
                        {role !== 'viewer' && (
                            <select
                                value=""
                                aria-label="Assign reviewer"
                                className="h-8 w-full rounded-md border bg-background px-2 text-xs"
                                onChange={(e) => e.target.value && router.post(assignPull.url({ ...p, number: pull.number }), { user_id: Number(e.target.value) }, { preserveScroll: true })}
                            >
                                <option value="">Assign a reviewer…</option>
                                {members
                                    .filter((m) => !pull.assignees.some((a) => a.id === m.id))
                                    .map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.name}
                                        </option>
                                    ))}
                            </select>
                        )}
                    </div>
                    <ul className="space-y-2">
                        {reviews.map((r) => (
                            <li key={r.id} className="rounded-xl border p-3 text-sm">
                                <div className="text-xs text-muted-foreground">
                                    <span className="font-medium text-foreground">{r.user}</span> {STATE_LABEL[r.state] ?? r.state} · {timeAgo(r.createdAt)}
                                </div>
                                {r.body && <p className="mt-1 whitespace-pre-wrap">{r.state === 'assigned' ? `→ ${r.body}` : r.body}</p>}
                            </li>
                        ))}
                    </ul>
                </aside>
            </div>
        </>
    );
}

PullShow.layout = (props: Props) => ({
    breadcrumbs: projectBreadcrumbs(props.organization, props.project, [{ title: `PR #${props.pull.number}`, href: '#' }]),
});
