import { CommandLog, TERMINAL_COMMAND_STATUSES, type CommandStatus } from '@/components/command-log';
import { Button } from '@/components/falak/button';
import { DataTable } from '@/components/falak/data-table';
import { Dialog } from '@/components/falak/dialog';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { Section } from '@/components/falak/section';
import { Select } from '@/components/falak/select';
import { Tag } from '@/components/falak/tag';
import { toast } from '@/components/falak/toast';
import ServerLayout, { type ServerHeader } from '@/layouts/server-layout';
import { router, useForm, usePoll } from '@inertiajs/react';
import { Lock, Pencil, Plus, RefreshCw, Shield, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEventHandler } from 'react';
import { ApplyStatusBadge } from '../components/network-ui';
import { formatCount, type RuleCounters } from '../protection';
import { type FirewallStateSummary } from '../types';

type RuleAction = 'allow' | 'deny';
type RuleProtocol = 'tcp' | 'udp' | 'any';

interface Rule {
    id: string;
    name: string;
    action: RuleAction;
    protocol: RuleProtocol;
    port: string | null;
    source: string | null;
    is_default: boolean;
    created_at: string;
}

interface CompiledRule {
    id: string;
    protocol: string;
    ports?: string[];
    sources?: string[];
    interface?: string;
    comment?: string;
}

interface Props {
    server: ServerHeader;
    rules: Rule[];
    networkRules: CompiledRule[];
    state: FirewallStateSummary | null;
    sshPort: number;
    /** Packets / bytes per rule id since the ruleset was loaded; null before the agent reports them (net.protection). */
    counters?: RuleCounters | null;
    countersAt?: string | null;
    can: { manage: boolean };
}

interface RuleForm {
    name: string;
    action: RuleAction;
    protocol: RuleProtocol;
    port: string;
    source: string;
}

type Row = { kind: 'rule'; rule: Rule } | { kind: 'managed'; rule: CompiledRule };

const EMPTY: RuleForm = { name: '', action: 'allow', protocol: 'tcp', port: '', source: '' };
const PORT = /^\d{1,5}(-\d{1,5})?$/;
const RELOAD = ['server', 'rules', 'networkRules', 'state', 'counters', 'countersAt'];

const PRESETS: { label: string; form: Partial<RuleForm> }[] = [
    { label: 'HTTP', form: { name: 'HTTP', protocol: 'tcp', port: '80' } },
    { label: 'HTTPS', form: { name: 'HTTPS', protocol: 'tcp', port: '443' } },
    { label: 'PostgreSQL', form: { name: 'PostgreSQL', protocol: 'tcp', port: '5432', source: '10.0.0.0/8' } },
    { label: 'MySQL', form: { name: 'MySQL', protocol: 'tcp', port: '3306', source: '10.0.0.0/8' } },
    { label: 'Redis', form: { name: 'Redis', protocol: 'tcp', port: '6379', source: '10.0.0.0/8' } },
];

export default function Firewall({ server, rules, networkRules, state, sshPort, counters, countersAt, can }: Props) {
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Rule | null>(null);
    const [deleteBusy, setDeleteBusy] = useState(false);
    const [reapplying, setReapplying] = useState(false);
    const form = useForm<RuleForm>(EMPTY);
    const active = server.status === 'active';

    usePoll(state?.status === 'applying' || state?.status === 'pending' ? 3_000 : 60_000, { only: RELOAD });

    const portError = form.data.port.trim() !== '' && !PORT.test(form.data.port.trim()) ? 'A port (1-65535) or range such as 8000-8100.' : null;

    const open = (rule: Rule | 'new', preset?: Partial<RuleForm>) => {
        form.clearErrors();
        form.setData(
            rule === 'new'
                ? { ...EMPTY, ...preset }
                : { name: rule.name, action: rule.action, protocol: rule.protocol, port: rule.port ?? '', source: rule.source ?? '' },
        );
        setEditing(rule);
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (portError) return;
        const isNew = editing === 'new';
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setEditing(null);
                toast.success(
                    isNew ? `Rule ${form.data.name} added` : `Rule ${form.data.name} saved`,
                    active ? 'Applying the ruleset to the server.' : undefined,
                );
            },
        };

        if (isNew) {
            form.post(`/network/servers/${server.id}/firewall/rules`, options);
        } else if (editing) {
            form.put(`/network/servers/${server.id}/firewall/rules/${editing.id}`, options);
        }
    };

    const destroy = () => {
        if (!deleting) return;
        router.delete(`/network/servers/${server.id}/firewall/rules/${deleting.id}`, {
            preserveScroll: true,
            onStart: () => setDeleteBusy(true),
            onSuccess: () => toast.success(`Rule ${deleting.name} deleted`),
            onFinish: () => {
                setDeleteBusy(false);
                setDeleting(null);
            },
        });
    };

    const reapply = () =>
        router.post(
            `/network/servers/${server.id}/firewall/apply`,
            {},
            {
                preserveScroll: true,
                onStart: () => setReapplying(true),
                onFinish: () => setReapplying(false),
                onSuccess: () => toast.success('Re-applying the firewall'),
                onError: (errors) => toast.error('Could not re-apply', Object.values(errors)[0]),
            },
        );

    const onCommandStatus = (status: CommandStatus) => {
        if (TERMINAL_COMMAND_STATUSES.includes(status)) {
            router.reload({ only: RELOAD });
        }
    };

    const rows = useMemo<Row[]>(
        () => [...networkRules.map((rule) => ({ kind: 'managed' as const, rule })), ...rules.map((rule) => ({ kind: 'rule' as const, rule }))],
        [rules, networkRules],
    );

    const deletingSsh = deleting?.port === String(sshPort) && deleting.action === 'allow';
    // Falak's always-allow SSH accept runs before the rules and takes their hits: shown on the rule that allows the
    // same traffic (SSH's port from anywhere), or in the header when there is none.
    const sshRuleId = rules.find((rule) => rule.action === 'allow' && rule.port === String(sshPort) && rule.protocol !== 'udp' && !rule.source)?.id;
    const hits = (row: Row) => {
        const own = counters?.[row.rule.id];
        const ssh = row.kind === 'rule' && row.rule.id === sshRuleId ? counters?.ssh : undefined;

        return own || ssh ? { packets: (own?.packets ?? 0) + (ssh?.packets ?? 0), bytes: (own?.bytes ?? 0) + (ssh?.bytes ?? 0), ssh: !!ssh } : null;
    };

    return (
        <ServerLayout
            server={server}
            tab="firewall"
            reloadOnly={RELOAD}
            actions={
                can.manage && (
                    <>
                        <Button icon={<RefreshCw />} onClick={reapply} disabled={!active} loading={reapplying}>
                            Re-apply
                        </Button>
                        <Button variant="primary" icon={<Plus />} onClick={() => open('new')}>
                            Add rule
                        </Button>
                    </>
                )
            }
        >
            <div className="border-border bg-surface-1 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border px-4 py-3">
                <Shield className="text-fg-faint size-4" aria-hidden />
                <div className="grid min-w-0 flex-1 gap-0.5">
                    <p className="text-fg text-sm font-medium">Incoming traffic is dropped unless a rule allows it</p>
                    <p className="text-fg-muted text-xs">
                        {state?.applied_at ? (
                            <>
                                Revision <span className="tabular">{state.revision}</span> applied <RelativeTime value={state.applied_at} />
                            </>
                        ) : (
                            'Never applied'
                        )}
                        {state && !state.in_sync && state.status === 'applied' ? ' · changes pending' : ''} · deny rules run before allow rules · ICMP
                        is always allowed
                        {counters?.ssh && !sshRuleId && ` · SSH (always allowed) ${formatCount(counters.ssh.packets)} pkts`}
                        {countersAt && (
                            <>
                                {' '}
                                · hits as of <RelativeTime value={countersAt} />
                            </>
                        )}
                    </p>
                </div>
                <ApplyStatusBadge status={state?.status} />
            </div>

            {state?.error && (
                <p role="alert" className="border-danger/40 bg-danger-soft text-danger rounded-lg border px-4 py-3 text-sm">
                    {state.error}
                </p>
            )}

            <DataTable<Row>
                label="Firewall rules"
                rows={rows}
                rowKey={(row) => `${row.kind}-${row.rule.id}`}
                empty={{
                    icon: <Shield />,
                    title: 'No rules',
                    description: 'Without rules only SSH is reachable. Add a rule to open a port.',
                    action: can.manage ? (
                        <Button variant="primary" size="sm" icon={<Plus />} onClick={() => open('new')}>
                            Add rule
                        </Button>
                    ) : undefined,
                }}
                columns={[
                    {
                        id: 'name',
                        header: 'Rule',
                        cell: (row) =>
                            row.kind === 'managed' ? (
                                <span className="text-fg-muted flex items-center gap-2">
                                    <Lock className="text-fg-faint size-3.5" aria-hidden />
                                    {row.rule.comment ?? 'Private network'}
                                    <Tag tone="faint">managed</Tag>
                                </span>
                            ) : (
                                <span className="flex items-center gap-2">
                                    <span className="text-fg font-medium">{row.rule.name}</span>
                                    {row.rule.is_default && <Tag>default</Tag>}
                                </span>
                            ),
                    },
                    {
                        id: 'action',
                        header: 'Action',
                        cell: (row) =>
                            row.kind === 'managed' || row.rule.action === 'allow' ? <Tag tone="success">Allow</Tag> : <Tag tone="danger">Deny</Tag>,
                    },
                    {
                        id: 'protocol',
                        header: 'Protocol',
                        hideOnMobile: true,
                        cell: (row) => <span className="text-fg-muted font-mono text-xs uppercase">{row.rule.protocol}</span>,
                    },
                    {
                        id: 'port',
                        header: 'Port',
                        cell: (row) => (
                            <span className="font-mono text-xs">
                                {row.kind === 'managed'
                                    ? (row.rule.ports?.join(', ') ?? (row.rule.interface ? `all on ${row.rule.interface}` : 'all'))
                                    : (row.rule.port ?? 'all')}
                            </span>
                        ),
                    },
                    {
                        id: 'source',
                        header: 'Source',
                        hideOnMobile: true,
                        cell: (row) => (
                            <span className="text-fg-muted font-mono text-xs">
                                {row.kind === 'managed' ? (row.rule.sources?.join(', ') ?? 'anywhere') : (row.rule.source ?? 'anywhere')}
                            </span>
                        ),
                    },
                    ...(counters
                        ? [
                              {
                                  id: 'hits',
                                  header: 'Hits',
                                  hideOnMobile: true,
                                  cell: (row: Row) => {
                                      const c = hits(row);

                                      return c ? (
                                          <span
                                              className="tabular text-fg-muted text-xs"
                                              title={`${c.packets} packets, ${c.bytes} bytes${c.ssh ? ", including Falak's always-allow SSH rule, which runs first" : ''}`}
                                          >
                                              {formatCount(c.packets)} pkts · {formatCount(c.bytes, 'B')}
                                          </span>
                                      ) : (
                                          <span className="text-fg-faint text-xs">—</span>
                                      );
                                  },
                              },
                          ]
                        : []),
                ]}
                rowActions={
                    can.manage
                        ? (row) =>
                              row.kind === 'managed'
                                  ? [{ label: 'Managed by the private network', disabled: true }]
                                  : [
                                        { label: 'Edit', icon: <Pencil />, onSelect: () => open(row.rule) },
                                        { type: 'separator' },
                                        { label: 'Delete', icon: <Trash2 />, danger: true, onSelect: () => setDeleting(row.rule) },
                                    ]
                        : undefined
                }
            />

            {state?.command_id && state.status === 'applying' && (
                <Section title="Applying" bare>
                    <CommandLog commandId={state.command_id} onStatusChange={onCommandStatus} />
                </Section>
            )}

            {state?.ruleset_sha256 && <p className="text-fg-faint font-mono text-xs break-all">ruleset sha256 {state.ruleset_sha256}</p>}

            <Dialog
                open={editing !== null}
                onOpenChange={(value) => !value && setEditing(null)}
                title={editing === 'new' ? 'Add firewall rule' : 'Edit firewall rule'}
                description="Leave the port empty for all ports and the source empty for anywhere."
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setEditing(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="primary"
                            type="submit"
                            form="firewall-rule"
                            loading={form.processing}
                            disabled={!form.data.name.trim() || Boolean(portError)}
                        >
                            {editing === 'new' ? 'Add rule' : 'Save rule'}
                        </Button>
                    </>
                }
            >
                <form id="firewall-rule" onSubmit={submit} className="grid gap-4">
                    {editing === 'new' && (
                        <div className="flex flex-wrap gap-1.5" aria-label="Presets">
                            {PRESETS.map((preset) => (
                                <Button
                                    key={preset.label}
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => form.setData((current) => ({ ...current, ...preset.form }))}
                                >
                                    {preset.label}
                                </Button>
                            ))}
                        </div>
                    )}
                    <Field label="Name" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            placeholder="PostgreSQL from app servers"
                            autoFocus
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Action" error={form.errors.action}>
                            <Select
                                value={form.data.action}
                                onValueChange={(value) => form.setData('action', value as RuleAction)}
                                options={[
                                    { value: 'allow', label: 'Allow' },
                                    { value: 'deny', label: 'Deny' },
                                ]}
                            />
                        </Field>
                        <Field label="Protocol" error={form.errors.protocol}>
                            <Select
                                value={form.data.protocol}
                                onValueChange={(value) => form.setData('protocol', value as RuleProtocol)}
                                options={[
                                    { value: 'tcp', label: 'TCP' },
                                    { value: 'udp', label: 'UDP' },
                                    { value: 'any', label: 'Any' },
                                ]}
                            />
                        </Field>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <Field label="Port or range" error={form.errors.port ?? portError}>
                            <Input
                                value={form.data.port}
                                onChange={(event) => form.setData('port', event.target.value)}
                                placeholder="5432 or 8000-8100"
                                mono
                            />
                        </Field>
                        <Field label="Source" error={form.errors.source}>
                            <Input
                                value={form.data.source}
                                onChange={(event) => form.setData('source', event.target.value)}
                                placeholder="10.0.0.0/8"
                                mono
                            />
                        </Field>
                    </div>
                </form>
            </Dialog>

            <Dialog
                open={deleting !== null}
                onOpenChange={(value) => !value && setDeleting(null)}
                size="sm"
                title={`Delete ${deleting?.name}?`}
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setDeleting(null)}>
                            Cancel
                        </Button>
                        <Button variant="danger" onClick={destroy} loading={deleteBusy}>
                            Delete rule
                        </Button>
                    </>
                }
            >
                <p className={deletingSsh ? 'text-warning text-sm' : 'text-fg-muted text-sm'}>
                    {deletingSsh
                        ? `This rule allows SSH (port ${sshPort}). Deleting it may lock you out of the server.`
                        : `The updated ruleset is applied to ${server.name} immediately.`}
                </p>
            </Dialog>
        </ServerLayout>
    );
}
