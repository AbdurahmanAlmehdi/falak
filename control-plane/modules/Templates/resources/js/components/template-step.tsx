import { toast, useStepBack } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { errorMessage, requestJson } from '@/lib/http';
import { type CreateOptionProps } from '@/lib/registry';
import { type Canvas } from '@/types';
import { useState } from 'react';
import { type Category, type TemplateSummary } from '../types';
import { ConfigureForm } from './configure-form';
import { TemplateGallery } from './template-gallery';

/**
 * Create picker → Template (registered in register.ts): gallery → configure → Deploy. The new card appears where the
 * picker was opened and its panel opens on the deploy stream. Esc / Back from the form returns to the gallery.
 */
export function TemplateStep({ projectId, environmentSlug, position, onCreated }: CreateOptionProps) {
    const gallery = useJson<{ data: TemplateSummary[]; categories: Category[] }>('/templates', { unwrap: false });
    const [picked, setPicked] = useState<TemplateSummary | null>(null);
    useStepBack(picked !== null, () => setPicked(null));

    if (picked) {
        return (
            <ConfigureForm
                compact
                template={picked}
                target={{ projectId, environmentSlug }}
                position={position}
                onDeployed={async (result, warnings) => {
                    warnings.forEach((warning) => toast.warning(warning));
                    try {
                        const canvas = await requestJson<Canvas>(`/projects/${projectId}/${environmentSlug}/canvas`);
                        const card = canvas.services.find((service) => service.kind === 'site' && service.ref_id === result.site_id);
                        toast.success(`${result.name} created`, result.deployment_id ? 'First deploy started.' : undefined);
                        if (card) onCreated(card, result.deployment_id);
                        else if (result.panel_url) window.location.assign(result.panel_url);
                    } catch (error) {
                        toast.error(`${result.name} was created, but the canvas did not refresh`, errorMessage(error));
                    }
                }}
            />
        );
    }

    return (
        <TemplateGallery
            compact
            autoFocus
            templates={gallery.data?.data ?? null}
            categories={gallery.data?.categories ?? []}
            error={gallery.error}
            onPick={setPicked}
        />
    );
}
