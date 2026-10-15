/**
 * Every route the browser smoke test visits (docs/UI_DESIGN.md §8). Extend this list when you add pages.
 *
 * - `path` may contain `:param` placeholders; each param is resolved once per run by opening `params[name].from`
 *   and taking the first link whose pathname matches `params[name].match` (capture group 1 = the value).
 *   Routes whose params can't be resolved (empty list) are skipped, not failed.
 * - `allowedFailures` lists request URL patterns that may legitimately return 4xx/5xx on that page
 *   (e.g. an observability backend that isn't running locally).
 * - `guest: true` visits the page logged out.
 */
export interface BrowserRoute {
    path: string;
    guest?: boolean;
    allowedFailures?: RegExp[];
    /** Extra console messages to ignore on this page. */
    allowedConsole?: RegExp[];
}

export interface ParamSource {
    /** Page to scrape; may contain other `:params`. */
    from: string;
    match: RegExp;
    /** Only anchors whose text matches (e.g. a specific demo service). */
    text?: RegExp;
}

const ULID = '([0-9A-Za-z]{26})';

export const params: Record<string, ParamSource> = {
    // The demo Storefront site (richest panel), found through the services list of the first server.
    site: { from: '/servers/:server', match: new RegExp(`^/projects/[0-9a-z]{26}/production/service/site/${ULID}$`, 'i'), text: /Storefront/ },
    issue: { from: '/observability/issues?status=all', match: new RegExp(`^/observability/issues/${ULID}$`) },
    exception: { from: '/observability/issues?kind=exception&sort=occurrences', match: new RegExp(`^/observability/issues/${ULID}$`) },
    trace: { from: '/observability/traces', match: /^\/observability\/traces\/([0-9a-f]{16,32})$/ },
    server: { from: '/servers', match: new RegExp(`^/servers/${ULID}$`) },
    // infrastructure: servers in other lifecycle states (the fleet table honours ?status=), runs, recordings, networks
    waitingServer: { from: '/servers?status=creating', match: new RegExp(`^/servers/${ULID}$`) },
    provisioningServer: { from: '/servers?status=provisioning', match: new RegExp(`^/servers/${ULID}$`) },
    failedServer: { from: '/servers?status=error', match: new RegExp(`^/servers/${ULID}$`) },
    attentionServer: { from: '/servers?status=needs_attention', match: new RegExp(`^/servers/${ULID}$`) },
    recipeRun: { from: '/recipes/runs', match: new RegExp(`^/recipes/runs/${ULID}$`) },
    recording: { from: '/terminal', match: new RegExp(`^/terminal/sessions/${ULID}/recording$`) },
    privateNetwork: { from: '/network', match: new RegExp(`^/network/private-networks/${ULID}$`) },
    // compose: the demo "Automations" compose site (UiDemoSeeder)
    composeSite: {
        from: '/servers/:server',
        match: new RegExp(`^/projects/[0-9a-z]{26}/production/service/site/${ULID}$`, 'i'),
        text: /Automations/,
    },
    // canvas
    // The demo "Default" project (the starred "Content" project is listed first).
    project: { from: '/projects', match: new RegExp(`^/projects/${ULID}/production$`), text: /^Default$/ },
    canvas: { from: '/projects', match: new RegExp(`^/projects/(${ULID.slice(1, -1)}/production)$`), text: /^Default$/ },
    contentCanvas: { from: '/projects', match: new RegExp(`^/projects/(${ULID.slice(1, -1)}/production)$`), text: /^Content$/ },
};

/** Requests allowed to fail on every page (optional backends in local/sim setups). */
export const globalAllowedFailures: RegExp[] = [/\/broadcasting\/auth/, /\/favicon\.ico$/];

/** Console noise allowed everywhere (realtime transport retries when Reverb isn't running). */
export const globalAllowedConsole: RegExp[] = [/WebSocket connection to .* failed/i, /pusher/i];

const observability = [/\/telemetry\/.*\/data/, /\/telemetry\/.*search/, /\/insights\/.*\/data/, /\/telemetry\/sites\/[^/?]+(\?|$)/];

export const routes: BrowserRoute[] = [
    // Auth (guest)
    { path: '/login', guest: true },
    { path: '/register', guest: true },
    { path: '/forgot-password', guest: true },
    { path: '/', guest: true },

    // Home + shell
    { path: '/dashboard' },
    { path: '/organizations/create' },
    { path: '/notifications' },

    // Settings shell
    { path: '/settings/profile' },
    { path: '/settings/password' },
    { path: '/settings/two-factor' },
    { path: '/settings/appearance' },
    { path: '/settings/api-tokens' },
    { path: '/settings/organization' },
    { path: '/settings/members' },
    { path: '/settings/teams' },
    { path: '/settings/audit-log' },

    // Infrastructure
    { path: '/servers' },
    // The demo cloud credentials are fake: the provider catalog endpoints answer 502 locally.
    { path: '/servers/create', allowedFailures: [/\/providers\/[^/]+\/(regions|sizes|images)/] },
    { path: '/servers/:server' },
    { path: '/telemetry/servers/:server/metrics', allowedFailures: observability },
    { path: '/ssh-keys' },
    { path: '/providers' },
    { path: '/network' },
    { path: '/network/servers/:server/firewall' },
    { path: '/security' },
    { path: '/terminal' },
    { path: '/recipes' },
    { path: '/recipes/runs' },

    // Legacy site URLs redirect into the canvas panel (§3)
    { path: '/sites/:site' },
    { path: '/sites/:site/environment' },
    { path: '/sites/:site/domains' },

    // Data, builds, source control
    { path: '/databases' },
    { path: '/databases/backups' },
    { path: '/databases/storage' },
    { path: '/builds' },
    { path: '/builds/builders' },
    { path: '/source-control' },

    // Observability
    { path: '/insights', allowedFailures: observability },
    { path: '/insights/issues' },
    { path: '/insights/heartbeats' },
    { path: '/telemetry/logs', allowedFailures: observability },
    { path: '/telemetry/traces', allowedFailures: observability },
    { path: '/telemetry/settings' },
    { path: '/alerting/rules' },
    { path: '/alerting/channels' },
    { path: '/alerting/history' },

    // infrastructure
    { path: '/servers/:server/metrics', allowedFailures: observability },
    { path: '/servers/:server/processes' },
    { path: '/servers/:server/firewall' },
    { path: '/servers/:server/network' },
    { path: '/servers/:server/private-network' },
    { path: '/servers/:server/security' },
    { path: '/servers/:server/terminal' },
    { path: '/servers/:server/ssh-keys' },
    { path: '/servers/:server/recipes' },
    { path: '/servers/:server/php' },
    { path: '/servers/:server/settings' },
    { path: '/servers/:waitingServer' },
    { path: '/servers/:provisioningServer' },
    { path: '/servers/:failedServer' },
    { path: '/servers/:attentionServer' },
    { path: '/servers/:waitingServer/metrics', allowedFailures: observability },
    { path: '/servers/create?provider=custom' },
    { path: '/network/private-networks/:privateNetwork' },
    { path: '/recipes/runs/:recipeRun' },
    { path: '/recipes/builtin/disk-usage/run' },
    { path: '/terminal/sessions/:recording/recording' },

    // settings — organization settings sections (the legacy URLs above now 301 here)
    { path: '/settings/source-control' },
    { path: '/settings/cloud-providers' },
    { path: '/settings/storage' },
    { path: '/settings/builders' },
    { path: '/settings/alert-channels' },
    { path: '/settings/alert-rules' },
    { path: '/settings/observability' },
    { path: '/settings/recipes' },
    { path: '/invitations/not-a-real-token' },

    // observability
    { path: '/observability', allowedFailures: observability },
    { path: '/observability?range=7d', allowedFailures: observability },
    { path: '/observability/issues' },
    { path: '/observability/issues?status=all' },
    { path: '/observability/issues/:issue', allowedFailures: observability },
    { path: '/observability/issues/:exception', allowedFailures: observability },
    { path: '/observability/traces', allowedFailures: observability },
    { path: '/observability/traces/:trace', allowedFailures: observability },
    { path: '/observability/logs', allowedFailures: observability },
    { path: '/observability/heartbeats' },
    { path: '/observability/alerts' },
    { path: '/insights/sites/:site/settings' },
    // canvas
    { path: '/projects' },
    { path: '/projects/:canvas' },
    { path: '/projects/:contentCanvas' },
    { path: '/projects/:project/settings' },

    // panel — every service panel tab of a site, and deep links into the Settings sections
    { path: '/projects/:canvas/service/site/:site/deployments' },
    { path: '/projects/:canvas/service/site/:site/variables' },
    { path: '/projects/:canvas/service/site/:site/metrics', allowedFailures: observability },
    { path: '/projects/:canvas/service/site/:site/logs', allowedFailures: observability },
    { path: '/projects/:canvas/service/site/:site/observability', allowedFailures: observability },
    { path: '/projects/:canvas/service/site/:site/processes', allowedFailures: observability },
    { path: '/projects/:canvas/service/site/:site/settings' },
    { path: '/projects/:canvas/service/site/:site/settings/deploy' },
    { path: '/projects/:canvas/service/site/:site/settings/networking' },
    { path: '/projects/:canvas/service/site/:site/settings/servers' },
    { path: '/projects/:canvas/service/site/:site/settings/laravel' },
    { path: '/projects/:canvas/service/site/:site/settings/commands' },

    // compose — Services tab, Settings → Compose section, logs of one compose service, organization compose policy
    { path: '/projects/:canvas/service/site/:composeSite' },
    { path: '/projects/:canvas/service/site/:composeSite/services' },
    { path: '/projects/:canvas/service/site/:composeSite/settings/compose' },
    { path: '/projects/:canvas/service/site/:composeSite/logs/redis', allowedFailures: observability },
    { path: '/settings/compose' },
    { path: '/settings/domains' },

    // templates
    { path: '/templates' },
    { path: '/settings/templates' },
];

export function slugFor(path: string): string {
    return (
        path
            .replace(/^\//, '')
            .replace(/[/:]+/g, '-')
            .replace(/[^a-z0-9-]/gi, '') || 'root'
    );
}
