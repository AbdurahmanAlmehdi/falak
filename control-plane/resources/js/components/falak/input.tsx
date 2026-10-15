import { cn } from '@/lib/utils';
import { forwardRef, type InputHTMLAttributes, type ReactNode, type TextareaHTMLAttributes } from 'react';
import { useFieldControl } from './field';

export const controlClasses = [
    'w-full rounded-md border border-border bg-surface-2 text-sm text-fg placeholder:text-fg-faint',
    'transition-[border-color,background-color] duration-150 ease-out',
    'hover:border-border-strong focus-visible:border-border-strong focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
    'disabled:cursor-not-allowed disabled:opacity-60',
    'aria-invalid:border-danger aria-invalid:focus-visible:outline-danger',
].join(' ');

export interface InputProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix'> {
    /** Icon rendered inside the control on the left; text (e.g. `/status/`) is an addon attached to its left edge. */
    prefix?: ReactNode;
    suffix?: ReactNode;
    mono?: boolean;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(({ className, prefix, suffix, mono, type = 'text', ...props }, ref) => {
    const field = useFieldControl(props);
    // Text has no fixed width: an addon beside the input instead of an overlay over its padding.
    const addon = typeof prefix === 'string' ? prefix : null;
    if (addon !== null) prefix = undefined;
    const input = (
        <input
            ref={ref}
            type={type}
            {...props}
            {...field}
            className={cn(
                controlClasses,
                'h-8 px-2.5',
                mono && 'font-mono text-xs',
                prefix && 'pl-8',
                suffix && 'pr-8',
                addon !== null && 'rounded-l-none',
                'file:text-fg file:mr-2 file:border-0 file:bg-transparent file:text-xs file:font-medium',
                className,
            )}
        />
    );

    if (addon !== null) {
        return (
            <div className="flex w-full">
                <span
                    className={cn(
                        'border-border bg-surface-1 text-fg-faint flex shrink-0 items-center rounded-l-md border border-r-0 px-2.5 text-sm',
                        mono && 'font-mono text-xs',
                    )}
                >
                    {addon}
                </span>
                {suffix ? (
                    <div className="relative w-full">
                        {input}
                        <span className="text-fg-faint absolute inset-y-0 right-2 flex items-center [&_svg]:size-4">{suffix}</span>
                    </div>
                ) : (
                    input
                )}
            </div>
        );
    }

    if (!prefix && !suffix) return input;

    return (
        <div className="relative w-full">
            {prefix && (
                <span className="text-fg-faint pointer-events-none absolute inset-y-0 left-2.5 flex items-center [&_svg]:size-4">{prefix}</span>
            )}
            {input}
            {suffix && <span className="text-fg-faint absolute inset-y-0 right-2 flex items-center [&_svg]:size-4">{suffix}</span>}
        </div>
    );
});
Input.displayName = 'Input';

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
    mono?: boolean;
}

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(({ className, mono, rows = 4, ...props }, ref) => {
    const field = useFieldControl(props);

    return (
        <textarea
            ref={ref}
            rows={rows}
            {...props}
            {...field}
            className={cn(controlClasses, 'min-h-16 px-2.5 py-2', mono && 'font-mono text-xs leading-5', className)}
        />
    );
});
Textarea.displayName = 'Textarea';
