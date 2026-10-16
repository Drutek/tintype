import { Form, Head, Link } from '@inertiajs/react';
import { FolderGit2, Plus } from 'lucide-react';
import { BuildStatusBadge, timeAgo } from '@/components/tintype/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { show as showOrg, store as storeOrg } from '@/routes/organizations';
import { show as showProject } from '@/routes/projects';
import type { BuildStatus, Role } from '@/types';

interface Props {
    organizations: {
        id: number;
        name: string;
        slug: string;
        role: Role;
        projects: {
            id: number;
            name: string;
            slug: string;
            repository: string | null;
            latestBuild: { number: number; status: BuildStatus; branch: string; createdAt: string } | null;
        }[];
    }[];
}

export default function Dashboard({ organizations }: Props) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-8 p-4 md:p-6">
                {organizations.length === 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>You're not in an organization yet</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            Ask an administrator to add you, or create one below.
                        </CardContent>
                    </Card>
                )}
                {organizations.map((org) => (
                    <section key={org.id} className="space-y-3">
                        <div className="flex items-center justify-between">
                            <Link href={showOrg(org.slug)} className="text-lg font-semibold hover:underline">
                                {org.name}
                            </Link>
                            {(org.role === 'owner' || org.role === 'admin') && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={showOrg(org.slug)}>
                                        <Plus /> New project
                                    </Link>
                                </Button>
                            )}
                        </div>
                        {org.projects.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No projects yet.</p>
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {org.projects.map((p) => (
                                    <Link
                                        key={p.id}
                                        href={showProject({ organization: org.slug, project: p.slug })}
                                        className="group rounded-xl border p-4 transition-colors hover:bg-accent"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium">{p.name}</span>
                                            {p.latestBuild && <BuildStatusBadge status={p.latestBuild.status} />}
                                        </div>
                                        {p.repository && (
                                            <div className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
                                                <FolderGit2 className="size-3" /> {p.repository}
                                            </div>
                                        )}
                                        <div className="mt-3 text-xs text-muted-foreground">
                                            {p.latestBuild
                                                ? `Build ${p.latestBuild.number} · ${p.latestBuild.branch} · ${timeAgo(p.latestBuild.createdAt)}`
                                                : 'No builds yet'}
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </section>
                ))}
                <Form {...storeOrg.form()} className="flex max-w-md items-center gap-2">
                    {({ processing }) => (
                        <>
                            <Input name="name" placeholder="New organization name" required />
                            <Button type="submit" variant="secondary" disabled={processing}>
                                Create
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
