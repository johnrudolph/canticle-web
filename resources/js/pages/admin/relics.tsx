import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { AdminNav } from '@/components/admin/admin-nav';
import { CardEditModal } from '@/components/admin/card-edit-modal';
import { CardTable } from '@/components/admin/card-table';
import comments from '@/routes/admin/comments';
import relics from '@/routes/admin/relics';
import type { CardRecord, ColumnDef } from '@/types/admin';

const COLUMNS: ColumnDef[] = [
    { key: 'location', label: 'Location', type: 'text', filterable: true },
    { key: 'slot', label: 'Slot', type: 'text', filterable: true },
    { key: 'type', label: 'Type', type: 'text', filterable: true },
    { key: 'symbol', label: 'Symbol', type: 'text', filterable: true },
    { key: 'effect', label: 'Effect', type: 'textarea' },
    { key: 'slot_emoji', label: 'Slot emoji', type: 'text' },
    { key: 'is_action', label: 'Is action', type: 'boolean' },
    { key: 'is_passive', label: 'Is passive', type: 'boolean' },
    { key: 'is_event', label: 'Is event', type: 'boolean' },
];

export default function AdminRelics({ cards }: { cards: CardRecord[] }) {
    const [editing, setEditing] = useState<CardRecord | null | 'new'>(null);

    return (
        <>
            <Head title="Relics" />
            <AdminNav />
            <div className="mx-auto flex max-w-6xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">Relics</h1>
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
