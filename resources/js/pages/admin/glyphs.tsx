import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { AdminNav } from '@/components/admin/admin-nav';
import { CardEditModal } from '@/components/admin/card-edit-modal';
import { CardTable } from '@/components/admin/card-table';
import { GLYPH_COLUMNS } from '@/lib/admin-columns';
import comments from '@/routes/admin/comments';
import glyphs from '@/routes/admin/glyphs';
import type { CardRecord } from '@/types/admin';

const COLUMNS = GLYPH_COLUMNS;

export default function AdminGlyphs({ cards }: { cards: CardRecord[] }) {
    const [editing, setEditing] = useState<CardRecord | null | 'new'>(null);

    return (
        <>
            <Head title="Glyphs" />
            <AdminNav />
            <div className="mx-auto flex max-w-6xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">Glyphs</h1>
                <CardTable
                    cards={cards}
                    columns={COLUMNS}
                    onEdit={(card) => setEditing(card)}
                    onNew={() => setEditing('new')}
                />
            </div>
            <CardEditModal
                open={editing !== null}
                onClose={() => setEditing(null)}
                card={editing === 'new' ? null : editing}
                columns={COLUMNS}
                morphType="glyph"
                storeUrl={glyphs.store().url}
                updateUrl={(id) => glyphs.update(id).url}
                destroyUrl={(id) => glyphs.destroy(id).url}
                commentStoreUrl={comments.store().url}
                commentResolveUrl={(id) => comments.resolve(id).url}
            />
        </>
    );
}
