import { Head, Link } from '@inertiajs/react';
import { AdminNav } from '@/components/admin/admin-nav';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import glyphs from '@/routes/admin/glyphs';
import golemCards from '@/routes/admin/golem-cards';
import locations from '@/routes/admin/locations';
import relics from '@/routes/admin/relics';
import rulebook from '@/routes/admin/rulebook';

const SECTIONS = [
    { href: locations.index().url, label: 'Locations' },
    { href: relics.index().url, label: 'Relics' },
    { href: glyphs.index().url, label: 'Glyphs' },
    { href: golemCards.index().url, label: 'Golem Cards' },
    { href: rulebook.index().url, label: 'Rulebook' },
];

export default function AdminDashboard() {
    return (
        <>
            <Head title="Card editor" />
            <AdminNav />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">Canticle card editor</h1>
                <div className="grid grid-cols-2 gap-4">
                    {SECTIONS.map((section) => (
                        <Link key={section.href} href={section.href}>
                            <Card className="transition-colors hover:bg-accent">
                                <CardHeader>
                                    <CardTitle>{section.label}</CardTitle>
                                </CardHeader>
                                <CardContent />
                            </Card>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}
