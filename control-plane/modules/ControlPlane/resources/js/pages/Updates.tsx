import { Button, Callout, CodeBlock, Dialog, KeyValue, RelativeTime, Section, StatusBadge } from '@/components/falak';
import SettingsLayout from '@/layouts/settings/layout';
import { router } from '@inertiajs/react';
import { ArrowUpCircle, ExternalLink, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HostLog, useHostStatus } from '../components/host-log';
import { currentRequest, type HostChannelState, type Release } from '../types';

interface Props {
    current: string | null;
    release: Release | null;
    update_available: boolean;
    check_enabled: boolean;
    channel: HostChannelState;
    commands: { update: string; up: string };
}

/**
 * Settings → Falak updates (the install's owners and admins): the running and the latest release, Check now, and
 * Update now through the host channel, with the update's live log. The panel restarts during the update; the page
 * waits for it (/up) and reloads once the new version answers.
 */
export default function Updates({ current, release, update_available, check_enabled, channel, commands }: Props) {
    const [confirming, setConfirming] = useState(false);
    const [requesting, setRequesting] = useState(false);
    const [checking, setChecking] = useState(false);
    const [following, setFollowing] = useState(() => channel.busy || currentRequest(channel)?.action === 'update');
    const { data, down } = useHostStatus(following);
    const live = data?.channel ?? channel;
    const request = currentRequest(live);
    const reloaded = useRef(false);

    // The new panel answers with another version (or the update ended): show the fresh page once.
    useEffect(() => {
        if (!data || reloaded.current) return;
        const finished = request?.action === 'update' && (request.state === 'succeeded' || request.state === 'failed');
        if (data.current !== current || (finished && channel.busy)) {
            reloaded.current = true;
            router.reload();
        }
    }, [data, request, current, channel.busy]);

    const update = () =>
        router.post(
            '/settings/falak-updates',
            { version: release?.tag ?? '' },
            {
                preserveScroll: true,
                onStart: () => setRequesting(true),
                onFinish: () => setRequesting(false),
                onSuccess: () => {
                    setConfirming(false);
                    setFollowing(true);
                },
            },
        );

    const check = () =>
        router.post(
            '/settings/falak-updates/check',
            {},
            { preserveScroll: true, onStart: () => setChecking(true), onFinish: () => setChecking(false) },
        );

    const blocked = !live.available || live.busy;

    return (
        <SettingsLayout title="Falak updates" description="The release this control plane runs, the latest one on GitHub, and a one-click update.">
            {!live.available &&
                (live.mounted ? (
                    <Callout tone="warning" title="One more step on the host">
                        Run this once on the control-plane machine to enable updates from the panel:
                        <CodeBlock className="mt-2" code={commands.up} />
                    </Callout>
                ) : (
                    <Callout tone="warning" title="Updates from the panel need the host channel">
                        This control plane's host has no host channel yet (falak-ctl v0.23 installs it). Update once from the host; after that the
                        button here works:
                        <CodeBlock className="mt-2" code={commands.update} />
                    </Callout>
                ))}

            {!check_enabled && (
                <Callout tone="info" title="The release check is off">
                    FALAK_UPDATE_CHECK=false: the panel doesn't ask GitHub for new releases.
                </Callout>
            )}

            {release?.error && (
                <Callout tone="warning" title="The last release check failed">
                    {release.error}
                </Callout>
            )}

            <Section
                title="Version"
                aside={
                    check_enabled && (
                        <Button variant="secondary" size="sm" icon={<RefreshCw />} loading={checking} onClick={check}>
                            Check now
                        </Button>
                    )
                }
            >
                <KeyValue
                    items={[
                        { label: 'Running', value: current ?? 'unknown', mono: true },
                        {
                            label: 'Latest release',
                            value: release?.tag ? (
                                <span className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono">{release.tag}</span>
                                    {update_available ? (
                                        <StatusBadge status="waiting" tone="info" label="Update available" />
                                    ) : (
                                        <StatusBadge status="succeeded" label="Up to date" />
                                    )}
                                    {release.published_at && (
                                        <span className="text-fg-muted text-xs">
                                            published <RelativeTime value={release.published_at} />
                                        </span>
                                    )}
                                </span>
                            ) : (
                                'Not checked yet'
                            ),
                        },
                        { label: 'Last check', value: release ? <RelativeTime value={release.checked_at} /> : 'Never' },
                    ]}
                />
                {update_available && (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button variant="primary" icon={<ArrowUpCircle />} disabled={blocked} onClick={() => setConfirming(true)}>
                            Update now to {release?.tag}
                        </Button>
                        {release?.url && (
                            <Button variant="ghost" asChild>
                                <a href={release.url} target="_blank" rel="noreferrer noopener">
                                    <ExternalLink aria-hidden /> Release notes on GitHub
                                </a>
                            </Button>
                        )}
                        {live.available && live.busy && <span className="text-fg-muted text-sm">The host is busy with another request.</span>}
                    </div>
                )}
            </Section>

            {release?.notes && update_available && (
                <Section title={`What's new in ${release.tag}`}>
                    {/* Plain text from GitHub: React escapes it; never rendered as HTML or Markdown. */}
                    <pre className="text-fg-muted max-h-96 overflow-y-auto font-sans text-sm whitespace-pre-wrap">{release.notes}</pre>
                </Section>
            )}

            {(following || request) && (
                <Section
                    title={request?.action === 'local-server' ? 'Last host request: this machine as a server' : 'Update log'}
                    description="As the host writes it (the last 64 KiB)."
                >
                    {following ? (
                        <HostLog data={data} down={down} label="falak-ctl update" />
                    ) : (
                        <Button variant="secondary" size="sm" onClick={() => setFollowing(true)}>
                            Show the log
                        </Button>
                    )}
                </Section>
            )}

            <Dialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`Update Falak to ${release?.tag ?? ''}?`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button variant="primary" loading={requesting} onClick={update}>
                            Update now
                        </Button>
                    </>
                }
            >
                <ul className="text-fg-muted grid list-disc gap-1.5 pl-5 text-sm">
                    <li>falak-ctl takes a backup of the control plane first.</li>
                    <li>The panel restarts: it is unavailable for about 2–5 minutes. Sites, databases and agents keep running.</li>
                    <li>If the new version doesn't come up healthy, it rolls back to {current ?? 'the current version'} on its own.</li>
                </ul>
            </Dialog>
        </SettingsLayout>
    );
}
