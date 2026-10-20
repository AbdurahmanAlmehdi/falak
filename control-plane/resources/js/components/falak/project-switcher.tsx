import { currentProject, defaultEnvironment, projectUrl } from '@/lib/falak';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Check, FolderKanban, LayoutGrid } from 'lucide-react';
import { MenuContent, MenuLabel, MenuLink, MenuRoot, MenuSeparator, MenuTrigger } from './menu';
import { ServiceIcon } from './service-icon';
import { SwitcherTrigger } from './switcher-trigger';

function ProjectIcon({ icon }: { icon: string | null }) {
    const folder = <FolderKanban className="text-fg-muted size-3.5" aria-hidden />;

    return icon ? <ServiceIcon name={icon} size={14} fallback={folder} /> : folder;
}

/** Project switcher from the `falak` shared prop (§9); renders nothing until the Projects module provides it. */
export function ProjectSwitcher() {
    const { falak } = usePage<SharedData>().props;
    if (!falak || falak.projects.length === 0) return null;

    const { project } = currentProject(falak);

    return (
        <MenuRoot>
            <MenuTrigger asChild>
                <SwitcherTrigger
                    aria-label={`Project: ${project?.name ?? 'none selected'}`}
                    icon={project ? <ProjectIcon icon={project.icon} /> : <FolderKanban className="text-fg-muted size-3.5" aria-hidden />}
                    label={project?.name ?? 'Select project'}
                />
            </MenuTrigger>
            <MenuContent align="start" className="w-64">
                <MenuLabel>Projects</MenuLabel>
                {falak.projects.map((item) => (
                    <MenuLink key={item.id} href={projectUrl(item, defaultEnvironment(item))} icon={<ProjectIcon icon={item.icon} />}>
                        <span className="flex items-center gap-2">
                            {item.name}
                            {item.id === project?.id && <Check className="text-primary ml-auto size-4" aria-label="Current" />}
                        </span>
                    </MenuLink>
                ))}
                <MenuSeparator />
                <MenuLink href="/projects" icon={<LayoutGrid />}>
                    All projects
                </MenuLink>
            </MenuContent>
        </MenuRoot>
    );
}
