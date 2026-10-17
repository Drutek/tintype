import { Head, Link, router } from '@inertiajs/react';
import { GitPullRequest } from 'lucide-react';
import { useEffect } from 'react';
import { ProjectNav, projectBreadcrumbs } from '@/components/tintype/project-nav';
import { BuildStatusBadge, shortSha, timeAgo } from '@/components/tintype/status-badge';
import { Button } from '@/components/ui/button';
import { useLive } from '@/hooks/use-live';
import { show as showBuild } from '@/routes/builds';
import { settings as projectSettings, show as showProject } from '@/routes/projects';
import type { BuildRow, OrgRef, Paginated, ProjectRef, Role } from '@/types';

interface Props {
    organization: OrgRef;
    project: ProjectRef;
    role: Role;
    builds: Paginated<BuildRow>;
    branches: string[];
    filters: { branch: string | null; status: string | null };
}

export default function ProjectShow({ organization, project, builds, branches, filters }: Props) {
    const p = { organization: organization.slug, project: project.slug };
    const live = useLive(`projects.${project.id}`, '.build.updated', () => router.reload({ only: ['builds'] }));
    const active = builds.data.some((b) => ['uploading', 'queued', 'capturing'].includes(b.status));
    useEffect(() => {
        if (!active) return;
        const t = setInterval(() => router.reload({ only: ['builds'] }), live ? 20000 : 4000);

        return () => clearInterval(t);
    }, [active, live]);

    const filter = (key: 'branch' | 'status', value: string) =>
        router.get(showProject.url(p), { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });

    return (
        <>
            <Head title={project.name} />
            <ProjectNav organization={organization} project={project} />
            <div className="space-y-4 p-4 md:p-6">
                <div className="flex flex-wrap gap-2">
                    <select value={filters.branch ?? ''} onChange={(e) => filter('branch', e.target.value)} className="h-9 rounded-md border bg-background px-2 text-sm" aria-label="Branch">
                        <option value="">All branches</option>
                        {branches.map((b) => (
                            <option key={b}>{b}</option>
                        ))}
                    </select>
                    <select value={filters.status ?? ''} onChange={(e) => filter('status', e.target.value)} className="h-9 rounded-md border bg-background px-2 text-sm" aria-label="Status">
                        <option value="">Any status</option>
                        {['pending', 'accepted', 'denied', 'passed', 'broken', 'failed', 'capturing'].map((s) => (
                            <option key={s}>{s}</option>
                        ))}
                    </select>
                </div>
                {builds.data.length === 0 ? (
                    <div className="rounded-xl border p-8 text-center">
                        <p className="font-medium">No builds yet</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Run <code className="rounded bg-muted px-1">npx @drutek/tintype-cli</code> in CI. Your token and setup snippet are in{' '}
                            <Link className="underline" href={projectSettings.url(p)}>
                                settings
                            </Link>
                            .
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2">Build</th>
                                    <th className="px-3 py-2">Status</th>
                                    <th className="px-3 py-2">Branch</th>
                                    <th className="px-3 py-2">Commit</th>
                                    <th className="px-3 py-2">Changes</th>
                                    <th className="px-3 py-2">When</th>
                                </tr>
                            </thead>
                            <tbody>
                                {builds.data.map((b) => (
                                    <tr
                                        key={b.number}
                                        className="cursor-pointer border-b last:border-0 hover:bg-accent"
                                        onClick={() => router.visit(showBuild.url({ ...p, build: b.number }))}
                                    >
                                        <td className="px-3 py-2 font-medium">
                                            <Link href={showBuild.url({ ...p, build: b.number })}>#{b.number}</Link>
                                        </td>
                                        <td className="px-3 py-2">
                                            <BuildStatusBadge status={b.status} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className="flex items-center gap-1">
                                                {b.branch}
                                                {b.prNumber && (
                                                    <span className="flex items-center text-xs text-muted-foreground">
                                                        <GitPullRequest className="size-3" />
                                                        {b.prNumber}
                                                    </span>
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">{shortSha(b.commit)}</td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">
                                            {b.counts.changed} changed · {b.counts.new} new
                                            {b.counts.errors > 0 && <span className="text-red-600"> · {b.counts.errors} errors</span>}
                                            {' · '}
                                            {b.counts.snapshots} total
                                        </td>
                                        <td className="px-3 py-2 text-xs text-muted-foreground">{timeAgo(b.createdAt)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {(builds.prev_page_url || builds.next_page_url) && (
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={!builds.prev_page_url} onClick={() => builds.prev_page_url && router.visit(builds.prev_page_url)}>
                            Newer
                        </Button>
                        <Button variant="outline" size="sm" disabled={!builds.next_page_url} onClick={() => builds.next_page_url && router.visit(builds.next_page_url)}>
                            Older
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

ProjectShow.layout = (props: Props) => ({ breadcrumbs: projectBreadcrumbs(props.organization, props.project) });
