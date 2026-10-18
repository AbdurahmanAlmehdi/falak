import {
    AppShell,
    Avatar,
    Button,
    Callout,
    ChangesBar,
    Checkbox,
    CodeBlock,
    Combobox,
    ConfirmDestructive,
    DataTable,
    defaultSetupSteps,
    Dialog,
    EmptyState,
    Field,
    IconButton,
    Input,
    IntegrationTile,
    KeyValue,
    LogViewer,
    Menu,
    MetricChart,
    PageHeader,
    Panel,
    PhaseTimeline,
    RelativeTime,
    SecretInput,
    Section,
    Select,
    ServiceIcon,
    SetupChecklist,
    Skeleton,
    StatusBadge,
    StatusDot,
    Stepper,
    Switch,
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
    Tag,
    Textarea,
    toast,
    Tooltip,
} from '@/components/falak';
import { usePage } from '@inertiajs/react';
import { Copy, Database, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

const STATUSES = [
    'active',
    'online',
    'healthy',
    'succeeded',
    'building',
    'deploying',
    'provisioning',
    'needs-attention',
    'queued',
    'failed',
    'crashed',
    'offline',
    'removed',
    'inactive',
    'cancelled',
];
const ICONS = [
    'laravel',
    'symfony',
    'statamic',
    'wordpress',
    'php',
    'next',
    'nuxt',
    'node',
    'bun',
    'deno',
    'docker',
    'postgresql',
    'mysql',
    'mariadb',
    'redis',
    'caddy',
    'site',
    'database',
    'server',
    'unknown',
];

const LOG = [
    '\u001b[1m==> Build\u001b[0m',
    'Detected \u001b[35mLaravel 12\u001b[0m (PHP 8.4) via railpack',
    '\u001b[32m✔\u001b[0m composer install --no-dev --optimize-autoloader (12.4s)',
    '\u001b[33mwarning\u001b[0m npm WARN deprecated inflight@1.0.6',
    '\u001b[32m✔\u001b[0m vite build (3.1s)',
    '\u001b[1m==> Migrate\u001b[0m',
    '\u001b[31merror\u001b[0m SQLSTATE[42S01]: Base table or view already exists',
];

export default function ComponentsGallery() {
    const { url } = usePage();
    const [panelOpen, setPanelOpen] = useState(url.includes('panel='));
    const [dialogOpen, setDialogOpen] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [changes, setChanges] = useState(3);
    const [select, setSelect] = useState<string>('production');
    const [combo, setCombo] = useState<string | null>(null);
    const [on, setOn] = useState(true);
    const [secret, setSecret] = useState('');

    const logLines = useMemo(
        () =>
            Array.from({ length: 400 }, (_, index) => ({
                text: `${LOG[index % LOG.length]} \u001b[2m#${index}\u001b[0m`,
                phase: index < 200 ? 'Build' : 'Deploy',
                level: LOG[index % LOG.length].includes('error') ? ('error' as const) : undefined,
            })),
        [],
    );
    const metrics = useMemo(
        () =>
            Array.from({ length: 48 }, (_, index) => ({
                t: 1_790_000_000 + index * 300,
                cpu: 20 + 15 * Math.sin(index / 5) + (index % 7),
                memory: 45 + 10 * Math.cos(index / 8),
            })),
        [],
    );
    const rows = [
        { id: '1', name: 'storefront', icon: 'laravel', status: 'active', deployed: new Date(Date.now() - 120_000).toISOString(), p95: 182 },
        { id: '2', name: 'marketing', icon: 'next', status: 'deploying', deployed: new Date(Date.now() - 3_600_000).toISOString(), p95: 96 },
        { id: '3', name: 'db-main', icon: 'postgresql', status: 'failed', deployed: null, p95: 12 },
    ];

    return (
        <AppShell breadcrumbs={[{ title: 'Components', href: '/dev/components' }]}>
            <div className="grid gap-10">
                <PageHeader
                    title="Falak components"
                    description="Local-only gallery of resources/js/components/falak (docs/UI_DESIGN.md §6)."
                    actions={
                        <Button variant="primary" icon={<Plus />}>
                            Primary
                        </Button>
                    }
                />

                <Section title="Buttons" bare>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="primary">Deploy</Button>
                        <Button>Secondary</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="danger">Delete</Button>
                        <Button variant="primary" loading>
                            Deploying
                        </Button>
                        <Button size="sm">Small</Button>
                        <IconButton label="Copy id" icon={<Copy />} />
                        <Menu
                            actions={[
                                { label: 'Redeploy', icon: <RotateCcw />, shortcut: 'R' },
                                { label: 'Rename', icon: <Pencil /> },
                                { type: 'separator' },
                                { label: 'Delete', icon: <Trash2 />, danger: true },
                            ]}
                        />
                        <Tooltip content="Tooltip with shortcut" shortcut="G S">
                            <Button variant="ghost">Hover me</Button>
                        </Tooltip>
                        <Button onClick={() => toast.success('Deployed storefront', 'app-1, app-2 · 42s')}>Toast</Button>
                        <Button
                            onClick={() =>
                                void toast.promise(new Promise((resolve) => setTimeout(resolve, 1200)), {
                                    loading: 'Saving…',
                                    success: 'Saved',
                                    error: 'Failed',
                                })
                            }
                        >
                            Promise toast
                        </Button>
                    </div>
                </Section>

                <Section title="Setup checklist · secrets · callouts · integrations" bare>
                    <SetupChecklist
                        steps={defaultSetupSteps({ gitConnected: true, hasServer: true, hasProject: false, hasDeployment: false })}
                        onDismiss={() => toast.info('Dismissed')}
                    />
                    <div className="grid items-start gap-3 sm:grid-cols-2">
                        <Field label="New secret">
                            <SecretInput value={secret} onChange={setSecret} placeholder="ghp_…" />
                        </Field>
                        <Field label="Stored secret" hint="Write-only: Replace, never reveal.">
                            <SecretInput stored storedHint="…a1b2" value={secret} onChange={setSecret} />
                        </Field>
                    </div>
                    <Callout tone="success" title="Verified">
                        Falak wrote and deleted a probe object.
                    </Callout>
                    <Callout tone="danger" title="Could not verify">
                        Hetzner Cloud: unable to authenticate
                    </Callout>
                    <div className="flex flex-wrap gap-2">
                        {[
                            'github',
                            'gitlab',
                            'bitbucket',
                            'hetzner',
                            'digitalocean',
                            'vultr',
                            'linode',
                            'contabo',
                            'aws',
                            'r2',
                            'b2',
                            'minio',
                            's3',
                            'slack',
                            'discord',
                            'telegram',
                            'email',
                            'webhook',
                            'grafana',
                        ].map((name) => (
                            <Tooltip key={name} content={name}>
                                <span tabIndex={0}>
                                    <IntegrationTile name={name} />
                                </span>
                            </Tooltip>
                        ))}
                    </div>
                </Section>

                <Section title="Status language" bare>
                    <div className="flex flex-wrap gap-2">
                        {STATUSES.map((status) => (
                            <StatusBadge key={status} status={status} />
                        ))}
                        <StatusBadge status="deploying" label="Deploying 64%" />
                    </div>
                    <div className="text-fg-muted flex items-center gap-3 text-sm">
                        <StatusDot status="active" /> Active · <RelativeTime value={new Date(Date.now() - 125_000)} />
                        <StatusDot status="deploying" label="Deploying" /> Deploying
                    </div>
                </Section>

                <Section title="Service icons" bare>
                    <div className="flex flex-wrap gap-3">
                        {ICONS.map((icon) => (
                            <Tooltip key={icon} content={icon}>
                                <span className="border-border bg-surface-1 flex size-9 items-center justify-center rounded-lg border" tabIndex={0}>
                                    <ServiceIcon name={icon} size={18} title={icon} />
                                </span>
                            </Tooltip>
                        ))}
                    </div>
                </Section>

                <Section title="Form controls" description="Field wires label, hint and error to its control.">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" hint="Lowercase, used in URLs">
                            <Input defaultValue="storefront" />
                        </Field>
                        <Field label="Domain" error="The domain has already been taken.">
                            <Input defaultValue="shop.acme.test" />
                        </Field>
                        <Field label="Environment">
                            <Select
                                value={select}
                                onValueChange={setSelect}
                                options={[
                                    { value: 'production', label: 'production' },
                                    { value: 'staging', label: 'staging' },
                                ]}
                            />
                        </Field>
                        <Field label="Server">
                            <Combobox
                                value={combo}
                                onValueChange={setCombo}
                                placeholder="Pick a server"
                                options={[
                                    { value: 'app-1', label: 'app-1', description: '10.0.0.11' },
                                    { value: 'app-2', label: 'app-2', description: '10.0.0.12' },
                                ]}
                            />
                        </Field>
                        <Field label="Deploy script" className="sm:col-span-2">
                            <Textarea mono defaultValue={'$FALAK_FETCH\ncomposer install\n$FALAK_ACTIVATE'} />
                        </Field>
                        <Field inline label="Push to deploy">
                            <Switch checked={on} onCheckedChange={setOn} />
                        </Field>
                        <Field inline label="Expose to deploy script">
                            <Checkbox defaultChecked />
                        </Field>
                    </div>
                </Section>

                <Section title="Data table" bare>
                    <DataTable
                        label="Services"
                        rows={rows}
                        rowKey={(row) => row.id}
                        onRowClick={(row) => toast(`Open ${row.name}`)}
                        columns={[
                            {
                                id: 'name',
                                header: 'Service',
                                sortValue: (row) => row.name,
                                cell: (row) => (
                                    <span className="flex items-center gap-2">
                                        <ServiceIcon name={row.icon} /> {row.name}
                                    </span>
                                ),
                            },
                            { id: 'status', header: 'Status', cell: (row) => <StatusBadge status={row.status} /> },
                            {
                                id: 'deployed',
                                header: 'Last deploy',
                                hideOnMobile: true,
                                cell: (row) => <RelativeTime value={row.deployed} fallback="Never" className="text-fg-muted" />,
                            },
                            { id: 'p95', header: 'p95', align: 'right', sortValue: (row) => row.p95, cell: (row) => `${row.p95} ms` },
                        ]}
                        rowActions={() => [
                            { label: 'Redeploy', icon: <RotateCcw /> },
                            { label: 'Delete', icon: <Trash2 />, danger: true },
                        ]}
                    />
                    <DataTable
                        label="Loading"
                        rows={[]}
                        rowKey={() => ''}
                        loading
                        columns={[{ id: 'a', header: 'Loading state', cell: () => null }]}
                    />
                    <EmptyState
                        icon={<Database />}
                        title="No databases yet"
                        description="Databases run on your servers and are backed up to your storage."
                        action={<Button variant="primary">Create database</Button>}
                        size="sm"
                    />
                </Section>

                <Section title="Metrics" bare>
                    <div className="grid gap-3 lg:grid-cols-2">
                        <MetricChart
                            title="CPU"
                            value="31%"
                            data={metrics}
                            series={[{ key: 'cpu', label: 'CPU' }]}
                            type="area"
                            format={(v) => `${v.toFixed(0)}%`}
                        />
                        <MetricChart
                            title="CPU vs memory"
                            data={metrics}
                            series={[
                                { key: 'cpu', label: 'CPU' },
                                { key: 'memory', label: 'Memory' },
                            ]}
                            format={(v) => `${v.toFixed(0)}%`}
                        />
                    </div>
                </Section>

                <Section title="Deploy view pieces" bare>
                    <PhaseTimeline
                        phases={['Build', 'Fetch', 'Prepare', 'Migrate', 'Activate', 'Restart', 'Health'].map((label) => ({
                            id: label.toLowerCase(),
                            label,
                        }))}
                        rows={[
                            {
                                id: 'a',
                                label: 'app-1 ★',
                                cells: {
                                    build: { status: 'succeeded', durationMs: 42_000 },
                                    fetch: { status: 'succeeded', durationMs: 3200 },
                                    prepare: { status: 'succeeded', durationMs: 900 },
                                    migrate: { status: 'running' },
                                    activate: { status: 'queued' },
                                    restart: { status: 'queued' },
                                    health: { status: 'queued' },
                                },
                            },
                            {
                                id: 'b',
                                label: 'app-2',
                                cells: {
                                    build: { status: 'succeeded', durationMs: 42_000 },
                                    fetch: { status: 'succeeded', durationMs: 2900 },
                                    prepare: { status: 'failed', durationMs: 1400 },
                                    activate: { status: 'skipped' },
                                    restart: { status: 'skipped' },
                                    health: { status: 'skipped' },
                                },
                            },
                        ]}
                    />
                    <Stepper
                        steps={[
                            { id: '1', label: 'Create server', status: 'succeeded', detail: '12s' },
                            { id: '2', label: 'Install agent', status: 'running' },
                            { id: '3', label: 'Provision stack', status: 'pending' },
                        ]}
                    />
                    <LogViewer lines={logLines} height={300} streaming label="Build log" />
                    <CodeBlock title=".env" code={'APP_ENV=production\nDB_HOST=${{ db-main.DB_HOST }}'} />
                </Section>

                <Section title="Overlays & misc" bare>
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={() => setPanelOpen(true)}>Open panel</Button>
                        <Button onClick={() => setDialogOpen(true)}>Open dialog</Button>
                        <Button variant="danger" onClick={() => setConfirmOpen(true)}>
                            Delete service…
                        </Button>
                        <Button onClick={() => setChanges((value) => value + 1)}>Add change</Button>
                    </div>
                    <div className="flex items-center gap-3">
                        <Avatar name="Ada Admin" />
                        <Tag>php 8.4</Tag>
                        <Tag tone="accent">leader</Tag>
                        <Tag mono>01J9ZK3…</Tag>
                        <Skeleton className="h-4 w-40" />
                    </div>
                    <Tabs defaultValue="one">
                        <TabsList>
                            <TabsTrigger value="one">Deployments</TabsTrigger>
                            <TabsTrigger value="two" badge={3}>
                                Variables
                            </TabsTrigger>
                        </TabsList>
                        <TabsContent value="one" className="text-fg-muted pt-3 text-sm">
                            Tab one
                        </TabsContent>
                        <TabsContent value="two" className="text-fg-muted pt-3 text-sm">
                            Tab two
                        </TabsContent>
                    </Tabs>
                    <KeyValue
                        columns={3}
                        items={[
                            { label: 'Engine', value: 'PostgreSQL 17' },
                            { label: 'Host', value: '10.0.0.5', mono: true, copy: '10.0.0.5' },
                            { label: 'Size', value: '1.2 GB' },
                        ]}
                    />
                </Section>

                <ChangesBar count={changes} onApply={() => setChanges(0)} onDiscard={() => setChanges(0)} />
            </div>

            <Panel
                open={panelOpen}
                onOpenChange={setPanelOpen}
                title="storefront"
                subtitle="shop.acme.test · app-1, app-2"
                icon={<ServiceIcon name="laravel" size={18} />}
                status={<StatusBadge status="active" />}
                actions={
                    <>
                        <Button variant="primary" size="sm">
                            Deploy
                        </Button>
                        <Menu actions={[{ label: 'Redeploy' }, { label: 'Rollback…' }, { type: 'separator' }, { label: 'Delete', danger: true }]} />
                    </>
                }
                urlSync={{ mode: 'query', param: 'panel' }}
                tabs={[
                    {
                        id: 'deployments',
                        label: 'Deployments',
                        content: () => <LogViewer lines={logLines.slice(0, 60)} height={360} label="Deploy log" />,
                    },
                    { id: 'variables', label: 'Variables', badge: 12, content: <p className="text-fg-muted text-sm">Variables tab</p> },
                    {
                        id: 'metrics',
                        label: 'Metrics',
                        content: () => <MetricChart title="Requests" data={metrics} series={[{ key: 'cpu', label: 'req/s' }]} />,
                    },
                    { id: 'settings', label: 'Settings', content: <p className="text-fg-muted text-sm">Settings tab</p> },
                ]}
            />
            <Dialog
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                title="New variable"
                description="Available on the next deploy."
                footer={
                    <Button variant="primary" onClick={() => setDialogOpen(false)}>
                        Add
                    </Button>
                }
            >
                <Field label="Key">
                    <Input mono placeholder="APP_KEY" />
                </Field>
            </Dialog>
            <ConfirmDestructive
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Delete storefront"
                description="Removes the site from app-1 and app-2."
                confirmText="storefront"
                onConfirm={() => setConfirmOpen(false)}
            />
        </AppShell>
    );
}
