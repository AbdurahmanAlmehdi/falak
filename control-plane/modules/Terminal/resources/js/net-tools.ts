/** Network tools (docs/plans/network/tools.md): the form's fields per tool and the request body they build. */

export type NetTool = 'ping' | 'traceroute' | 'dns' | 'http' | 'tcp';

export interface NetToolForm {
    tool: NetTool;
    target: string;
    url: string;
    port: string;
    count: string;
    max_hops: string;
    record_type: string;
    nameserver: string;
    method: string;
    connect_to: string;
    insecure: boolean;
    timeout_s: string;
}

export interface NetToolRun {
    id: string;
    tool: NetTool;
    input: Record<string, unknown>;
    target_type: 'server' | 'site' | 'database';
    target_name: string | null;
    server_name: string;
    container: string | null;
    command_id: string | null;
    status: 'running' | 'succeeded' | 'failed' | 'timed_out' | 'cancelled';
    exit_code: number | null;
    error: string | null;
    created_at: string;
}

export const NET_TOOLS: { value: NetTool; label: string }[] = [
    { value: 'ping', label: 'Ping' },
    { value: 'traceroute', label: 'Traceroute' },
    { value: 'dns', label: 'DNS' },
    { value: 'http', label: 'HTTP' },
    { value: 'tcp', label: 'TCP' },
];

export const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'PTR'];

/** The fields each tool shows (and sends), besides the timeout. Mirrors NetToolSpec. */
export const TOOL_FIELDS: Record<NetTool, (keyof NetToolForm)[]> = {
    ping: ['target', 'count'],
    traceroute: ['target', 'max_hops'],
    dns: ['target', 'record_type', 'nameserver'],
    http: ['url', 'method', 'connect_to', 'insecure'],
    tcp: ['target', 'port', 'count'],
};

export const EMPTY_FORM: NetToolForm = {
    tool: 'ping',
    target: '',
    url: 'https://',
    port: '',
    count: '',
    max_hops: '',
    record_type: 'A',
    nameserver: '',
    method: 'GET',
    connect_to: '',
    insecure: false,
    timeout_s: '',
};

const NUMBERS: (keyof NetToolForm)[] = ['port', 'count', 'max_hops', 'timeout_s'];

/** The request body: the tool, only its own non-empty fields (numbers as numbers), and the timeout when set. */
export function netToolBody(form: NetToolForm): Record<string, string | number | boolean> {
    const body: Record<string, string | number | boolean> = { tool: form.tool };

    for (const field of [...TOOL_FIELDS[form.tool], 'timeout_s' as const]) {
        const value = form[field];

        if (typeof value === 'boolean') {
            if (value) body[field] = true;
            continue;
        }

        const trimmed = value.trim();
        if (trimmed === '') continue;
        body[field] = NUMBERS.includes(field) && /^\d+$/.test(trimmed) ? Number(trimmed) : trimmed;
    }

    return body;
}

/** One line describing a past run: "tcp db.internal:5432", "http GET https://…". */
export function describeRun(run: Pick<NetToolRun, 'tool' | 'input'>): string {
    const input = run.input;
    switch (run.tool) {
        case 'http':
            return `http ${String(input.method ?? 'GET')} ${String(input.url ?? '')}`;
        case 'tcp':
            return `tcp ${String(input.target ?? '')}:${String(input.port ?? '')}`;
        case 'dns':
            return `dns ${String(input.record_type ?? 'A')} ${String(input.target ?? '')}`;
        default:
            return `${run.tool} ${String(input.target ?? '')}`;
    }
}
