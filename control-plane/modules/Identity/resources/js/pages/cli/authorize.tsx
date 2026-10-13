import { Button } from '@/components/falak/button';
import { Callout } from '@/components/falak/callout';
import { Field } from '@/components/falak/field';
import { Input } from '@/components/falak/input';
import { RelativeTime } from '@/components/falak/relative-time';
import { Select } from '@/components/falak/select';
import AuthLayout, { AuthLink } from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface AuthorizeProps {
    code: string | null;
    answered: 'approved' | 'denied' | null;
    login: { client_name: string; ip_address: string | null; requested_at: string; expires_at: string } | null;
    organizations: { id: string; name: string }[];
    currentOrganizationId: string | null;
}

/** The browser side of `falak login`: type or confirm the code shown in the terminal, then approve or deny. */
export default function CliAuthorize({ code, answered, login, organizations, currentOrganizationId }: AuthorizeProps) {
    const lookup = useForm({ code: '' });
    const approve = useForm({ organization_id: currentOrganizationId ?? organizations[0]?.id ?? '' });
    const deny = useForm({});
    const codeError = (approve.errors as Record<string, string | undefined>).code ?? approve.errors.organization_id;

    if (answered) {
        return (
            <AuthLayout
                title={answered === 'approved' ? 'Falak CLI is signed in' : 'Login denied'}
                description={
                    answered === 'approved'
                        ? 'Go back to your terminal: it has its token. You can revoke it any time in Settings → API tokens.'
                        : 'The terminal that asked was refused. If that was you, run `falak login` again.'
                }
                footer={<AuthLink href={'/settings/api-tokens'}>API tokens</AuthLink>}
            >
                <Head title="Falak CLI" />
            </AuthLayout>
        );
    }

    if (!login) {
        const submit: FormEventHandler = (event) => {
            event.preventDefault();
            lookup.post(route('cli.authorize.lookup'));
        };
        return (
            <AuthLayout
                title="Sign in the Falak CLI"
                description={
                    code
                        ? 'This code is invalid, has expired or was already answered. Run `falak login` again and enter the new code.'
                        : 'Enter the code `falak login` shows in your terminal.'
                }
            >
                <Head title="Falak CLI" />
                <form className="grid gap-4" onSubmit={submit}>
                    <Field label="Code" error={lookup.errors.code}>
                        <Input
                            mono
                            autoFocus
                            autoComplete="off"
                            placeholder="WDJB-MJHT"
                            value={lookup.data.code}
                            onChange={(event) => lookup.setData('code', event.target.value.toUpperCase())}
                        />
                    </Field>
                    <Button variant="primary" type="submit" className="w-full" loading={lookup.processing}>
                        Continue
                    </Button>
                </form>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout
            title="Sign in the Falak CLI?"
            description={
                <>
                    <span className="text-fg font-medium">{login.client_name}</span>
                    {login.ip_address ? <> from {login.ip_address}</> : null} asked <RelativeTime value={login.requested_at} /> for access to your
                    account.
                </>
            }
        >
            <Head title="Falak CLI" />
            <Callout tone="warning">
                Approve only if you just ran <code className="font-mono">falak login</code> and your terminal shows{' '}
                <span className="font-mono font-medium">{code}</span>. Anyone holding the token can act as you.
            </Callout>
            {codeError && (
                <p role="alert" className="text-danger text-sm">
                    {codeError}
                </p>
            )}
            {organizations.length > 1 && (
                <Field label="Organization" hint="The token works in this organization only.">
                    <Select
                        value={approve.data.organization_id}
                        onValueChange={(value) => approve.setData('organization_id', value)}
                        options={organizations.map((o) => ({ value: o.id, label: o.name }))}
                    />
                </Field>
            )}
            <p className="text-fg-muted text-sm">
                The token carries your role's permissions and expires after 90 days without use (a year at most). This request expires{' '}
                <RelativeTime value={login.expires_at} />.
            </p>
            <div className="grid gap-2">
                <Button
                    variant="primary"
                    className="w-full"
                    loading={approve.processing}
                    disabled={!approve.data.organization_id || deny.processing}
                    onClick={() => approve.post(route('cli.authorize.approve', code ?? ''))}
                >
                    Approve
                </Button>
                <Button
                    variant="ghost"
                    className="w-full"
                    loading={deny.processing}
                    disabled={approve.processing}
                    onClick={() => deny.post(route('cli.authorize.deny', code ?? ''))}
                >
                    Deny
                </Button>
            </div>
        </AuthLayout>
    );
}
