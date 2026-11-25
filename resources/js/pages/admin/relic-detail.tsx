import { Head, router } from '@inertiajs/react';
import { AdminNav } from '@/components/admin/admin-nav';
import { CardFields } from '@/components/admin/card-fields';
import { RelicSlotTypeMatrix } from '@/components/admin/relic-slot-type-matrix';
import { relicColumns } from '@/lib/admin-columns';
import comments from '@/routes/admin/comments';
import relics from '@/routes/admin/relics';
import type { CardRecord, RelicSummary } from '@/types/admin';

export default function AdminRelicDetail({
    card,
    locationNames,
    relicSummary,
    relicSymbols,
}: {
    card: CardRecord;
    locationNames: string[];
    relicSummary: RelicSummary;
    relicSymbols: string[];
}) {
    return (
        <>
            <Head title={card.name} />
            <AdminNav />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">{card.name}</h1>
                <RelicSlotTypeMatrix summary={relicSummary} />
                <CardFields
                    card={card}
                    columns={relicColumns(locationNames, relicSymbols)}
                    morphType="relic"
                    updateUrl={(id) => relics.update(id).url}
                    destroyUrl={(id) => relics.destroy(id).url}
                    commentStoreUrl={comments.store().url}
                    commentResolveUrl={(id) => comments.resolve(id).url}
                    onDeleted={() => router.visit(relics.index().url)}
                />
            </div>
        </>
    );
}
