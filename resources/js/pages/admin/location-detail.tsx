import { Head, router } from '@inertiajs/react';
import { AdminNav } from '@/components/admin/admin-nav';
import { BranchSummaryTable } from '@/components/admin/branch-summary-table';
import { CardFields } from '@/components/admin/card-fields';
import { LOCATION_COLUMNS } from '@/lib/admin-columns';
import comments from '@/routes/admin/comments';
import locations from '@/routes/admin/locations';
import type { BranchSummary, CardRecord } from '@/types/admin';

export default function AdminLocationDetail({
    card,
    branchSummary,
}: {
    card: CardRecord;
    branchSummary: BranchSummary;
}) {
    return (
        <>
            <Head title={card.name} />
            <AdminNav />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">{card.name}</h1>
                <BranchSummaryTable summary={branchSummary} />
                <CardFields
                    card={card}
                    columns={LOCATION_COLUMNS}
                    morphType="location"
                    updateUrl={(id) => locations.update(id).url}
                    destroyUrl={(id) => locations.destroy(id).url}
                    commentStoreUrl={comments.store().url}
                    commentResolveUrl={(id) => comments.resolve(id).url}
                    onDeleted={() => router.visit(locations.index().url)}
                />
            </div>
        </>
    );
}
