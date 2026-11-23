import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { AdminNav } from '@/components/admin/admin-nav';
import { CardEditModal } from '@/components/admin/card-edit-modal';
import { CardTable } from '@/components/admin/card-table';
import { relicColumns } from '@/lib/admin-columns';
import comments from '@/routes/admin/comments';
import relics from '@/routes/admin/relics';
import type { CardRecord } from '@/types/admin';

export default function AdminRelics({
    cards,
    locationNames,
}: {
    cards: CardRecord[];
    locationNames: string[];
}) {
    const [editing, setEditing] = useState<CardRecord | null | 'new'>(null);
    const columns = relicColumns(locationNames);

    return (
        <>
            <Head title="Relics" />
            <AdminNav />
            <div className="mx-auto flex max-w-6xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">Relics</h1>
                <CardTable
                    cards={cards}
                    columns={columns}
                    onEdit={(card) => setEditing(card)}
                    onNew={() => setEditing('new')}
                />
            </div>
            <CardEditModal
                open={editing !== null}
                onClose={() => setEditing(null)}
                card={editing === 'new' ? null : editing}
                columns={columns}
                morphType="relic"
                storeUrl={relics.store().url}
                updateUrl={(id) => relics.update(id).url}
                destroyUrl={(id) => relics.destroy(id).url}
                commentStoreUrl={comments.store().url}
                commentResolveUrl={(id) => comments.resolve(id).url}
            />
        </>
    );
}
