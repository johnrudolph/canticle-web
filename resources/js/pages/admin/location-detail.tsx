import { Head, Link, router } from '@inertiajs/react';
import { AdminNav } from '@/components/admin/admin-nav';
import { BranchSummaryTable } from '@/components/admin/branch-summary-table';
import { CardFields } from '@/components/admin/card-fields';
import { locationColumns } from '@/lib/admin-columns';
import comments from '@/routes/admin/comments';
import locations from '@/routes/admin/locations';
import relics from '@/routes/admin/relics';
import type { BranchSummary, CardRecord, LinkedRelic } from '@/types/admin';

export default function AdminLocationDetail({
    card,
    branchSummary,
    linkedRelics,
    branches,
}: {
    card: CardRecord;
    branchSummary: BranchSummary;
    linkedRelics: LinkedRelic[];
    branches: string[];
}) {
    return (
        <>
            <Head title={card.name} />
            <AdminNav />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">{card.name}</h1>
                <BranchSummaryTable summary={branchSummary} />
                {linkedRelics.length > 0 && (
                    <div className="flex flex-col gap-2 rounded-md border p-4">
                        <h2 className="text-sm font-medium text-muted-foreground">
                            Relics locked to this Location
                        </h2>
                        <ul className="flex flex-col gap-1">
                            {linkedRelics.map((relic) => (
                                <li key={relic.id}>
                                    <Link
                                        href={relics.show(relic.id).url}
                                        className="text-sm underline underline-offset-2"
                                    >
                                        {relic.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                <CardFields
                    card={card}
                    columns={locationColumns(branches)}
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
