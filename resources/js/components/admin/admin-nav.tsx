import { Link, router } from '@inertiajs/react';
import { destroy as logout } from '@/actions/Laravel/Fortify/Http/Controllers/AuthenticatedSessionController';
import { Button } from '@/components/ui/button';
import glyphs from '@/routes/admin/glyphs';
import golemCards from '@/routes/admin/golem-cards';
import locations from '@/routes/admin/locations';
import relics from '@/routes/admin/relics';
import rulebook from '@/routes/admin/rulebook';

const LINKS = [
    { href: locations.index().url, label: 'Locations' },
    { href: relics.index().url, label: 'Relics' },
    { href: glyphs.index().url, label: 'Glyphs' },
    { href: golemCards.index().url, label: 'Golem Cards' },
    { href: rulebook.index().url, label: 'Rulebook' },
];

export function AdminNav() {
    return (
        <nav className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 pt-4">
            <div className="flex gap-3 text-sm">
                {LINKS.map((link) => (
                    <Link
                        key={link.href}
                        href={link.href}
                        className="text-muted-foreground hover:text-foreground"
                    >
                        {link.label}
                    </Link>
                ))}
            </div>
            <Button
                size="sm"
                variant="ghost"
                onClick={() => router.post(logout().url)}
            >
                Log out
            </Button>
        </nav>
    );
}
