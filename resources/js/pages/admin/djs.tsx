import { router, useForm, usePage } from '@inertiajs/react';
import { KeyRound, Trash2, Users } from 'lucide-react';
import { useState } from 'react';

import { useConfirm } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { FieldError } from '@/components/field-error';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { AdminLayout } from '@/layouts/admin-layout';

interface Dj {
    id: number;
    name: string;
    email: string;
    created_at: string | null;
}

interface Props {
    djs: Dj[];
    passwordRules: string;
}

/**
 * Operator accounts.
 *
 * There is no public sign-up and no role column: an account here can run a
 * transmitter, so the only way to get one is for someone who already has one to
 * make it on this page.
 */
export default function Djs({ djs, passwordRules }: Props) {
    const confirm = useConfirm();
    const { auth } = usePage<{ auth: { user: { id: number } | null } }>().props;

    const [resetting, setResetting] = useState<Dj | null>(null);

    const create = useForm({ name: '', email: '', password: '' });
    const reset = useForm({ password: '' });

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        create.post('/admin/djs', {
            preserveScroll: true,
            onSuccess: () => create.reset(),
        });
    };

    const submitReset = (e: React.FormEvent) => {
        e.preventDefault();

        if (!resetting) {
            return;
        }

        reset.post(`/admin/djs/${resetting.id}/password`, {
            preserveScroll: true,
            onSuccess: () => {
                reset.reset();
                setResetting(null);
            },
        });
    };

    const remove = async (dj: Dj) => {
        const ok = await confirm({
            title: `Remove ${dj.name}?`,
            description:
                'They lose access immediately. Their request history stays on the station.',
            confirmLabel: 'Remove',
            variant: 'destructive',
        });

        if (ok) {
            router.delete(`/admin/djs/${dj.id}`, { preserveScroll: true });
        }
    };

    return (
        <AdminLayout
            title="DJs"
            description="Everyone who can log in and run the transmitter."
        >
            <div className="max-w-3xl space-y-6">
                <Card>
                    <CardContent className="space-y-4">
                        <CardTitle>Operators</CardTitle>

                        {djs.length === 0 ? (
                            <EmptyState
                                icon={<Users />}
                                title="No DJs yet"
                                description="Add one below and give them the password in person."
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead className="hidden sm:table-cell">
                                            Added
                                        </TableHead>
                                        <TableHead className="w-0 text-right">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {djs.map((dj) => {
                                        const isYou = dj.id === auth.user?.id;

                                        return (
                                            <TableRow key={dj.id}>
                                                <TableCell className="max-w-0">
                                                    <p className="flex items-center gap-2 truncate font-medium text-foreground">
                                                        <span className="truncate">
                                                            {dj.name}
                                                        </span>
                                                        {isYou && (
                                                            <Badge variant="brand">
                                                                You
                                                            </Badge>
                                                        )}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {dj.email}
                                                    </p>
                                                </TableCell>
                                                <TableCell className="hidden text-xs whitespace-nowrap text-muted-foreground sm:table-cell">
                                                    {dj.created_at ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={`Set a new password for ${dj.name}`}
                                                            onClick={() =>
                                                                setResetting(dj)
                                                            }
                                                        >
                                                            <KeyRound />
                                                        </Button>
                                                        {/* Removing yourself is refused server-side,
                                                            which is also what keeps the last account
                                                            alive: with nobody else left, the only row
                                                            on the page is your own. */}
                                                        {!isYou && (
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                aria-label={`Remove ${dj.name}`}
                                                                onClick={() =>
                                                                    remove(dj)
                                                                }
                                                            >
                                                                <Trash2 className="text-destructive" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent>
                        <form onSubmit={submitCreate} className="space-y-4">
                            <CardTitle>Add a DJ</CardTitle>
                            <p className="text-sm text-muted-foreground">
                                You set the password here and tell them what it
                                is. They can change it later under Account.
                            </p>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label htmlFor="dj-name">Name</Label>
                                    <Input
                                        id="dj-name"
                                        value={create.data.name}
                                        onChange={(e) =>
                                            create.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="On-air name"
                                        autoComplete="off"
                                        required
                                    />
                                    <FieldError message={create.errors.name} />
                                </div>

                                <div className="space-y-1">
                                    <Label htmlFor="dj-email">Email</Label>
                                    <Input
                                        id="dj-email"
                                        type="email"
                                        value={create.data.email}
                                        onChange={(e) =>
                                            create.setData(
                                                'email',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="dj@example.com"
                                        autoComplete="off"
                                        required
                                    />
                                    <FieldError message={create.errors.email} />
                                </div>
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="dj-password">Password</Label>
                                <PasswordInput
                                    id="dj-password"
                                    value={create.data.password}
                                    onChange={(e) =>
                                        create.setData(
                                            'password',
                                            e.target.value,
                                        )
                                    }
                                    autoComplete="new-password"
                                    placeholder="Password"
                                    passwordrules={passwordRules}
                                    required
                                />
                                <FieldError message={create.errors.password} />
                            </div>

                            <Button type="submit" disabled={create.processing}>
                                {create.processing && <Spinner />}
                                Add DJ
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>

            <Dialog
                open={resetting !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        reset.reset();
                        reset.clearErrors();
                        setResetting(null);
                    }
                }}
            >
                <DialogContent>
                    <form onSubmit={submitReset} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>
                                New password for {resetting?.name}
                            </DialogTitle>
                            <DialogDescription>
                                Any &ldquo;remember me&rdquo; sessions they left
                                open stop working too.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-1">
                            <Label htmlFor="dj-new-password">Password</Label>
                            <PasswordInput
                                id="dj-new-password"
                                value={reset.data.password}
                                onChange={(e) =>
                                    reset.setData('password', e.target.value)
                                }
                                autoComplete="new-password"
                                placeholder="Password"
                                passwordrules={passwordRules}
                                required
                            />
                            <FieldError message={reset.errors.password} />
                        </div>

                        <DialogFooter>
                            <Button type="submit" disabled={reset.processing}>
                                {reset.processing && <Spinner />}
                                Set password
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
