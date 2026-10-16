import { Head, router, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { timeAgo } from '@/components/tintype/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import { forget as forgetGithub } from '@/routes/admin/github';
import { useState } from 'react';
import { AuditTable, type AuditRow } from '@/components/tintype/audit-table';

interface Props {
    github: {
        configured: boolean;
        name: string | null;
        slug: string | null;
        htmlUrl: string | null;
        installUrl: string | null;
        manifest: string;
        createUrl: string;
        createOrgUrlTemplate: string;
        installations: { installation_id: number; account_login: string; account_type: string | null }[];
        appUrl: string;
    };
    workers: { workerId: string; arch: string | null; image: string | null; browsers: string[] | null; browserVersion: string | null; lastSeenAt: string | null; online: boolean }[];
    queue: { queued: number; leased: number; failed24h: number };
    workerTokenConfigured: boolean;
    audit: AuditRow[];
}

export default function Admin({ github, workers, queue, workerTokenConfigured, audit }: Props) {
    const [ghOrg, setGhOrg] = useState('');
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const action = ghOrg ? github.createOrgUrlTemplate.replace('{org}', encodeURIComponent(ghOrg)) : github.createUrl;

    return (
        <>
            <Head title="Admin" />
            <div className="grid gap-10 p-4 md:p-6 xl:grid-cols-2">
                <section className="space-y-4">
                    <Heading title="GitHub App" description="Posts check runs on commits and pull requests." />
                    {errors.github && <p className="text-sm text-red-600">{errors.github}</p>}
                    {github.configured ? (
                        <div className="space-y-3 text-sm">
                            <p>
                                Connected as <strong>{github.name}</strong>
                                {github.htmlUrl && (
                                    <>
                                        {' '}
                                        (<a className="underline" href={github.htmlUrl} target="_blank" rel="noreferrer">settings</a>)
                                    </>
                                )}
                            </p>
                            {github.installUrl && (
                                <Button asChild size="sm">
                                    <a href={github.installUrl}>Install on repositories</a>
                                </Button>
                            )}
                            <div>
                                <div className="mb-1 font-medium">Installations</div>
                                {github.installations.length ? (
                                    <ul className="list-inside list-disc text-muted-foreground">
                                        {github.installations.map((i) => (
                                            <li key={i.installation_id}>
                                                {i.account_login} ({i.account_type})
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-muted-foreground">None yet.</p>
                                )}
                            </div>
                            <Button variant="outline" size="sm" onClick={() => router.delete(forgetGithub.url())}>
                                Disconnect
                            </Button>
                        </div>
                    ) : (
                        <form method="post" action={action} className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Creates a private GitHub App owned by you or your GitHub organization, pre-configured with webhooks to{' '}
                                <code>{github.appUrl}/github/webhook</code>. The server must be reachable from GitHub.
                            </p>
                            <input type="hidden" name="manifest" value={github.manifest} />
                            <Input value={ghOrg} onChange={(e) => setGhOrg(e.target.value)} placeholder="GitHub organization (optional; blank = your account)" />
                            <Button type="submit">Create GitHub App</Button>
                        </form>
                    )}
                </section>
                <section className="space-y-4">
                    <Heading title="Capture fleet" description="Workers lease capture jobs from this server." />
                    {!workerTokenConfigured && <p className="text-sm text-red-600">TINTYPE_WORKER_TOKEN is not set — workers cannot connect.</p>}
                    <p className="text-sm text-muted-foreground">
                        Queue: {queue.queued} waiting · {queue.leased} running · {queue.failed24h} failed (24h)
                    </p>
                    <div className="divide-y rounded-xl border text-sm">
                        {workers.map((w) => (
                            <div key={w.workerId} className="flex flex-wrap items-center gap-3 p-3">
                                <span className={`size-2 rounded-full ${w.online ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                                <span className="font-medium">{w.workerId}</span>
                                <span className="text-xs text-muted-foreground">
                                    {w.arch} · {w.browsers?.join(', ')} · {w.image ?? 'unknown image'}
                                </span>
                                <span className="ml-auto text-xs text-muted-foreground">{timeAgo(w.lastSeenAt)}</span>
                            </div>
                        ))}
                        {!workers.length && <p className="p-3 text-muted-foreground">No workers have connected yet.</p>}
                    </div>
                </section>
                <section className="space-y-4 xl:col-span-2">
                    <Heading title="Audit log" description="Security-relevant changes across the instance." />
                    <AuditTable rows={audit} showOrganization />
                </section>
            </div>
        </>
    );
}

Admin.layout = { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }, { title: 'Admin', href: '#' }] };
