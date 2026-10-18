export type ServerStatus = 'creating' | 'provisioning' | 'needs_attention' | 'active' | 'error' | 'deleting';
export type ServerTypeValue = 'app' | 'web' | 'db' | 'cache' | 'worker' | 'lb' | 'builder';
export type StackComponent = 'php' | 'node';

export interface StackConfig {
    php: { runtime: string; versions: string[]; default: string | null } | null;
    node: string | null;
}

export interface ServerSummary {
    id: string;
    name: string;
    type: ServerTypeValue;
    type_label: string;
    status: ServerStatus;
    status_message: string | null;
    provider: string;
    provider_label: string;
    region: string | null;
    ipv4: string | null;
    php: string | null;
    agent: {
        status: 'online' | 'offline' | 'revoked';
        last_heartbeat_at: string | null;
        version: string | null;
        /** The agent build this control plane ships. */
        available_version: string | null;
        update_available: boolean;
        upgrade: AgentUpgrade | null;
    } | null;
    load1: number | null;
    cpu_percent: number | null;
    memory_percent: number | null;
    disk_percent: number | null;
    created_at: string;
    /** The control plane's own machine ("This machine"). */
    control_plane_host: boolean;
    /** Memory kept for the control plane there (0 elsewhere). */
    reserved_memory_mb: number;
}

export interface ServerDetails extends ServerSummary {
    size: string | null;
    image: string | null;
    provider_server_id: string | null;
    ipv6: string | null;
    private_ipv4: string | null;
    ssh_port: number;
    timezone: string;
    stack: StackConfig;
    os: string | null;
    arch: string | null;
    cpus: number | null;
    memory_bytes: number | null;
    disk_bytes: number | null;
    provision_command_id: string | null;
    provisioned_at: string | null;
    install_command: string | null;
    can_regenerate_install_command: boolean;
}

export interface AgentDetails {
    id: string;
    status: 'online' | 'offline' | 'revoked';
    version: string | null;
    hostname: string | null;
    enrolled_at: string;
    last_heartbeat_at: string | null;
    certificate_expires_at: string | null;
    metrics: {
        at?: string;
        uptime_s?: number;
        load?: number[];
        cpu_percent?: number | null;
        memory_used_bytes?: number;
        disk_used_bytes?: number;
    };
    runtimes: Record<string, string[]>;
    docker: string | null;
    kernel: string | null;
    /** The control plane's machine: the agent's edge runs behind the control plane's edge (falak-ctl local-server enable). */
    local_edge: boolean;
}

export interface MetricSample {
    at: string;
    load1: number;
    load5: number;
    load15: number;
    cpu_percent: number | null;
    memory_used_bytes: number;
    disk_used_bytes: number;
}

export interface FpmSettings {
    pm: 'dynamic' | 'static' | 'ondemand';
    max_children: number;
    start_servers: number;
    min_spare_servers: number;
    max_spare_servers: number;
    max_requests: number;
}

export interface PhpVersionRow {
    id: string;
    version: string;
    status: 'installing' | 'installed' | 'failed' | 'removing';
    status_message: string | null;
    is_default: boolean;
    ini: Record<string, string | number | boolean>;
    fpm: FpmSettings;
    command_id: string | null;
}

export interface SshKeyOption {
    id: string;
    name: string;
    fingerprint: string;
}

export interface FleetService {
    kind: 'site' | 'database';
    id: string;
    name: string;
    icon: string;
}

/** A site / database running on a server, with its canvas deep link. */
export interface ServerService extends FleetService {
    subtitle: string | null;
    status: string;
    role: 'leader' | 'member' | null;
    url: string;
}

export interface SparklinePoint {
    t: string;
    cpu: number | null;
    mem: number | null;
}

export type MachineCheckDecision = 'install' | 'adopt' | 'complete' | 'block' | 'skip';
export type MachineCheckSeverity = 'info' | 'warning' | 'block';

export interface MachineCheckNote {
    severity: MachineCheckSeverity;
    message: string;
    hint: string | null;
}

/** One component of the machine check (Servers\Domain\MachineCheck\ComponentDecision::toArray). */
export interface MachineCheckComponent {
    component: string;
    label: string;
    decision: MachineCheckDecision;
    /** Install · Use existing · Install missing parts · Blocked · Not managed */
    decision_label: string;
    severity: MachineCheckSeverity;
    reason: string;
    hint: string | null;
    found: { name: string; version: string | null; source: string | null }[];
    install: string[];
    keep: string[];
    service: string | null;
    notes: MachineCheckNote[];
}

/** The latest machine check (provision.inspect) and the decisions for the server's current stack. */
export interface MachineCheck {
    /** The agent runs the check (feature provision.v2). */
    supported: boolean;
    status: 'running' | 'finished' | 'failed' | null;
    purpose: 'provision' | 'check' | null;
    checked_at: string | null;
    agent_version: string | null;
    error: string | null;
    command_id: string | null;
    blocking: boolean;
    summary: string | null;
    /** Blocks first, then warnings. */
    components: MachineCheckComponent[];
}

export interface AgentUpgrade {
    id: string;
    status: 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled';
    from_version: string | null;
    to_version: string;
    error: string | null;
    requested_at: string;
    finished_at: string | null;
}
