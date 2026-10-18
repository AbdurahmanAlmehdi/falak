import { Button } from '@/components/falak';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ArrowUpCircle } from 'lucide-react';
import { falakUpdateOf } from '../types';

/** Top bar, for the install's operators only: "Update available vX" once the release check found a newer Falak. */
export function UpdateHeaderItem() {
    const { props } = usePage<SharedData>();
    const update = falakUpdateOf(props);

    if (!update?.update_available || !update.latest) return null;

    return (
        <Button asChild variant="ghost" size="sm" className="text-primary">
            <Link href={update.settings_url} aria-label={`Falak ${update.latest} is available`}>
                <ArrowUpCircle aria-hidden />
                <span className="hidden sm:inline">Update available {update.latest}</span>
            </Link>
        </Button>
    );
}
