import { Head, router, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import games from '@/routes/games';

interface OpenGame {
    code: string;
    wanderer_count: number;
    golem_enabled: boolean;
    roles_filled: number;
    roles_total: number;
}

export default function Welcome({ games: openGames }: { games: OpenGame[] }) {
    const createForm = useForm({ wanderer_count: 2, golem_enabled: false });
    const joinForm = useForm({ code: '' });

    return (
        <>
            <Head title="Canticle" />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 p-4 py-12">
                <h1 className="text-center text-2xl font-semibold">Canticle</h1>

                <Card>
                    <CardHeader>
                        <CardTitle>Start a game</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="flex flex-col gap-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                createForm.post(games.store().url);
                            }}
                        >
                            <div className="flex items-end gap-3">
                                <div>
                                    <Label htmlFor="wanderer_count">
                                        Wanderers
                                    </Label>
                                    <select
                                        id="wanderer_count"
                                        className="flex h-9 w-24 rounded-md border border-input bg-transparent px-3 text-sm"
                                        value={createForm.data.wanderer_count}
                                        onChange={(e) =>
                                            createForm.setData(
                                                'wanderer_count',
                                                Number(e.target.value),
                                            )
                                        }
                                    >
                                        {[2, 3].map((n) => (
                                            <option key={n} value={n}>
                                                {n}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <Button
                                    type="submit"
                                    disabled={createForm.processing}
                                >
                                    Create game
                                </Button>
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="golem_enabled"
                                    checked={createForm.data.golem_enabled}
                                    onCheckedChange={(checked) =>
                                        createForm.setData(
                                            'golem_enabled',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label
                                    htmlFor="golem_enabled"
                                    className="font-normal"
                                >
                                    Human-controlled Golem
                                </Label>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Roles (Emperor, 2-3 Wanderers, optional
                                Golem) are assigned in the lobby, separately
                                from however many people actually show up —
                                solo play claims every role.
                            </p>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Join by code</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="flex items-end gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                joinForm.post(
                                    games.join(joinForm.data.code).url,
                                );
                            }}
                        >
                            <div>
                                <Label htmlFor="code">Room code</Label>
                                <Input
                                    id="code"
                                    value={joinForm.data.code}
                                    onChange={(e) =>
                                        joinForm.setData(
                                            'code',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    className="w-32 font-mono uppercase"
                                    maxLength={6}
                                />
                            </div>
                            <Button
                                type="submit"
                                disabled={
                                    joinForm.processing ||
                                    joinForm.data.code.length === 0
                                }
                            >
                                Join
                            </Button>
                        </form>
                        {joinForm.errors.code && (
                            <p className="mt-2 text-sm text-destructive">
                                {joinForm.errors.code}
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Join a game</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {openGames.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No open games right now.
                            </p>
                        )}
                        {openGames.map((g) => (
                            <button
                                key={g.code}
                                type="button"
                                onClick={() =>
                                    router.get(games.show(g.code).url)
                                }
                                className="flex w-full items-center justify-between rounded-md border p-3 text-left text-sm hover:bg-accent"
                            >
                                <span className="font-mono font-semibold">
                                    {g.code}
                                </span>
                                <span className="text-muted-foreground">
                                    {g.roles_filled}/{g.roles_total} roles
                                    claimed
                                </span>
                            </button>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
