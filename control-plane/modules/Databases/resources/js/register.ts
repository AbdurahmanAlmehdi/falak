import { registerCommands, registerNavigation, registerServiceActions, registerServiceTabs, registerSettingsNav } from '@/lib/registry';
import { Archive, Database, HardDrive, Plug } from 'lucide-react';
import { lazy } from 'react';
import { isKeyValue } from './types';

// Panel code loads with the canvas, not with every page.
const DatabaseOverviewTab = lazy(() => import('./panel/overview-tab').then((module) => ({ default: module.DatabaseOverviewTab })));
const DatabaseUsersTab = lazy(() => import('./panel/users-tab').then((module) => ({ default: module.DatabaseUsersTab })));
const DatabaseBackupsTab = lazy(() => import('./panel/backups-tab').then((module) => ({ default: module.DatabaseBackupsTab })));
const DatabaseSettingsTab = lazy(() => import('./panel/settings-tab').then((module) => ({ default: module.DatabaseSettingsTab })));
const DatabaseMetricsTab = lazy(() => import('./panel/metrics-tab').then((module) => ({ default: module.DatabaseMetricsTab })));
const DatabaseLogsTab = lazy(() => import('./panel/logs-tab').then((module) => ({ default: module.DatabaseLogsTab })));
const DatabaseInsightsTab = lazy(() => import('./panel/insights-tab').then((module) => ({ default: module.DatabaseInsightsTab })));

registerNavigation({ id: 'databases', title: 'Databases', url: '/databases', icon: Database, order: 300, permission: 'databases.view' });

// Canvas service panel of a database (§5.4).
registerServiceTabs(
    { id: 'overview', kinds: ['database'], title: 'Overview', order: 100, permission: 'databases.view', component: DatabaseOverviewTab },
    // Redis / Valkey instances have one `default` user (Overview): that tab is SQL only. Backups: RDB snapshots for both.
    {
        id: 'databases',
        kinds: ['database'],
        title: 'Databases & users',
        order: 200,
        permission: 'databases.view',
        component: DatabaseUsersTab,
        when: (service) => !isKeyValue(service.icon),
    },
    {
        id: 'backups',
        kinds: ['database'],
        title: 'Backups',
        order: 300,
        permission: 'databases.view',
        component: DatabaseBackupsTab,
    },
    // The container's engine metrics, logs and query insights (docs/plans/OBSERVABILITY.md phase 3).
    { id: 'metrics', kinds: ['database'], title: 'Metrics', order: 250, permission: 'telemetry.view', component: DatabaseMetricsTab },
    { id: 'logs', kinds: ['database'], title: 'Logs', order: 260, permission: 'telemetry.view', component: DatabaseLogsTab },
    { id: 'insights', kinds: ['database'], title: 'Insights', order: 270, permission: 'databases.view', component: DatabaseInsightsTab },
    { id: 'settings', kinds: ['database'], title: 'Settings', order: 900, permission: 'databases.view', component: DatabaseSettingsTab },
);

registerServiceActions({
    id: 'databases.connect',
    kinds: ['database'],
    label: 'Connect',
    icon: Plug,
    order: 0,
    primary: true,
    permission: 'databases.view',
    perform: (ctx) => ctx.open('overview'),
});

registerCommands({
    id: 'databases.navigation',
    commands: () => [
        { id: 'databases.index', title: 'Databases', group: 'Navigation', icon: Database, href: '/databases', permission: 'databases.view' },
        {
            id: 'databases.backups',
            title: 'Database backups',
            group: 'Navigation',
            icon: Archive,
            href: '/databases/backups',
            permission: 'databases.view',
            keywords: ['restore', 'dump'],
        },
    ],
});

// Organization settings → Integrations (docs/UI_DESIGN.md §3). The palette lists it under "Settings".
registerSettingsNav({
    id: 'storage',
    title: 'Backup storage',
    url: '/settings/storage',
    group: 'integrations',
    order: 220,
    icon: HardDrive,
    permission: 'databases.view',
    requiresOrganization: true,
    keywords: ['s3', 'r2', 'b2', 'spaces', 'minio', 'bucket', 'backups'],
});
