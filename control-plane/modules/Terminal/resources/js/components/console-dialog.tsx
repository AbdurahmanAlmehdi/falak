import { Button, Dialog, Field, Select, SkeletonRows } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { type ServiceActionDialogProps } from '@/lib/registry';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface ConsoleOptions {
    servers: { id: string; name: string; leader: boolean }[];
    /** Compose sites: the stack's services; null for other runtimes. */
    services: string[] | null;
    runtime: string;
}

/**
 * Header `⋯` → Console on a site (docs/plans/CONSOLE.md): opens a session and goes to its page. Asks first only when
 * there is a real choice — the server when the site runs on several, the service of a compose stack with several.
 */
export function ConsoleDialog({ ctx, open, onOpenChange }: ServiceActionDialogProps) {
    const { data, error } = useJson<ConsoleOptions>(open ? `/sites/${ctx.service.ref_id}/console` : null);
    const [server, setServer] = useState<string | undefined>(undefined);
    const [service, setService] = useState<string | undefined>(undefined);
    const [opening, setOpening] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const autoOpened = useRef(false);

    const servers = data?.servers ?? [];
    const services = data?.services ?? [];
    const chooseServer = servers.length > 1;
    const chooseService = data?.services !== null && data?.services !== undefined && services.length !== 1;
    const hasChoice = chooseServer || chooseService;
    const ready = (!chooseServer || server !== undefined) && (!chooseService || service !== undefined);

    useEffect(() => {
        if (!data) return;
        setServer((current) => current ?? data.servers.find((s) => s.leader)?.id ?? data.servers[0]?.id);
        setService((current) => current ?? (data.services?.length === 1 ? data.services[0] : undefined));
    }, [data]);

    const submit = (serverId?: string, serviceName?: string) => {
        setFailure(null);
        router.post(
            `/sites/${ctx.service.ref_id}/console`,
            { server_id: serverId ?? null, service: serviceName ?? null },
            {
                onStart: () => setOpening(true),
                onFinish: () => setOpening(false),
                onError: (errors) => setFailure(Object.values(errors)[0] ?? 'Could not open a console.'),
            },
        );
    };

    // Nothing to ask: open right away.
    useEffect(() => {
        if (!data || hasChoice || autoOpened.current) return;
        autoOpened.current = true;
        submit(data.servers[0]?.id, data.services?.[0]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, hasChoice]);

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Open a console"
            description={
                data?.services
                    ? 'A shell in one of the stack’s containers, as its image’s user.'
                    : data && ['docker'].includes(data.runtime)
                      ? 'A shell in the site’s running container.'
                      : 'A shell as the site’s user in its current release, with its variables.'
            }
            size="sm"
            footer={
                hasChoice && (
                    <>
                        <Button variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" disabled={!ready} loading={opening} onClick={() => submit(server, service)}>
                            Open console
                        </Button>
                    </>
                )
            }
        >
            {error && <p className="text-danger text-sm">{error}</p>}
            {!data && !error && <SkeletonRows rows={2} />}
            {data && !hasChoice && !failure && <p className="text-fg-muted text-sm">Opening the console…</p>}
            {data && hasChoice && (
                <div className="grid gap-4">
                    {chooseServer && (
                        <Field label="Server">
                            <Select
                                value={server}
                                onValueChange={setServer}
                                options={servers.map((s) => ({ value: s.id, label: s.leader ? `${s.name} (leader)` : s.name }))}
                            />
                        </Field>
                    )}
                    {chooseService && (
                        <Field label="Service" hint={services.length === 0 ? 'The stack has not reported its services yet.' : undefined}>
                            <Select
                                value={service}
                                onValueChange={setService}
                                options={services.map((name) => ({ value: name, label: name }))}
                                placeholder="Pick a service"
                            />
                        </Field>
                    )}
                </div>
            )}
            {failure && <p className="text-danger mt-3 text-sm">{failure}</p>}
        </Dialog>
    );
}
