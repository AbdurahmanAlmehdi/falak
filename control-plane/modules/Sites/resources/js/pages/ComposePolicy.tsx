import { Callout } from '@/components/falak/callout';
import { Section } from '@/components/falak/section';
import { Switch } from '@/components/falak/switch';
import { Tag } from '@/components/falak/tag';
import SettingsLayout from '@/layouts/settings/layout';
import { router } from '@inertiajs/react';
import { ShieldAlert, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

interface Props {
    policy: { allow_privileged: boolean; safe_capabilities: string[]; locked: boolean };
    compose_sites: number;
    can: { manage: boolean };
}

const BLOCKED = [
    ['privileged: true', 'Full access to the host kernel and devices'],
    ['network_mode / pid / ipc / userns_mode: host', 'Host namespaces'],
    ['cap_add beyond the default set', 'e.g. NET_ADMIN, SYS_ADMIN, ALL'],
    ['devices', 'Host device mappings'],
    ['security_opt: *:unconfined', 'Disabled seccomp / AppArmor profiles'],
    ['Host bind mounts', 'Absolute paths or paths escaping the release directory'],
    ['/var/run/docker.sock', 'Control of the Docker daemon (root on the host)'],
] as const;

/** Organization settings → Compose: the policy every compose site is rendered with (docs/COMPOSE_TEMPLATES.md §1.3). */
export default function ComposePolicy({ policy, compose_sites, can }: Props) {
    const [saving, setSaving] = useState(false);

    const toggle = (allow: boolean) => {
        setSaving(true);
        router.put('/settings/compose', { allow_privileged: allow }, { preserveScroll: true, onFinish: () => setSaving(false) });
    };

    return (
        <SettingsLayout title="Compose" description="What Docker Compose files of this organization may do on your servers.">
            <Section
                title="Compose policy"
                description={`Applies to ${compose_sites} compose ${compose_sites === 1 ? 'site' : 'sites'} and to templates deployed in this organization.`}
            >
                <div className="flex items-start justify-between gap-4">
                    <div className="grid gap-1">
                        <label htmlFor="allow-privileged" className="text-fg flex items-center gap-2 text-sm font-medium">
                            {policy.allow_privileged ? (
                                <ShieldAlert className="text-warning size-4" aria-hidden />
                            ) : (
                                <ShieldCheck className="text-success size-4" aria-hidden />
                            )}
                            Allow privileged compose
                        </label>
                        <p className="text-fg-muted text-xs">
                            Off by default. When on, the checks below are skipped — a compose file can then take over the servers it runs on.
                        </p>
                    </div>
                    <Switch
                        id="allow-privileged"
                        checked={policy.allow_privileged}
                        disabled={!can.manage || saving || policy.locked}
                        onCheckedChange={(checked) => toggle(checked === true)}
                    />
                </div>
                {policy.allow_privileged && (
                    <Callout tone="warning">Privileged compose files are allowed. Only enable this for stacks you trust.</Callout>
                )}
                {policy.locked && <p className="text-fg-faint text-xs">Privileged compose files can't be allowed on this Falak.</p>}
                {!can.manage && <p className="text-fg-faint text-xs">Only organization admins can change the compose policy.</p>}
            </Section>

            <Section
                title={policy.allow_privileged ? 'Checks (currently skipped)' : 'Blocked'}
                description="Named volumes and relative bind mounts inside the release directory are always allowed."
            >
                <ul className="divide-border -my-2 divide-y">
                    {BLOCKED.map(([rule, why]) => (
                        <li key={rule} className="flex flex-wrap items-center justify-between gap-2 py-2">
                            <span className="text-fg font-mono text-xs">{rule}</span>
                            <span className="text-fg-muted text-xs">{why}</span>
                        </li>
                    ))}
                </ul>
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-fg-faint text-xs">Allowed capabilities:</span>
                    {policy.safe_capabilities.map((capability) => (
                        <Tag key={capability} mono>
                            {capability}
                        </Tag>
                    ))}
                </div>
            </Section>
        </SettingsLayout>
    );
}
