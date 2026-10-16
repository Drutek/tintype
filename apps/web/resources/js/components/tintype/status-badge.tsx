import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { BuildStatus, ReviewState, SnapshotStatus } from '@/types';

const BUILD: Record<BuildStatus, { label: string; className: string }> = {
    uploading: { label: 'Uploading', className: 'bg-slate-500/15 text-slate-700 dark:text-slate-300' },
    queued: { label: 'Queued', className: 'bg-slate-500/15 text-slate-700 dark:text-slate-300' },
    capturing: { label: 'Capturing', className: 'bg-blue-500/15 text-blue-700 dark:text-blue-300' },
    pending: { label: 'Pending review', className: 'bg-amber-500/15 text-amber-700 dark:text-amber-300' },
    accepted: { label: 'Accepted', className: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' },
    denied: { label: 'Denied', className: 'bg-red-500/15 text-red-700 dark:text-red-300' },
    passed: { label: 'Passed', className: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' },
    broken: { label: 'Errors', className: 'bg-red-500/15 text-red-700 dark:text-red-300' },
    failed: { label: 'Failed', className: 'bg-red-500/15 text-red-700 dark:text-red-300' },
    canceled: { label: 'Canceled', className: 'bg-slate-500/15 text-slate-600 dark:text-slate-400' },
};

const SNAPSHOT: Record<SnapshotStatus, { label: string; className: string }> = {
    changed: { label: 'Changed', className: 'bg-amber-500/15 text-amber-700 dark:text-amber-300' },
    new: { label: 'New', className: 'bg-sky-500/15 text-sky-700 dark:text-sky-300' },
    unchanged: { label: 'Unchanged', className: 'bg-slate-500/10 text-slate-600 dark:text-slate-400' },
    error: { label: 'Error', className: 'bg-red-500/15 text-red-700 dark:text-red-300' },
    skipped: { label: 'Skipped', className: 'bg-slate-500/10 text-slate-500' },
    removed: { label: 'Removed', className: 'bg-slate-500/10 text-slate-500 line-through' },
    inconsistent: { label: 'Inconsistent', className: 'bg-purple-500/15 text-purple-700 dark:text-purple-300' },
};

export function BuildStatusBadge({ status, className }: { status: BuildStatus; className?: string }) {
    const s = BUILD[status];

    return (
        <Badge variant="outline" className={cn('border-transparent', s.className, className)} data-status={status}>
            {status === 'capturing' && <span className="size-1.5 animate-pulse rounded-full bg-current" />}
            {s.label}
        </Badge>
    );
}

export function SnapshotStatusBadge({ status, review }: { status: SnapshotStatus; review?: ReviewState }) {
    if (review === 'accepted') {
        return (
            <Badge variant="outline" className="border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-300">
                Accepted
            </Badge>
        );
    }
    if (review === 'denied') {
        return (
            <Badge variant="outline" className="border-transparent bg-red-500/15 text-red-700 dark:text-red-300">
                Denied
            </Badge>
        );
    }
    const s = SNAPSHOT[status];

    return (
        <Badge variant="outline" className={cn('border-transparent', s.className)}>
            {s.label}
        </Badge>
    );
}

export function shortSha(sha: string): string {
    return sha.slice(0, 7);
}

export function timeAgo(iso: string | null): string {
    if (!iso) return '';
    const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)}m ago`;
    if (s < 86400) return `${Math.floor(s / 3600)}h ago`;

    return `${Math.floor(s / 86400)}d ago`;
}
