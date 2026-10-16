import { Head, Link } from '@inertiajs/react';
import { ProjectNav, projectBreadcrumbs } from '@/components/tintype/project-nav';
import { BuildStatusBadge } from '@/components/tintype/status-badge';
import { show as showPull } from '@/routes/pulls';
import type { BuildRow, OrgRef, ProjectRef } from '@/types';

interface Props {
    organization: OrgRef;
    project: ProjectRef;
    pulls: {
        number: number;
        title: string | null;
        state: string;
        reviewStatus: string;
        headBranch: string | null;
        baseBranch: string;
        author: string | null;
        latestBuild: BuildRow | null;
    }[];
}

const REVIEW: Record<string, string> = { open: 'Needs review', approved: 'Approved', changes_requested: 'Changes requested' };

export default function PullsIndex({ organization, project, pulls }: Props) {
    return (
        <>
            <Head title={`Pull requests · ${project.name}`} />
            <ProjectNav organization={organization} project={project} />
            <div className="p-4 md:p-6">
                {pulls.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No pull requests yet. Builds from PRs (or PR webhooks from the GitHub App) appear here.</p>
                ) : (
                    <div className="divide-y rounded-xl border">
                        {pulls.map((pr) => (
                            <Link
                                key={pr.number}
                                href={showPull({ organization: organization.slug, project: project.slug, number: pr.number })}
                                className="flex flex-wrap items-center gap-3 p-3 hover:bg-accent"
                            >
                                <span className="font-medium">#{pr.number}</span>
                                <span className="min-w-0 flex-1 truncate">{pr.title ?? pr.headBranch}</span>
                                <span className="text-xs text-muted-foreground">
                                    {pr.headBranch} → {pr.baseBranch}
                                </span>
                                <span className="text-xs">{REVIEW[pr.reviewStatus] ?? pr.reviewStatus}</span>
                                {pr.latestBuild && <BuildStatusBadge status={pr.latestBuild.status} />}
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

PullsIndex.layout = (props: Props) => ({ breadcrumbs: projectBreadcrumbs(props.organization, props.project, [{ title: 'Pull requests', href: '#' }]) });
