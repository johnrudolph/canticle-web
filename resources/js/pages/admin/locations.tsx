import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { AdminNav } from '@/components/admin/admin-nav';
import { CardEditModal } from '@/components/admin/card-edit-modal';
import { CardTable } from '@/components/admin/card-table';
import comments from '@/routes/admin/comments';
import locations from '@/routes/admin/locations';
import type { CardRecord, ColumnDef } from '@/types/admin';

const COLUMNS: ColumnDef[] = [
    { key: 'lore', label: 'Lore', type: 'textarea' },
    { key: 'rule', label: 'Rule', type: 'textarea' },
    { key: 'cost_1', label: 'Cost 1', type: 'text' },
    { key: 'cost_2', label: 'Cost 2', type: 'text' },
    { key: 'cost_3', label: 'Cost 3', type: 'text' },
    { key: 'cost_4', label: 'Cost 4', type: 'text' },
    { key: 'produces', label: 'Produces', type: 'text', filterable: true },
    { key: 'produces_emoji', label: 'Produces emoji', type: 'text' },
    { key: 'relics', label: 'Relics', type: 'text' },
    { key: 'relic_names', label: 'Relic names', type: 'textarea' },
    { key: 'is_starter', label: 'Is starter', type: 'boolean', filterable: true },
    { key: 'has_shard', label: 'Has shard', type: 'boolean' },
    { key: 'has_tree', label: 'Has tree', type: 'boolean' },
    { key: 'has_back_text', label: 'Has back text', type: 'boolean' },
    { key: 'has_relics', label: 'Has relics', type: 'boolean' },
    { key: 'has_specific_relics', label: 'Has specific relics', type: 'boolean' },
    { key: 'produces_anything', label: 'Produces anything', type: 'boolean' },
    { key: 'has_cost', label: 'Has cost', type: 'boolean' },
    { key: 'cost_emoji', label: 'Cost emoji', type: 'text' },
];

export default function AdminLocations({ cards }: { cards: CardRecord[] }) {
    const [editing, setEditing] = useState<CardRecord | null | 'new'>(null);

    return (
        <>
            <Head title="Locations" />
            <AdminNav />
            <div className="mx-auto flex max-w-6xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">Locations</h1>
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
                morphType="location"
                storeUrl={locations.store().url}
                updateUrl={(id) => locations.update(id).url}
                destroyUrl={(id) => locations.destroy(id).url}
                commentStoreUrl={comments.store().url}
                commentResolveUrl={(id) => comments.resolve(id).url}
            />
        </>
    );
}
