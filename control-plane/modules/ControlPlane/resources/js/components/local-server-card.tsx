import { Section } from '@/components/falak';
import { type ServerSectionProps } from '@/lib/registry';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { currentRequest } from '../types';
import { HostLog, useHostStatus } from './host-log';

interface ServerPageProps {
    server?: { id: string; control_plane_host?: boolean; status?: string };
    agent?: unknown;
}

/**
 * Server page of the control plane's own machine, until its agent enrolls: falak-ctl installing the agent on the host
 * (the `local-server` host request), live.
 */
export function LocalServerCard({ serverId }: ServerSectionProps) {
    const { props } = usePage<SharedData & ServerPageProps>();
    const waiting = props.server?.id === serverId && props.server.control_plane_host === true && !props.agent;
    const { data, down } = useHostStatus(waiting);

    if (!waiting) return null;

    const request = data ? currentRequest(data.channel) : null;

    return (
        <Section
            title="Installing the agent on this machine"
            description="falak-ctl on the control plane's host runs the install command; provisioning starts once the agent enrolls."
        >
            {request && request.action !== 'local-server' ? (
                <p className="text-fg-muted text-sm">The host is busy with another request; this one follows.</p>
            ) : (
                <HostLog data={data} down={down} label="falak-ctl local-server" />
            )}
        </Section>
    );
}
