import { Form, Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, index, store } from '@/routes/tokens';

type Token = { id: number; name: string; prefix: string; lastUsedAt: string | null; createdAt: string };

export default function Tokens({ tokens, newToken, mcpUrl }: { tokens: Token[]; newToken: string | null; mcpUrl: string }) {
    const config = JSON.stringify(
        { mcpServers: { tintype: { type: 'http', url: mcpUrl, headers: { Authorization: `Bearer ${newToken ?? '<your token>'}` } } } },
        null,
        2,
    );

    return (
        <>
            <Head title="API tokens" />

            <h1 className="sr-only">API tokens</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="API tokens"
                    description="Personal access tokens let MCP clients (Claude Code, Cursor and others) review builds as you, with your role in each organization"
                />

                <Form {...store.form()} options={{ preserveScroll: true }} resetOnSuccess className="flex items-end gap-2">
                    {({ errors, processing }) => (
                        <>
                            <div className="grid flex-1 gap-2">
                                <Label htmlFor="name">Token name</Label>
                                <Input id="name" name="name" required placeholder="e.g. Claude Code on my laptop" />
                                <InputError message={errors.name} />
                            </div>
                            <Button type="submit" disabled={processing}>
                                Create token
                            </Button>
                        </>
                    )}
                </Form>

                {newToken && (
                    <div className="space-y-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-4 text-sm">
                        <p className="font-medium">Copy your token now. It won't be shown again.</p>
                        <code className="block rounded bg-background px-2 py-1 font-mono text-xs break-all" data-testid="new-token">
                            {newToken}
                        </code>
                    </div>
                )}

                <div className="space-y-2 text-sm">
                    <p className="font-medium">Connect an MCP client</p>
                    <p className="text-muted-foreground">
                        Claude Code: <code className="font-mono text-xs">claude mcp add --transport http tintype {mcpUrl} --header "Authorization: Bearer {newToken ?? '<your token>'}"</code>
                    </p>
                    <p className="text-muted-foreground">Other clients (JSON config):</p>
                    <pre className="overflow-x-auto rounded-lg border bg-muted/40 p-3 font-mono text-xs">{config}</pre>
                </div>

                <ul className="divide-y rounded-xl border">
                    {tokens.length === 0 && <li className="p-3 text-sm text-muted-foreground">No tokens yet.</li>}
                    {tokens.map((t) => (
                        <li key={t.id} className="flex items-center justify-between gap-4 p-3 text-sm">
                            <div>
                                <div className="font-medium">{t.name}</div>
                                <div className="text-xs text-muted-foreground">
                                    <code className="font-mono">{t.prefix}_…</code> · created {new Date(t.createdAt).toLocaleDateString()} ·{' '}
                                    {t.lastUsedAt ? `last used ${new Date(t.lastUsedAt).toLocaleString()}` : 'never used'}
                                </div>
                            </div>
                            <Button variant="outline" size="sm" onClick={() => router.delete(destroy.url(t.id), { preserveScroll: true })}>
                                Revoke
                            </Button>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

Tokens.layout = {
    breadcrumbs: [
        {
            title: 'API tokens',
            href: index(),
        },
    ],
};
