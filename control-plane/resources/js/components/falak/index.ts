/**
 * Falak component library (docs/UI_DESIGN.md §6). Owned components on Radix primitives, styled only with the
 * design tokens in resources/css/app.css. Import from '@/components/falak'.
 */
export { AppShell, type AppShellProps } from './app-shell';
export { Avatar, initialsOf, type AvatarProps } from './avatar';
export { Button, IconButton, buttonVariants, type ButtonProps, type IconButtonProps } from './button';
export { Callout, type CalloutProps } from './callout';
export { ChangesBar, type ChangesBarProps } from './changes-bar';
export { Checkbox, type CheckboxProps } from './checkbox';
export { CodeBlock, type CodeBlockProps } from './code-block';
export { Combobox, type ComboboxOption, type ComboboxProps } from './combobox';
export { CommandPalette, openCommandPalette } from './command-palette';
export { ConfirmDestructive, type ConfirmDestructiveProps } from './confirm-destructive';
export { CopyButton, copyText, type CopyButtonProps } from './copy-button';
export { DataTable, type DataTableColumn, type DataTableProps, type SortState } from './data-table';
export { Dialog, DialogClose, DialogTrigger, type DialogProps } from './dialog';
export { EmptyCanvas, type EmptyCanvasProps } from './empty-canvas';
export { EmptyState, type EmptyStateProps } from './empty-state';
export { EnvironmentSwitcher } from './environment-switcher';
export { Field, useFieldControl, type FieldProps } from './field';
export { toastsFrom, useFlashToasts } from './flash';
export { Input, Textarea, type InputProps, type TextareaProps } from './input';
export { IntegrationIcon, IntegrationTile, type IntegrationIconProps } from './integration-icon';
export { Kbd } from './kbd';
export { KeyValue, type KeyValueItem } from './key-value';
export { LogViewer, stripAnsi, type LogLine, type LogViewerProps } from './log-viewer';
export { FalakLogo, FalakMark } from './logo';
export { ManagedBadge, managedCovers, pointer, type ManagedConfig } from './managed-badge';
export {
    Menu,
    MenuActions,
    MenuCheckboxItem,
    MenuContent,
    MenuGroup,
    MenuItem,
    MenuLabel,
    MenuLink,
    MenuRoot,
    MenuSeparator,
    MenuTrigger,
    type MenuAction,
    type MenuItemProps,
} from './menu';
export { MetricChart, type MetricChartProps, type MetricPoint, type MetricSeries } from './metric-chart';
export { OrgSwitcher } from './org-switcher';
export { Panel, type PanelProps, type PanelTab, type PanelUrlSync } from './panel';
export { PanelHeader, PanelStack, type PanelHeaderProps, type PanelLayer, type PanelStackProps } from './panel-stack';
export { PhaseTimeline, Stepper, formatDuration, type PhaseCell, type PhaseRow, type Step } from './phase-timeline';
export { ProjectSwitcher } from './project-switcher';
export { RelativeTime, formatRelative } from './relative-time';
export { SecretInput, type SecretInputProps } from './secret-input';
export { PageHeader, Section, type SectionProps } from './section';
export { Segmented, type SegmentedOption, type SegmentedProps } from './segmented';
export { Select, type SelectOption, type SelectProps } from './select';
export { SERVICE_CARD, ServiceCard, type ServiceCardProps } from './service-card';
export { ServiceIcon, hasServiceIcon, serviceIconKey, type ServiceIconProps } from './service-icon';
export { SetupChecklist, defaultSetupSteps, type SetupChecklistProps, type SetupProgress, type SetupStep } from './setup-checklist';
export { Skeleton, SkeletonRows } from './skeleton';
export { StatusBadge, StatusDot, StatusPill, statusSpec, type StatusBadgeProps, type StatusDotProps, type StatusTone } from './status';
export { Switch, type SwitchProps } from './switch';
export { Tabs, TabsContent, TabsList, TabsTrigger, tabTriggerClasses, useTabIndicator } from './tabs';
export { Tag, type TagProps } from './tag';
export { Toaster, toast, type ToastItem, type ToastKind } from './toast';
export { Tooltip, TooltipProvider } from './tooltip';
export { TopBar } from './top-bar';
export { UserMenu } from './user-menu';
