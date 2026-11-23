import { Head, router } from '@inertiajs/react';
import { AdminNav } from '@/components/admin/admin-nav';
import { CardFields } from '@/components/admin/card-fields';
import { GOLEM_CARD_COLUMNS } from '@/lib/admin-columns';
import comments from '@/routes/admin/comments';
import golemCards from '@/routes/admin/golem-cards';
import type { CardRecord } from '@/types/admin';

export default function AdminGolemCardDetail({ card }: { card: CardRecord }) {
    return (
        <>
            <Head title={card.name} />
            <AdminNav />
            <div className="mx-auto flex max-w-2xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">{card.name}</h1>
                <CardFields
                    card={card}
                    columns={GOLEM_CARD_COLUMNS}
                    morphType="golem_card"
                    updateUrl={(id) => golemCards.update(id).url}
                    destroyUrl={(id) => golemCards.destroy(id).url}
                    commentStoreUrl={comments.store().url}
                    commentResolveUrl={(id) => comments.resolve(id).url}
                    onDeleted={() => router.visit(golemCards.index().url)}
                />
            </div>
        </>
    );
}
