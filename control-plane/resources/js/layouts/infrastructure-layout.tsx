import { AppShell } from '@/components/falak/app-shell';
import { PageHeader } from '@/components/falak/section';
import { tabTriggerClasses } from '@/components/falak/tabs';
import { shellContext } from '@/lib/registry';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';

export interface AreaSection {
    id: string;
    label: string;
    href: string;
    /** Any one of these permissions shows the section. */
    permissions?: string[];
}

/** Organization-wide infrastructure views (§3 `/servers`): the fleet plus the org-level lists that belong to it. */
const SECTIONS: AreaSection[] = [
    { id: 'servers', label: 'Servers', href: '/servers', permissions: ['servers.view'] },
    { id: 'ssh-keys', label: 'SSH keys', href: '/ssh-keys', permissions: ['servers.view'] },
    {
        id: 'terminal',
        label: 'Terminal sessions',
        href: '/terminal',
        permissions: ['terminal.open', 'terminal.attach', 'terminal.control', 'terminal.recordings.view'],
    },
    { id: 'runs', label: 'Recipe runs', href: '/recipes/runs', permissions: ['recipes.view'] },
    { id: 'security', label: 'Security', href: '/security', permissions: ['security.view'] },
];

interface InfrastructureLayoutProps {
    /** Active section id. */
    section: string;
    /** Browser title; defaults to the section label. */
    title?: string;
    description?: ReactNode;
    /** The page's one primary action. */
    actions?: ReactNode;
    children: ReactNode;
}

/** Shell for /servers, /ssh-keys, /terminal, /recipes/runs and /security: "Infrastructure" header + section tabs. */
export default function InfrastructureLayout(props: InfrastructureLayoutProps) {
    return <AreaLayout heading="Infrastructure" sections={SECTIONS} {...props} />;
}

/** An area of the app: its header, its section tabs (those the user may see) and the active one in the breadcrumbs. */
export function AreaLayout({
    heading,
    sections: all,
    section,
    title,
    description,
    actions,
    children,
}: InfrastructureLayoutProps & { heading: string; sections: AreaSection[] }) {
    const { props } = usePage<SharedData>();
    const sections = useMemo(() => {
        const ctx = shellContext(props);

        return all.filter((item) => !item.permissions || item.permissions.some((permission) => ctx.can(permission)));
    }, [props, all]);
    const current = all.find((item) => item.id === section) ?? all[0];

    return (
        <AppShell
            breadcrumbs={[{ title: heading, href: all[0].href }, ...(current.id === all[0].id ? [] : [{ title: current.label, href: current.href }])]}
        >
            <Head title={title ?? current.label} />
            <div className="grid gap-5">
                <PageHeader title={heading} description={description} actions={actions} />
                <nav aria-label={`${heading} sections`} className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
                    <ul className="border-border flex min-w-max items-center gap-1 border-b">
                        {sections.map((item) => (
                            <li key={item.id}>
                                <Link
                                    href={item.href}
                                    prefetch
                                    aria-current={item.id === current.id ? 'page' : undefined}
                                    className={tabTriggerClasses}
                                >
                                    {item.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
                <div className="grid min-w-0 gap-6">{children}</div>
            </div>
        </AppShell>
    );
}
