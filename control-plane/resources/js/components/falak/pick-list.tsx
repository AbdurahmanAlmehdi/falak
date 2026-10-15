import { cn } from '@/lib/utils';
import { Command } from 'cmdk';
import { Search } from 'lucide-react';
import { createContext, useContext, useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';

export interface PickListProps {
    /** Accessible name of the list (and of its search box). */
    label: string;
    placeholder: string;
    /** Controlled search, for lists the caller filters (server search); uncontrolled otherwise. */
    search?: string;
    onSearchChange?: (value: string) => void;
    /** false when the caller already filtered the items. */
    filter?: boolean;
    /** Shown when nothing matches. */
    empty?: ReactNode;
    /** Between the search box and the list (filters, a connection picker). */
    toolbar?: ReactNode;
    autoFocus?: boolean;
    className?: string;
    children: ReactNode;
}

/**
 * Keyboard-first option list (cmdk): type to filter, ↑/↓ Home/End move a highlight that slides between options, Enter
 * picks. Combobox + listbox semantics with aria-activedescendant come from cmdk. Used by every pick step of the canvas
 * Create picker so they all behave the same.
 */
export function PickList({
    label,
    placeholder,
    search,
    onSearchChange,
    filter = true,
    empty = 'Nothing matches.',
    toolbar,
    autoFocus = true,
    className,
    children,
}: PickListProps) {
    const list = useRef<HTMLDivElement>(null);
    const highlight = useRef<HTMLDivElement>(null);
    const [value, setValue] = useState('');

    // Follow the selected option (keyboard, hover, filtering) with one plate moved by a transform.
    useLayoutEffect(() => {
        const frame = requestAnimationFrame(() => {
            const plate = highlight.current;
            const item = list.current?.querySelector<HTMLElement>('[cmdk-item][data-selected="true"]');
            if (!plate?.parentElement) return;
            if (!item) {
                plate.style.opacity = '0';

                return;
            }
            const origin = plate.parentElement.getBoundingClientRect();
            const box = item.getBoundingClientRect();
            const first = plate.style.opacity !== '1';
            // Appearing: jump into place, slide only between options.
            if (first) plate.style.transition = 'none';
            plate.style.width = `${box.width}px`;
            plate.style.height = `${box.height}px`;
            plate.style.transform = `translate(${box.left - origin.left}px, ${box.top - origin.top}px)`;
            plate.style.opacity = '1';
            if (first) {
                void plate.offsetWidth;
                plate.style.removeProperty('transition');
            }
        });

        return () => cancelAnimationFrame(frame);
    });

    return (
        <Command label={label} loop shouldFilter={filter} value={value} onValueChange={setValue} className={cn('flex min-h-0 flex-col', className)}>
            <div className="border-border flex items-center gap-2 border-b px-3">
                <Search className="text-fg-faint size-4 shrink-0" aria-hidden />
                <Command.Input
                    autoFocus={autoFocus}
                    value={search}
                    onValueChange={onSearchChange}
                    placeholder={placeholder}
                    aria-label={label}
                    className="text-fg placeholder:text-fg-faint h-11 w-full bg-transparent text-sm outline-none"
                />
            </div>
            {toolbar}
            <Command.List ref={list} className="min-h-0 overflow-y-auto overscroll-contain p-1.5 [&>[cmdk-list-sizer]]:relative">
                <div ref={highlight} className="falak-pick-highlight" aria-hidden />
                <Command.Empty className="text-fg-faint px-3 py-6 text-center text-sm">{empty}</Command.Empty>
                {children}
            </Command.List>
        </Command>
    );
}

export interface PickItemProps {
    /** Search text (title + description); also the option's identity, so keep it unique. */
    value: string;
    onSelect: () => void;
    disabled?: boolean;
    icon?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    /** Right side: a tag, a lock, a chevron. */
    trailing?: ReactNode;
    'data-testid'?: string;
}

export function PickItem({ value, onSelect, disabled, icon, title, description, trailing, ...props }: PickItemProps) {
    return (
        <Command.Item
            value={value}
            disabled={disabled}
            onSelect={onSelect}
            data-testid={props['data-testid']}
            className="text-fg-muted data-[selected=true]:text-fg relative flex cursor-pointer items-center gap-3 rounded-md px-2.5 py-2 data-[disabled=true]:cursor-default data-[disabled=true]:opacity-50"
        >
            {icon && (
                <span className="border-border bg-surface-2 text-fg-muted flex size-8 shrink-0 items-center justify-center rounded-md border [&_svg]:size-4">
                    {icon}
                </span>
            )}
            <span className="grid min-w-0 flex-1">
                <span className="text-fg truncate text-sm font-medium">{title}</span>
                {description && <span className="text-fg-faint truncate text-xs">{description}</span>}
            </span>
            {trailing}
        </Command.Item>
    );
}

const StepBackContext = createContext<((back: (() => void) | null) => void) | null>(null);

/** Provided by a multi-step dialog: steps register how to go back from where they are. */
export const StepBackProvider = StepBackContext.Provider;

/**
 * Inside a multi-step dialog (the canvas Create picker): while `active`, Esc and the dialog's Back button call `back`
 * (e.g. from a template's form to the gallery) instead of leaving the step.
 */
export function useStepBack(active: boolean, back: () => void): void {
    const register = useContext(StepBackContext);
    const latest = useRef(back);
    latest.current = back;

    useEffect(() => {
        if (!register || !active) return;
        register(() => latest.current());

        return () => register(null);
    }, [register, active]);
}
