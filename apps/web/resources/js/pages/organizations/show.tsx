import { Form, Head, Link, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { destroy as removeMember, store as addMember } from '@/routes/organizations/members';
import { show as showProject, store as storeProject } from '@/routes/projects';
import type { OrgRef, Role } from '@/types';

interface Props {
    organization: OrgRef;
    role: Role;
    projects: { id: number; name: string; slug: string; github_repository: string | null; default_branch: string }[];
    members: { id: number; name: string; email: string; role: Role }[];
    newPassword: { email: string; password: string } | null;
}

export default function OrganizationShow({ organization, role, projects, members, newPassword }: Props) {
    const canManage = role === 'owner' || role === 'admin';

    return (
        <>
            <Head title={organization.name} />
            <div className="grid flex-1 gap-6 p-4 md:p-6 lg:grid-cols-2">
                <div className="space-y-4">
                    <Heading title="Projects" description="Each project is one Storybook with its own token." />
                    <div className="divide-y rounded-xl border">
                        {projects.map((p) => (
                            <Link
                                key={p.id}
                                href={showProject({ organization: organization.slug, project: p.slug })}
                                className="flex items-center justify-between p-3 hover:bg-accent"
                            >
                                <span className="font-medium">{p.name}</span>
                                <span className="text-xs text-muted-foreground">{p.github_repository ?? p.default_branch}</span>
                            </Link>
                        ))}
                        {projects.length === 0 && <p className="p-3 text-sm text-muted-foreground">No projects yet.</p>}
                    </div>
                    {canManage && (
                        <Card>
                            <CardHeader>
                                <CardTitle>New project</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Form {...storeProject.form(organization.slug)} className="grid gap-3">
                                    {({ errors, processing }) => (
                                        <>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="name">Name</Label>
                                                <Input id="name" name="name" required placeholder="Design system" />
                                                <InputError message={errors.name} />
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="github_repository">GitHub repository (optional)</Label>
                                                <Input id="github_repository" name="github_repository" placeholder="owner/repo" />
                                                <InputError message={errors.github_repository} />
                                            </div>
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="default_branch">Default branch</Label>
                                                <Input id="default_branch" name="default_branch" defaultValue="main" />
                                            </div>
                                            <Button type="submit" disabled={processing} className="justify-self-start">
                                                Create project
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>
                    )}
                </div>
                <div className="space-y-4">
                    <Heading title="Members" description="Reviewers can accept and deny changes; viewers are read-only." />
                    {newPassword && (
                        <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                            Account created for <strong>{newPassword.email}</strong>. One-time password:{' '}
                            <code className="rounded bg-background px-1">{newPassword.password}</code>
                        </div>
                    )}
                    <div className="divide-y rounded-xl border">
                        {members.map((m) => (
                            <div key={m.id} className="flex items-center justify-between gap-2 p-3">
                                <div>
                                    <div className="font-medium">{m.name}</div>
                                    <div className="text-xs text-muted-foreground">{m.email}</div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="text-xs text-muted-foreground capitalize">{m.role}</span>
                                    {canManage && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove ${m.name}`}
                                            onClick={() => router.delete(removeMember.url({ organization: organization.slug, user: m.id }))}
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                    {canManage && (
                        <Form {...addMember.form(organization.slug)} className="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                            {({ errors, processing }) => (
                                <>
                                    <div>
                                        <Input name="email" type="email" placeholder="teammate@example.com" required />
                                        <InputError message={errors.email} />
                                    </div>
                                    <select name="role" defaultValue="reviewer" className="h-9 rounded-md border bg-background px-2 text-sm">
                                        <option value="viewer">Viewer</option>
                                        <option value="reviewer">Reviewer</option>
                                        <option value="admin">Admin</option>
                                        <option value="owner">Owner</option>
                                    </select>
                                    <Button type="submit" disabled={processing}>
                                        Add
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            </div>
        </>
    );
}

OrganizationShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: props.organization.name, href: '#' },
    ],
});
