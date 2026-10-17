import { Head, router, useForm } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ProjectNav, projectBreadcrumbs } from '@/components/tintype/project-nav';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy as destroyProject, rebaseline, token as rotateToken, update as updateProject } from '@/routes/projects';
import type { OrgRef, ProjectRef, Role } from '@/types';

interface Settings {
    browsers: string[];
    diff_threshold: number;
    include_anti_aliasing: boolean;
    allowed_hosts: string[];
    fixed_time: string;
    device_scale_factor: number;
    viewport_width: number;
    viewport_height: number;
    auto_accept_default_branch: boolean;
    retention_days: number;
    storybook_public: boolean;
    ui_review: boolean;
    a11y: boolean;
    rebaseline_pending: boolean;
    notify_webhook_url: string | null;
    notify_slack_webhook_url: string | null;
    scm: 'github' | 'gitlab' | 'bitbucket';
    scm_repository: string | null;
    scm_url: string | null;
    scm_token_set: boolean;
}

interface Props {
    organization: OrgRef;
    project: ProjectRef & { settings: Settings };
    role: Role;
    newToken: string | null;
    tokenPrefix: string;
    webhookSecret: string | null;
    storyCount: number;
    apiUrl: string;
}

function workflow(apiUrl: string): string {
    return `name: Visual tests
on: [push, pull_request]
jobs:
  tintype:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0 # full history for git-aware baselines
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          registry-url: https://npm.pkg.github.com
          scope: '@drutek'
      - run: npm ci
      - run: npx @drutek/tintype-cli --exit-zero-on-changes
        env:
          NODE_AUTH_TOKEN: \${{ secrets.GITHUB_TOKEN }}
          TINTYPE_SERVER: ${apiUrl}
          TINTYPE_PROJECT_TOKEN: \${{ secrets.TINTYPE_PROJECT_TOKEN }}`;
}

export default function ProjectSettings({ organization, project, role, newToken, tokenPrefix, webhookSecret, apiUrl }: Props) {
    const canManage = role === 'owner' || role === 'admin';
    const p = { organization: organization.slug, project: project.slug };
    const form = useForm({
        name: project.name,
        default_branch: project.defaultBranch,
        github_repository: project.repository ?? '',
        settings: project.settings,
        scm_token: '',
    });
    const s = form.data.settings;
    const set = <K extends keyof Settings>(k: K, v: Settings[K]) => form.setData('settings', { ...s, [k]: v });
    const err = form.errors as Record<string, string>;

    return (
        <>
            <Head title={`Settings · ${project.name}`} />
            <ProjectNav organization={organization} project={project} />
            <div className="grid gap-10 p-4 md:p-6 xl:grid-cols-2">
                <section className="space-y-4">
                    <Heading title="Project token" description="Used by the CLI. Store it as a CI secret." />
                    {newToken ? (
                        <div className="space-y-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
                            <p className="text-sm">Copy this token now — it won't be shown again.</p>
                            <div className="flex gap-2">
                                <code className="flex-1 truncate rounded bg-background px-2 py-1.5 text-sm" data-testid="project-token">
                                    {newToken}
                                </code>
                                <Button size="sm" variant="outline" onClick={() => navigator.clipboard?.writeText(newToken)}>
                                    <Copy /> Copy
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Current token starts with <code>{tokenPrefix}_…</code>
                        </p>
                    )}
                    {canManage && (
                        <Button variant="outline" size="sm" onClick={() => router.post(rotateToken.url(p))}>
                            Generate new token
                        </Button>
                    )}
                    <Heading variant="small" title="GitHub Actions" description="Add .github/workflows/visual-tests.yml:" />
                    <pre className="overflow-auto rounded-lg bg-muted p-3 text-xs">{workflow(apiUrl)}</pre>
                </section>

                <form
                    className="space-y-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(updateProject.url(p), { preserveScroll: true });
                    }}
                >
                    <Heading title="Settings" />
                    <fieldset disabled={!canManage} className="grid gap-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <InputError message={err.name} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="branch">Default branch</Label>
                                <Input id="branch" value={form.data.default_branch} onChange={(e) => form.setData('default_branch', e.target.value)} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="repo">GitHub repository</Label>
                                <Input id="repo" placeholder="owner/repo" value={form.data.github_repository} onChange={(e) => form.setData('github_repository', e.target.value)} />
                                <InputError message={err.github_repository} />
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="scm">Status reporting</Label>
                                <select id="scm" value={s.scm} onChange={(e) => set('scm', e.target.value as Settings['scm'])} className="h-9 rounded-md border bg-background px-2 text-sm">
                                    <option value="github">GitHub (App check runs)</option>
                                    <option value="gitlab">GitLab commit status</option>
                                    <option value="bitbucket">Bitbucket build status</option>
                                </select>
                            </div>
                            {s.scm !== 'github' && (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="scm_repo">{s.scm === 'gitlab' ? 'Project path or id' : 'workspace/repo'}</Label>
                                        <Input id="scm_repo" value={s.scm_repository ?? ''} onChange={(e) => set('scm_repository', e.target.value || null)} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="scm_token">Access token {s.scm_token_set && '(set)'}</Label>
                                        <Input id="scm_token" type="password" placeholder={s.scm_token_set ? '••••••••' : ''} value={form.data.scm_token} onChange={(e) => form.setData('scm_token', e.target.value)} />
                                    </div>
                                    <div className="grid gap-1.5 sm:col-span-3">
                                        <Label htmlFor="scm_url">Self-hosted URL (optional)</Label>
                                        <Input id="scm_url" placeholder={s.scm === 'gitlab' ? 'https://gitlab.example.com' : 'https://api.bitbucket.org'} value={s.scm_url ?? ''} onChange={(e) => set('scm_url', e.target.value || null)} />
                                    </div>
                                </>
                            )}
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Browsers</Label>
                            <div className="flex gap-4 text-sm">
                                {['chromium', 'firefox', 'webkit'].map((b) => (
                                    <label key={b} className="flex items-center gap-1.5">
                                        <input
                                            type="checkbox"
                                            checked={s.browsers.includes(b)}
                                            onChange={(e) => set('browsers', e.target.checked ? [...s.browsers, b] : s.browsers.filter((x) => x !== b))}
                                        />
                                        {b}
                                    </label>
                                ))}
                            </div>
                            <InputError message={err['settings.browsers']} />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="threshold">Diff threshold (0–1)</Label>
                                <Input id="threshold" type="number" step="0.001" min={0} max={1} value={s.diff_threshold} onChange={(e) => set('diff_threshold', Number(e.target.value))} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="vw">Viewport width</Label>
                                <Input id="vw" type="number" value={s.viewport_width} onChange={(e) => set('viewport_width', Number(e.target.value))} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="vh">Viewport height</Label>
                                <Input id="vh" type="number" value={s.viewport_height} onChange={(e) => set('viewport_height', Number(e.target.value))} />
                            </div>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="grid gap-1.5">
                                <Label htmlFor="dsf">Device scale factor</Label>
                                <Input id="dsf" type="number" step="0.5" min={1} max={3} value={s.device_scale_factor} onChange={(e) => set('device_scale_factor', Number(e.target.value))} />
                            </div>
                            <div className="grid gap-1.5 sm:col-span-2">
                                <Label htmlFor="time">Frozen date/time</Label>
                                <Input id="time" value={s.fixed_time} onChange={(e) => set('fixed_time', e.target.value)} />
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="hosts">Allowed external hosts (one per line, *.example.com)</Label>
                            <textarea
                                id="hosts"
                                rows={3}
                                className="rounded-md border bg-background px-3 py-2 text-sm"
                                value={s.allowed_hosts.join('\n')}
                                onChange={(e) => set('allowed_hosts', e.target.value.split('\n').map((h) => h.trim()).filter(Boolean))}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="hook">Webhook URL (build events, JSON)</Label>
                                <Input id="hook" placeholder="https://…" value={s.notify_webhook_url ?? ''} onChange={(e) => set('notify_webhook_url', e.target.value || null)} />
                                <InputError message={err['settings.notify_webhook_url']} />
                                {webhookSecret && (
                                    <p className="text-xs text-muted-foreground">
                                        Signing secret (HMAC-SHA256 in <code>X-Tintype-Signature</code>): <code className="break-all">{webhookSecret}</code>
                                    </p>
                                )}
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="slack">Slack incoming webhook</Label>
                                <Input id="slack" placeholder="https://hooks.slack.com/…" value={s.notify_slack_webhook_url ?? ''} onChange={(e) => set('notify_slack_webhook_url', e.target.value || null)} />
                                <InputError message={err['settings.notify_slack_webhook_url']} />
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="retention">Keep builds for (days)</Label>
                            <Input id="retention" type="number" className="max-w-32" value={s.retention_days} onChange={(e) => set('retention_days', Number(e.target.value))} />
                        </div>
                        {(
                            [
                                ['include_anti_aliasing', 'Count anti-aliased pixels as changes'],
                                ['auto_accept_default_branch', 'Auto-accept changes on the default branch'],
                                ['storybook_public', 'Published Storybooks are public (no login)'],
                                ['ui_review', 'Report a "UI Review" check on pull requests'],
                                ['a11y', 'Run accessibility checks (axe) on every story'],
                            ] as const
                        ).map(([k, label]) => (
                            <label key={k} className="flex items-center gap-2 text-sm">
                                <input type="checkbox" checked={s[k]} onChange={(e) => set(k, e.target.checked)} />
                                {label}
                            </label>
                        ))}
                        {canManage && (
                            <Button type="submit" disabled={form.processing} className="justify-self-start">
                                Save settings
                            </Button>
                        )}
                        {form.recentlySuccessful && <p className="text-sm text-muted-foreground">Saved.</p>}
                    </fieldset>
                    {canManage && (
                        <div className="rounded-lg border p-4">
                            <Heading
                                variant="small"
                                title="Rebaseline"
                                description="After upgrading the capture image, accept the next build on the default branch wholesale so new browser rendering becomes the baseline."
                            />
                            <Button type="button" variant="outline" size="sm" className="mt-3" disabled={s.rebaseline_pending} onClick={() => router.post(rebaseline.url(p))}>
                                {s.rebaseline_pending ? 'Next default-branch build will be accepted' : 'Rebaseline on next build'}
                            </Button>
                        </div>
                    )}
                    {canManage && (
                        <div className="rounded-lg border border-red-500/40 p-4">
                            <Heading variant="small" title="Delete project" description="Removes all builds and snapshots." />
                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                className="mt-3"
                                onClick={() => {
                                    if (window.prompt(`Type ${project.slug} to delete this project`) === project.slug) router.delete(destroyProject.url(p));
                                }}
                            >
                                Delete project
                            </Button>
                        </div>
                    )}
                </form>
            </div>
        </>
    );
}

ProjectSettings.layout = (props: Props) => ({
    breadcrumbs: projectBreadcrumbs(props.organization, props.project, [{ title: 'Settings', href: '#' }]),
});
