import { ManagedBadge, PanelHeader, SkeletonRows, StatusBadge, Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { type ServiceLayerProps } from '@/lib/registry';
import { HardDrive, Lock } from 'lucide-react';
import { useEffect, useState } from 'react';
import { VolumeReload } from '../components/api';
import { VolumeDetail, volumeBusy, type VolumeProps, type VolumeTab } from '../pages/Show';
import { kindLabel, volumeStatus } from '../types';

/**
 * A volume opened from a canvas card (`?volume={id}` over its service's panel): the volume page's content in tabs,
 * with the canvas still in view. /volumes/{id} stays the full page (deep links, ⌘-click on the chip).
 */
export function VolumeLayer({ ctx, record, tab, onTabChange, close }: ServiceLayerProps) {
    const [fast, setFast] = useState(false);
    const state = useJson<VolumeProps>(`/volumes/${record}`, { interval: fast ? 3_000 : 60_000 });
    const data = state.data;

    useEffect(() => setFast(data ? volumeBusy(data) : false), [data]);

    const tabs: { id: VolumeTab; label: string }[] = [
        { id: 'overview', label: 'Overview' },
        { id: 'backups', label: 'Backups' },
        ...(data?.can.browse && data.browsable ? [{ id: 'files' as const, label: 'Files' }] : []),
    ];
    const active = tabs.find((item) => item.id === tab)?.id ?? 'overview';
    const volume = data?.volume;

    return (
        <div className="flex min-h-0 flex-1 flex-col" data-testid="volume-panel">
            <PanelHeader
                size="md"
                icon={<HardDrive className="size-4" aria-hidden />}
                parent={ctx.service.name}
                title={
                    <span className="flex items-center gap-2">
                        <span className="font-mono text-[0.95em] font-medium">{volume?.name ?? 'Volume'}</span>
                        {volume?.protected && <Lock className="text-fg-faint size-3.5" aria-label="Protected" />}
                        {volume && <ManagedBadge config={volume.managed} />}
                    </span>
                }
                status={
                    volume && (
                        <StatusBadge
                            status={volumeStatus(volume)}
                            label={volume.status_message && volume.status === 'active' ? 'Attention' : undefined}
                        />
                    )
                }
                subtitle={volume && `${kindLabel(volume.kind)} · ${volume.server?.name ?? 'every server of its site'}`}
                onClose={close}
            />
            <Tabs value={active} onValueChange={(value) => onTabChange(value)} className="flex min-h-0 flex-1 flex-col">
                <TabsList className="gap-4 px-5 sm:px-7" aria-label="Volume views">
                    {tabs.map((item) => (
                        <TabsTrigger key={item.id} value={item.id} className="px-0.5">
                            {item.label}
                        </TabsTrigger>
                    ))}
                </TabsList>
                {state.error && !data ? (
                    <p className="text-danger px-7 py-5 text-sm">{state.error}</p>
                ) : !data ? (
                    <div className="px-7 py-5">
                        <SkeletonRows rows={8} />
                    </div>
                ) : (
                    <VolumeReload.Provider value={() => void state.reload()}>
                        {tabs.map((item) => (
                            <TabsContent key={item.id} value={item.id} className="min-h-0 flex-1 overflow-y-auto px-5 pt-5 pb-8 sm:px-7">
                                <VolumeDetail
                                    {...data}
                                    tab={item.id}
                                    onDeleted={() => {
                                        close();
                                        ctx.refresh();
                                    }}
                                />
                            </TabsContent>
                        ))}
                    </VolumeReload.Provider>
                )}
            </Tabs>
        </div>
    );
}
