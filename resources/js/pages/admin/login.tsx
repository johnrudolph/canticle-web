import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import login from '@/routes/login';

export default function AdminLogin() {
    const form = useForm({ email: '', password: '' });

    return (
        <>
            <Head title="Admin login" />
            <div className="mx-auto flex max-w-sm flex-col gap-6 p-4 py-24">
                <Card>
                    <CardHeader>
                        <CardTitle>Canticle card editor</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="flex flex-col gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(login.store().url);
                            }}
                        >
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    autoFocus
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                />
                                {form.errors.email && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.email}
                                    </p>
                                )}
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={form.data.password}
                                    onChange={(e) =>
                                        form.setData('password', e.target.value)
                                    }
                                />
                                {form.errors.password && (
                                    <p className="text-sm text-destructive">
                                        {form.errors.password}
                                    </p>
                                )}
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                Log in
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
