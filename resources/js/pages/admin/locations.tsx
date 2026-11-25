import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { AdminNav } from '@/components/admin/admin-nav';
import { BranchSummaryTable } from '@/components/admin/branch-summary-table';
import { CardEditModal } from '@/components/admin/card-edit-modal';
import { CardTable } from '@/components/admin/card-table';
import { LOCATION_COLUMNS } from '@/lib/admin-columns';
import comments from '@/routes/admin/comments';
import locations from '@/routes/admin/locations';
import relics from '@/routes/admin/relics';
import type { BranchSummary, CardRecord, ColumnDef, LinkedRelic } from '@/types/admin';

const REQUIRED_RELICS_COLUMN: ColumnDef = {
    key: 'linked_relics',
    label: 'Required relics',
    type: 'text',
    showInTable: true,
    render: (card) => {
        const linked = (card.linked_relics as LinkedRelic[] | undefined) ?? [];
        if (linked.length === 0) return '';
        return (
            <div className="flex flex-wrap gap-x-2 gap-y-1">
                {linked.map((relic) => (
                    <Link
                        key={relic.id}
                        href={relics.show(relic.id).url}
                        className="underline underline-offset-2"
                    >
                        {relic.name}
                    </Link>
                ))}
            </div>
        );
    },
};

// Only the index table gets the extra render-only "Required relics"
// column — CardFields (the edit form) iterates the same columns array
// it's given, and linked_relics isn't a real editable field.
const TABLE_COLUMNS = [...LOCATION_COLUMNS, REQUIRED_RELICS_COLUMN];

export default function AdminLocations({
    cards,
    branchSummary,
}: {
    cards: CardRecord[];
    branchSummary: BranchSummary;
}) {
    const [editing, setEditing] = useState<CardRecord | null | 'new'>(null);

    return (
        <>
            <Head title="Locations" />
            <AdminNav />
            <div className="mx-auto flex max-w-6xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">Locations</h1>
                <BranchSummaryTable summary={branchSummary} />
                <CardTable
                    cards={cards}
                    columns={TABLE_COLUMNS}
                    onEdit={(card) => setEditing(card)}
                    onNew={() => setEditing('new')}
                    hidePrint
                />
            </div>
            <CardEditModal
                open={editing !== null}
                onClose={() => setEditing(null)}
                card={editing === 'new' ? null : editing}
                columns={LOCATION_COLUMNS}
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
