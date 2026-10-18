import { currentProject } from '@/lib/falak';
import { headerItemsFor, shellContext } from '@/lib/registry';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, CircleHelp, Keyboard, Search } from 'lucide-react';
import { Fragment, useMemo } from 'react';
import { IconButton } from './button';
import { openCommandPalette } from './command-palette';
import { EnvironmentSwitcher } from './environment-switcher';
import { Kbd } from './kbd';
import { FalakMark } from './logo';
import { MenuContent, MenuItem, MenuLabel, MenuLink, MenuRoot, MenuTrigger } from './menu';
import { OrgSwitcher } from './org-switcher';
import { ProjectSwitcher } from './project-switcher';
import { PathSeparator } from './switcher-trigger';
import { UserMenu } from './user-menu';

function HelpMenu() {
    return (
        <MenuRoot>
            <MenuTrigger asChild>
                <IconButton label="Help" tooltip={false} icon={<CircleHelp />} className="hidden sm:inline-flex" />
            </MenuTrigger>
            <MenuContent className="w-64">
                <MenuLabel>Keyboard</MenuLabel>
                {[
                    ['Command palette', '⌘ K'],
                    ['Go to projects', 'G P'],
                    ['Go to servers', 'G S'],
                    ['Go to observability', 'G O'],
                    ['Close panel', 'Esc'],
                    ['Previous / next tab', '[ ]'],
                ].map(([label, keys]) => (
                    <MenuItem key={label} icon={<Keyboard />} onSelect={(event) => event.preventDefault()}>
                        <span className="flex items-center justify-between gap-2">
                            {label}
                            <span className="flex gap-0.5">
                                {keys.split(' ').map((key) => (
                                    <Kbd key={key}>{key}</Kbd>
                                ))}
                            </span>
                        </span>
                    </MenuItem>
                ))}
                <MenuLink href="https://falak.sh/docs/" external icon={<BookOpen />}>
                    Documentation
                </MenuLink>
            </MenuContent>
        </MenuRoot>
    );
}

/**
 * [◉ Falak] [Org ▾] / [Project ▾] / [Environment ▾] … [⌘K Search] [🔔] [Help] [Avatar ▾]  (§3)
 * Pages without project context show their breadcrumbs in the path instead.
 */
export function TopBar({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItem[] }) {
    const { props, url } = usePage<SharedData>();
    const ctx = useMemo(() => shellContext(props), [props]);
    const headerItems = props.organization?.current ? headerItemsFor(ctx) : [];
    const inProject = Boolean(props.falak?.projects.length) && (url.startsWith('/projects/') || breadcrumbs.length === 0);
    const home = '/projects';

    return (
        <header className="border-border bg-bg/85 sticky top-0 z-30 flex h-12 shrink-0 items-center gap-1 border-b px-3 backdrop-blur md:px-4">
            <Link href={home} className="mr-1 flex items-center gap-2 rounded-md p-1" aria-label="Falak home">
                <FalakMark />
                <span className="text-fg hidden text-sm font-semibold tracking-tight lg:inline">Falak</span>
            </Link>

            <nav aria-label="Context" className="flex min-w-0 items-center">
                <PathSeparator />
                <OrgSwitcher />
                {inProject ? (
                    <>
                        <span className="hidden items-center md:flex">
                            <PathSeparator />
                            <ProjectSwitcher />
                        </span>
                        {currentProject(props.falak).environment && (
                            <span className="hidden items-center md:flex">
                                <PathSeparator />
                                <EnvironmentSwitcher />
                            </span>
                        )}
                    </>
                ) : (
                    <ol className="hidden min-w-0 items-center md:flex">
                        {breadcrumbs.map((crumb, index) => {
                            const last = index === breadcrumbs.length - 1;

                            return (
                                <Fragment key={`${crumb.href}-${index}`}>
                                    <li className="flex items-center" aria-hidden>
                                        <PathSeparator />
                                    </li>
                                    <li className="min-w-0">
                                        <Link
                                            href={crumb.href}
                                            aria-current={last ? 'page' : undefined}
                                            className={cn(
                                                'hover:bg-surface-2 block max-w-56 truncate rounded-md px-2 py-1 text-sm transition-colors duration-150',
                                                last ? 'text-fg font-medium' : 'text-fg-muted hover:text-fg',
                                            )}
                                        >
                                            {crumb.title}
                                        </Link>
                                    </li>
                                </Fragment>
                            );
                        })}
                    </ol>
                )}
            </nav>

            <div className="ml-auto flex items-center gap-1">
                <button
                    type="button"
                    onClick={openCommandPalette}
                    className="border-border bg-surface-1 text-fg-faint hover:border-border-strong hover:text-fg-muted hidden h-8 w-56 items-center gap-2 rounded-md border px-2.5 text-sm transition-colors duration-150 md:flex"
                >
                    <Search className="size-4" aria-hidden />
                    Search
                    <Kbd className="ml-auto">⌘K</Kbd>
                </button>
                <IconButton label="Search" shortcut="⌘K" icon={<Search />} onClick={openCommandPalette} className="md:hidden" />
                {headerItems.map((item) => (
                    <item.component key={item.id} />
                ))}
                <HelpMenu />
                <div className="ml-1">
                    <UserMenu />
                </div>
            </div>
        </header>
    );
}
