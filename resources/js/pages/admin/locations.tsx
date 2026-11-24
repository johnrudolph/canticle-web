import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { AdminNav } from '@/components/admin/admin-nav';
import { BranchSummaryTable } from '@/components/admin/branch-summary-table';
import { CardEditModal } from '@/components/admin/card-edit-modal';
import { CardTable } from '@/components/admin/card-table';
import { LOCATION_COLUMNS } from '@/lib/admin-columns';
import comments from '@/routes/admin/comments';
import locations from '@/routes/admin/locations';
import type { BranchSummary, CardRecord } from '@/types/admin';

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
                    columns={LOCATION_COLUMNS}
                    onEdit={(card) => setEditing(card)}
                    onNew={() => setEditing('new')}
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
