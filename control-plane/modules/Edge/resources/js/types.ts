import { type ManagedConfig } from '@/components/falak';

export type TlsMode = 'auto' | 'dns' | 'custom' | 'internal' | 'off';
export type WwwRedirect = 'none' | 'to_www' | 'to_apex';
export type ApplyStatus = 'pending' | 'applied' | 'failed' | 'error';
export type InstallStatus = 'pending' | 'installed' | 'failed' | 'removing';

export interface Option {
    value: string;
    label: string;
}

/** A public service of a compose site (primary first): each has domains and rules of its own. */
export interface ComposeServiceOption {
    service: string;
    primary: boolean;
    port: number;
    test_domain: string | null;
    health_check_path: string | null;
}

export interface EdgeDomain {
    id: string;
    name: string;
    /** Compose site: the public service it routes to (null: the primary service, i.e. the site). */
    service: string | null;
    is_primary: boolean;
    www_redirect: WwwRedirect;
    tls_mode: TlsMode;
    certificate_id: string | null;
    dns_credential_id: string | null;
    hosts: string[];
    served_host: string;
    supports_www: boolean;
    wildcard: boolean;
    /** ISO timestamp when ownership was confirmed (null: not yet verified in managed mode). */
    verified_at: string | null;
    /** TXT record value for ownership verification; non-null only while unverified in managed mode. */
    verification_token: string | null;
    /** Set by the project's falak.yaml: read-only here (edit the file). Null when the file doesn't manage it. */
    managed?: ManagedConfig | null;
    /** Set when the domain is in a Cloudflare zone Falak manages (records created for it). */
    cloudflare: {
        zone: string;
        proxied: boolean;
        /** null: the zone's default */
        override: boolean | null;
        cache: 'standard' | 'everything' | 'bypass';
        /** Cloudflare rate limit rule (null: none). */
        rate_limit: RateLimitRule | null;
        /** Another domain's Free-plan rule: it has no host condition, so it applies to this domain too. */
        zone_rate_limit: ZoneRateLimit | null;
        records: { name: string; type: string; content: string; status: 'pending' | 'synced' | 'conflict' | 'error'; error: string | null }[];
    } | null;
}

export interface EdgeCertificate {
    id: string;
    domains: string[];
    issuer: string | null;
    not_after: string | null;
    expired: boolean;
    fingerprint: string;
    created_at: string;
    installs: { server_id: string; server_name: string; status: InstallStatus; error: string | null }[];
}

export interface DnsCredentialOption {
    id: string;
    name: string;
    provider: string;
}

export interface EdgeServer {
    id: string;
    name: string;
    role: string;
    serves_http: boolean;
    state: {
        status: ApplyStatus;
        error: string | null;
        command_id: string | null;
        dispatched_at: string | null;
        applied_at: string | null;
        routes: number | null;
    } | null;
}

export interface LoadBalancerConfig {
    server_id: string;
    policy: string;
    health_uri: string | null;
    backend_port: number;
    weights: Record<string, number>;
}

/** GET /sites/{site}/domains (JSON). */
export interface DomainsData {
    testDomain: string | null;
    slug: string;
    /** Compose sites: their public services (empty for other sites). */
    services: ComposeServiceOption[];
    domains: EdgeDomain[];
    certificates: EdgeCertificate[];
    dnsCredentials: DnsCredentialOption[];
    dnsProviders: Option[];
    tlsModes: Option[];
    edgeServers: EdgeServer[];
    loadBalancer: LoadBalancerConfig | null;
    lbServers: { id: string; name: string; status: string }[];
    targets: { server_id: string; name: string; role: string }[];
    policies: Option[];
    routeId: string;
    can: { manage: boolean; manage_dns: boolean };
}

/** GET /sites/{site}/routing (JSON). */
export interface RoutingData {
    /** Compose sites: their public services (empty for other sites). Rows with `service` apply to that service only. */
    services: ComposeServiceOption[];
    redirects: { id: string; service: string | null; from: string; to: string; status: number; managed?: ManagedConfig | null }[];
    rules: { id: string; service: string | null; name: string | null; path: string | null; username: string }[];
    headers: { id: string; service: string | null; name: string; value: string; managed?: ManagedConfig | null }[];
    settings: { allow_ips: string[]; deny_ips: string[]; max_body_bytes: number | null; encode: boolean; tailnet_only: boolean };
    /** The organization saved a Tailscale auth key (Settings → Tailscale): "Tailscale only" can be turned on. */
    tailnet: { configured: boolean };
    /** IP lists per compose service: its allow list replaces the site's, its deny list adds to it. */
    serviceSettings: Record<string, { allow_ips: string[]; deny_ips: string[] }>;
    behindLoadBalancer: boolean;
    can: { manage: boolean };
}

export interface DnsTargetInfo {
    server_id: string;
    name: string;
    ipv4: string | null;
    ipv6: string | null;
    load_balancer: boolean;
}

/** GET /domains/options (JSON). */
export interface DomainOptionsData {
    test_domain: string | null;
    generated: {
        suffix: string | null;
        provider: string | null;
        ipv4: string | null;
        target: DnsTargetInfo | null;
        available: boolean;
        reason: string | null;
    };
    default: 'generated' | 'test' | 'custom';
    targets: DnsTargetInfo[];
}

export interface DnsRecord {
    type: string;
    name: string;
    host: string;
    value: string;
    target?: string;
}

export type DnsStatus = 'ok' | 'mismatch' | 'proxied' | 'missing' | 'error';

/** GET /dns/check (JSON). */
export interface DnsCheckData {
    name: string;
    status: DnsStatus;
    message: string;
    addresses: string[];
    cnames: string[];
    targets: DnsTargetInfo[];
    matched: DnsTargetInfo[];
    instructions: {
        name: string;
        zone: string;
        host: string;
        apex: boolean;
        ttl: number;
        records: DnsRecord[];
        alternative: DnsRecord | null;
        notes: string[];
        /** Records Falak creates itself (a Cloudflare zone it manages). */
        managed_by?: { provider: 'cloudflare'; zone: string } | null;
    };
    certificate: { status: 'issued' | 'pending'; message: string; issuer: string | null; expires_at: string | null } | null;
    checked_at: string;
}

export type RateLimitAction = 'block' | 'managed_challenge';

export interface RateLimitRule {
    path: string | null;
    requests: number;
    period: number;
    action: RateLimitAction;
    timeout: number;
}

/** GET /sites/{site}/domains/{domain}/rate-limit */
export interface ZoneRateLimit {
    domain: string;
    path: string | null;
}

export interface RateLimitData {
    domain: string;
    zone_rule: ZoneRateLimit | null;
    rule: RateLimitRule | null;
    zone: string | null;
    proxied: boolean;
    limits: {
        plan: string;
        rules: number;
        host: boolean;
        periods: number[];
        timeouts: number[];
        challenge_timeout: boolean;
        note: string | null;
    } | null;
}
