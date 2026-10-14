/** Path rate limits at the edge (docs/plans/network/protection.md). */

export const WINDOWS: { value: string; label: string }[] = [
    { value: '1', label: 'second' },
    { value: '10', label: '10 seconds' },
    { value: '60', label: 'minute' },
    { value: '600', label: '10 minutes' },
    { value: '3600', label: 'hour' },
    { value: '86400', label: 'day' },
];

/** 10, 60 → "10 requests / minute per IP"; windows not in the list are spelled in seconds. */
export function describeLimit(events: number, windowS: number): string {
    const window = WINDOWS.find((w) => Number(w.value) === windowS)?.label ?? `${windowS} seconds`;

    return `${events} ${events === 1 ? 'request' : 'requests'} / ${window} per IP`;
}
