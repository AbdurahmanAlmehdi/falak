import { toast, type ManagedConfig } from '@/components/falak';
import { useJson } from '@/hooks/use-json';
import { HttpError, errorMessage, requestJson, type HttpMethod } from '@/lib/http';
import { type ServicePanelContext } from '@/lib/registry';
import { useState } from 'react';
import { type LaravelToggles, type OctaneServer, type SharedPathItem, type SiteOptions, type SiteStatus, type SiteTarget } from '../../types';

/** GET /sites/{site}/settings (JSON). */
export interface SiteSettingsData {
    site: { id: string; name: string; slug: string; status: SiteStatus };
    settings: {
        name: string;
        framework: string;
        framework_label: string;
        is_laravel: boolean;
        runtime: string;
        build_mode: string;
        php_version: string | null;
        node_version: string | null;
        source_connection_id: string | null;
        repository: string | null;
        branch: string | null;
        push_to_deploy: boolean;
        web_directory: string | null;
        app_port: number | null;
        /** Docker sites: the port the app listens on inside its container (app_port is then Falak's loopback host port). */
        container_port: number | null;
        docker_image: string | null;
        dockerfile: string | null;
        compose_file: string | null;
        root_directory: string | null;
        health_check_path: string | null;
        test_domain_enabled: boolean;
        test_domain: string | null;
        unix_user: string;
        isolated: boolean;
        root_path: string;
        document_root: string;
        laravel: LaravelToggles;
        /** Octane servers available for the site's runtime. */
        octane_servers: { value: OctaneServer; label: string }[];
        shared_paths: SharedPathItem[];
        created_at: string;
    };
    source: {
        /** `github_app`: cloned with short-lived installation tokens, no deploy key. */
        connection: { id: string; name: string; provider: string; provider_label: string; github_app: boolean } | null;
        deploy_key: { public_key: string; fingerprint: string; installed: boolean; install_error: string | null } | null;
        error: string | null;
    };
    targets: SiteTarget[];
    options: SiteOptions;
    warnings: string[];
    can: { update: boolean; delete: boolean; run_commands: boolean };
    /** What the project's falak.yaml manages on this site (read-only here), or null. */
    config: ManagedConfig | null;
}

export const settingsUrl = (siteId: string) => `/sites/${siteId}/settings`;

/** One shared request for every Sites settings block of the panel. */
export function useSiteSettings(ctx: ServicePanelContext) {
    return useJson<SiteSettingsData>(settingsUrl(ctx.service.ref_id));
}

/** Save helper: pending state, 422 errors per field, toast, and a reload of the shared settings. */
export function useSave(reload: () => Promise<void>, refresh?: () => void) {
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const save = async (method: HttpMethod, url: string, body: unknown, success: string): Promise<boolean> => {
        setSaving(true);
        setErrors({});
        try {
            const result = await requestJson<{ data?: { warnings?: string[] } } | null>(url, method, body);
            toast.success(success);
            result?.data?.warnings?.forEach((warning) => toast.warning('Source control', warning));
            await reload();
            refresh?.();

            return true;
        } catch (error) {
            if (error instanceof HttpError && Object.keys(error.errors).length > 0) setErrors(error.errors);
            else toast.error('Could not save', errorMessage(error));

            return false;
        } finally {
            setSaving(false);
        }
    };

    return { saving, errors, setErrors, save };
}
