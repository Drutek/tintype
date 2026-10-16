import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { library, settings, show } from '@/routes/projects';
import { index as pulls } from '@/routes/pulls';
import { latest as storybook } from '@/routes/storybook';
import type { OrgRef, ProjectRef } from '@/types';

export function ProjectNav({ organization, project }: { organization: OrgRef; project: ProjectRef }) {
    const { url } = usePage();
    const p = { organization: organization.slug, project: project.slug };
    const items = [
        { label: 'Builds', href: show.url(p), match: (u: string) => /\/projects\/[^/]+(\/builds|\/?$|\?)/.test(u) },
        { label: 'Pull requests', href: pulls.url(p), match: (u: string) => u.includes('/pulls') },
        { label: 'Library', href: library.url(p), match: (u: string) => u.includes('/library') },
        { label: 'Settings', href: settings.url(p), match: (u: string) => u.includes('/settings') },
    ];

    return (
        <nav className="flex items-center gap-1 border-b px-4">
            <span className="mr-3 py-2 font-semibold">{project.name}</span>
            {items.map((i) => (
                <Link
                    key={i.label}
                    href={i.href}
                    className={cn(
                        '-mb-px border-b-2 px-3 py-2 text-sm',
                        i.match(url) ? 'border-foreground font-medium' : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {i.label}
                </Link>
            ))}
            <a href={storybook.url(p)} target="_blank" rel="noreferrer" className="ml-auto px-3 py-2 text-sm text-muted-foreground hover:text-foreground">
                Storybook ↗
            </a>
        </nav>
    );
}

export function projectBreadcrumbs(organization: OrgRef, project: ProjectRef, extra: { title: string; href: string }[] = []) {
    return [
        { title: 'Dashboard', href: '/dashboard' },
        { title: organization.name, href: `/orgs/${organization.slug}` },
        { title: project.name, href: show.url({ organization: organization.slug, project: project.slug }) },
        ...extra,
    ];
}
