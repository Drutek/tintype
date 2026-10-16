import { timeAgo } from '@/components/tintype/status-badge';

export interface AuditRow {
    id: number;
    action: string;
    user: string | null;
    organization?: string | null;
    subject: string | null;
    data: Record<string, unknown> | null;
    ip: string | null;
    at: string;
}

export function AuditTable({ rows, showOrganization = false }: { rows: AuditRow[]; showOrganization?: boolean }) {
    if (!rows.length) return <p className="text-sm text-muted-foreground">No activity yet.</p>;

    return (
        <div className="overflow-x-auto rounded-xl border">
            <table className="w-full text-sm">
                <thead className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                    <tr>
                        <th className="px-3 py-2">When</th>
                        <th className="px-3 py-2">Who</th>
                        <th className="px-3 py-2">Action</th>
                        {showOrganization && <th className="px-3 py-2">Org</th>}
                        <th className="px-3 py-2">Details</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.id} className="border-b last:border-0">
                            <td className="px-3 py-2 text-xs whitespace-nowrap text-muted-foreground" title={r.at}>
                                {timeAgo(r.at)}
                            </td>
                            <td className="px-3 py-2">{r.user ?? 'system'}</td>
                            <td className="px-3 py-2 font-mono text-xs">{r.action}</td>
                            {showOrganization && <td className="px-3 py-2 text-xs">{r.organization}</td>}
                            <td className="px-3 py-2 text-xs text-muted-foreground">
                                {r.subject}
                                {r.data && ` ${JSON.stringify(r.data)}`}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
