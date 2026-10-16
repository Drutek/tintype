import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { ProjectNav, projectBreadcrumbs } from '@/components/tintype/project-nav';
import { BuildStatusBadge, shortSha } from '@/components/tintype/status-badge';
import { Input } from '@/components/ui/input';
import { library as libraryRoute } from '@/routes/projects';
import type { BuildStatus, OrgRef, ProjectRef } from '@/types';

interface LibraryStory {
    storyId: string;
    name: string;
    mode: string;
    browser: string;
    image: string;
    width: number | null;
    height: number | null;
}

interface Props {
    organization: OrgRef;
    project: ProjectRef;
    branch: string;
    build: { number: number; status: BuildStatus; commit: string } | null;
    components: { title: string; stories: LibraryStory[] }[];
    branches: string[];
}

export default function Library({ organization, project, branch, build, components, branches }: Props) {
    const [q, setQ] = useState('');
    const shown = components
        .map((c) => ({ ...c, stories: c.stories.filter((s) => `${c.title} ${s.name}`.toLowerCase().includes(q.toLowerCase())) }))
        .filter((c) => c.stories.length);

    return (
        <>
            <Head title={`Library · ${project.name}`} />
            <ProjectNav organization={organization} project={project} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center gap-3">
                    <select
                        value={branch}
                        onChange={(e) => router.get(libraryRoute.url({ organization: organization.slug, project: project.slug }), { branch: e.target.value })}
                        className="h-9 rounded-md border bg-background px-2 text-sm"
                        aria-label="Branch"
                    >
                        {[...new Set([branch, ...branches])].map((b) => (
                            <option key={b}>{b}</option>
                        ))}
                    </select>
                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Filter components…" className="max-w-xs" />
                    {build && (
                        <span className="flex items-center gap-2 text-sm text-muted-foreground">
                            Approved snapshots as of build {build.number} ({shortSha(build.commit)}) <BuildStatusBadge status={build.status} />
                        </span>
                    )}
                </div>
                {!build && <p className="text-sm text-muted-foreground">No completed builds on {branch} yet.</p>}
                {shown.map((c) => (
                    <section key={c.title}>
                        <h2 className="mb-3 font-semibold">{c.title}</h2>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            {c.stories.map((s) => (
                                <figure key={`${s.storyId}-${s.mode}-${s.browser}`} className="overflow-hidden rounded-xl border">
                                    <div className="flex h-48 items-center justify-center overflow-hidden bg-muted/30 p-2">
                                        <img src={s.image} alt={s.name} loading="lazy" className="max-h-full max-w-full object-contain" />
                                    </div>
                                    <figcaption className="flex justify-between border-t px-3 py-2 text-sm">
                                        <span>{s.name}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {s.mode !== 'default' ? `${s.mode} · ` : ''}
                                            {s.browser}
                                        </span>
                                    </figcaption>
                                </figure>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}

Library.layout = (props: Props) => ({ breadcrumbs: projectBreadcrumbs(props.organization, props.project, [{ title: 'Library', href: '#' }]) });
