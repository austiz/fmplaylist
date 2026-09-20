import { Form, Head } from '@inertiajs/react';
import { FieldError } from '@/components/field-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    passwordRules: string;
};

/**
 * The first operator account, and only the first.
 *
 * This is the page the old public /register used to be, minus the "public":
 * the route behind it 404s the moment one account exists, so it can only ever
 * run once. Everyone after this is added from System -> DJs.
 *
 * The action is written out rather than imported from the generated route
 * helpers because those helpers are built from the route table, and a route
 * that disappears after first use is a strange thing to hold a typed reference
 * to.
 */
export default function Setup({ passwordRules }: Props) {
    return (
        <>
            <Head title="Set up" />
            <Form
                action="/setup"
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                type="text"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="name"
                                name="name"
                                placeholder="On-air name"
                            />
                            <FieldError
                                message={errors.name}
                                className="mt-2"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                required
                                tabIndex={2}
                                autoComplete="email"
                                name="email"
                                placeholder="email@example.com"
                            />
                            <FieldError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                required
                                tabIndex={3}
                                autoComplete="new-password"
                                name="password"
                                placeholder="Password"
                                passwordrules={passwordRules}
                            />
                            <FieldError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                Confirm password
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                required
                                tabIndex={4}
                                autoComplete="new-password"
                                name="password_confirmation"
                                placeholder="Confirm password"
                                passwordrules={passwordRules}
                            />
                            <FieldError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            className="mt-2 w-full"
                            tabIndex={5}
                            data-test="setup-button"
                        >
                            {processing && <Spinner />}
                            Create the first account
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

Setup.layout = {
    title: 'Set up this station',
    description:
        'This creates the first DJ account. Afterwards this page disappears and new DJs are added from the admin.',
};
